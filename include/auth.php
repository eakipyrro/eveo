<?php
declare(strict_types=1);

// include/auth.php
require_once __DIR__ . '/db.php';   // tuo myös util.php:n sisään
require_once __DIR__ . '/audit.php';

//
// --- Apurit ---
//
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}


function normalize_email(string $email): string {
    $email = trim($email);
    // Useimmat järjestelmät käsittelevät emailia case-insensitiivisesti
    return mb_strtolower($email);
}

function user_agent_truncated(): string {
    // kasvata pituutta skeemassa 512:een (teit jo)
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
}

function current_session_id(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // PHP:n session_id voi olla tyhjä jos ei vielä startattu, varmistetaan
    return (string) session_id();
}

//
// --- Perushaku ---
//
function find_user_by_email(string $email): ?array
{
    $email = normalize_email($email);
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

//
// --- Login attempt -kirjanpito ---
//
function record_login_attempt(string $email, bool $success): void
{
    $email = normalize_email($email);
    $stmt = db()->prepare('INSERT INTO login_attempts(email, ip, success) VALUES (?, ?, ?)');
    $stmt->execute([$email, ip_bin(), $success ? 1 : 0]);
}

function too_many_failures(string $email): bool
{
    $email = normalize_email($email);
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS c
         FROM login_attempts
         WHERE email = ?
           AND success = 0
           AND attempted_at >= (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$email, LOGIN_WINDOW_MIN]);
    $row = $stmt->fetch();
    return ($row && (int) $row['c'] >= LOGIN_MAX_FAILS);
}

//
// --- Oikeudet ---
//
function can(string $perm): bool
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = $_SESSION['role'] ?? 'user';
    static $map = [
        'user'    => ['view_profile'],
        'eveo'    => ['view_profile', 'view_reports'],
        'manager' => ['view_profile', 'view_eveo_tools', 'view_manager_tools', 'view_reports'],
        'admin'   => [
            'view_profile',
            'view_eveo_tools',
            'view_manager_tools',
            'view_admin_tools',
            'view_reports',
            // hienojakoiset
            'manage_users',
            'view_audit',
        ],
    ];
    return in_array($perm, $map[$role] ?? [], true);
}

//
// --- Login/logout ---
//  HUOM: login-auditointi tehdään login.php:ssä (login_success / login_failed).
//        Tässä EI duplikoida niitä, mutta logout auditoidaan täällä.
//
function do_login(string $email, string $password): array
{
    if (session_status() === PHP_SESSION_NONE) session_start();

    $email = normalize_email($email);

    // Throttlaus – liikaa epäonnistumisia
    if (too_many_failures($email)) {
        return ['ok' => false, 'error' => 'locked'];
    }

    $u = find_user_by_email($email);
    if (!$u || !(int)$u['is_active']) {
        record_login_attempt($email, false);
        // pieni viive brute forcen hidastamiseksi
        usleep(150000); // 150 ms
        return ['ok' => false, 'error' => 'invalid'];
    }

    // Salasanatarkistus
    if (!password_verify($password, (string)$u['password_hash'])) {
        record_login_attempt($email, false);
        usleep(150000);
        return ['ok' => false, 'error' => 'invalid'];
    }

    // Ok: kirjataan sessioon
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['role']    = (string)$u['role'];
    $_SESSION['email']   = (string)$u['email'];
    session_regenerate_id(true); // session fixation -suoja

    // Päivitä user-info (IPv4/IPv6 varbinaarina + UA)
    db()->prepare(
        'UPDATE users
            SET last_login_at = NOW(),
                last_login_ip = ?,
                last_login_ua = ?
          WHERE id = ?'
    )->execute([ip_bin(), user_agent_truncated(), (int)$u['id']]);

    record_login_attempt($email, true);

    return [
        'ok'      => true,
        'user_id' => (int)$u['id'],
        'role'    => (string)$u['role'],
        'email'   => (string)$u['email'],
    ];
}

function logout(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Auditointi: logout
    try {
        audit_log('logout', [
            'status'         => 'success',
            'page'           => 'logout',
            'user_id'        => $_SESSION['user_id'] ?? null,
            'email'          => $_SESSION['email'] ?? null,
            'session_id'     => current_session_id(),
            'correlation_id' => bin2hex(random_bytes(16)),
        ]);
    } catch (Throwable $e) {
        // ei estä uloskirjausta
    }

    // Tyhjennys
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

//
// --- Session-apurit ---
//
function current_user(): ?array
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) return null;
    return [
        'id'    => (int)($_SESSION['user_id']),
        'email' => (string)($_SESSION['email'] ?? ''),
        'role'  => (string)($_SESSION['role'] ?? 'user'),
    ];
}

function require_login(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role(array $roles): void
{
    require_login();
    $cur = (string)($_SESSION['role'] ?? '');
    if (!in_array($cur, $roles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function require_permission(string $perm): void
{
    if (!can($perm)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

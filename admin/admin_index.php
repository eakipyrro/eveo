<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/audit.php';

require_login();

// roolirajaus hubiin
$role = $_SESSION['role'] ?? 'guest';
$allowedRoles = ['admin'];
if (!in_array($role, $allowedRoles, true)) {
    header('Location: ../index.php');
    exit;
}


$pdo = db();

// --- PIKA-TILASTOT ---
// Käyttäjät
$stats = [
    'users_total' => 0,
    'users_active' => 0,
    'users_inactive' => 0,
    'roles' => ['admin' => 0, 'manager' => 0, 'eveo' => 0, 'user' => 0],
];

try {
    $stats['users_total'] = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Throwable $e) {
}
try {
    $stats['users_active'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
} catch (Throwable $e) {
}
try {
    $stats['users_inactive'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=0")->fetchColumn();
} catch (Throwable $e) {
}
foreach (['admin', 'manager', 'eveo', 'user'] as $rname) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        $st->execute([$rname]);
        $stats['roles'][$rname] = (int) $st->fetchColumn();
    } catch (Throwable $e) {
    }
}

// --- apurit: onko sarake olemassa? ---
function col_exists(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '|' . $col;
    if (isset($cache[$key]))
        return $cache[$key];
    $st = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    $cache[$key] = ((int) $st->fetchColumn() > 0);
    return $cache[$key];
}

// IP-sarakkeen ilmaisu tilanteen mukaan
$ipExpr = col_exists($pdo, 'audit_events', 'ip_bin')
    ? "COALESCE(INET6_NTOA(ip_bin), ip_address)"
    : "ip_address";

// Aikaleiman sarake (fallback jos migraatio kesken)
$tsCol = col_exists($pdo, 'audit_events', 'occurred_at') ? 'occurred_at' : 'ts_utc';

// --- Viimeiset 5 epäonnistunutta (login_failed + varalla reasonista virhemaininnat) ---
$lastFails = [];
try {
    $sqlFail = "
      SELECT 
        id, {$tsCol} AS occurred_at, event_type, reason,
        {$ipExpr} AS ip_text, user_id, role, request_uri
      FROM audit_events
      WHERE 
        event_type = 'login_failed'
        OR (reason IS NOT NULL AND reason REGEXP '(error|fail|denied|invalid)')
      ORDER BY {$tsCol} DESC, id DESC
      LIMIT 5
    ";
    $lastFails = $pdo->query($sqlFail)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lastFails = [];
}

// --- Viimeiset 5 onnistunutta kirjautumista (login_success) ---
$lastLogins = [];
try {
    $sqlLogin = "
      SELECT 
        ae.id, ae.{$tsCol} AS occurred_at, ae.user_id, u.email,
        {$ipExpr} AS ip_text, ae.event_type
      FROM audit_events ae
      LEFT JOIN users u ON u.id = ae.user_id
      WHERE ae.event_type = 'login_success'
      ORDER BY ae.{$tsCol} DESC, ae.id DESC
      LIMIT 5
    ";
    $lastLogins = $pdo->query($sqlLogin)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lastLogins = [];
}

$PAGE_TITLE = 'Ylläpito';
$REQUIRE_LOGIN = true;
$BACK_HREF = '../index.php';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($debug) {
    try {
        $probe = $pdo->query("
            SELECT id, {$tsCol} AS occurred_at, event_type,
                   {$statusExpr} AS status_eff, user_id, reason
            FROM audit_events
            ORDER BY {$tsCol} DESC, id DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre style='background:#111;color:#eee;padding:10px;border-radius:8px;overflow:auto'>";
        echo "DEBUG: viimeiset 5 audit_events -riviä (ilman suodatusta)\n\n";
        foreach ($probe as $p) {
            echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        }
        echo "</pre>";
    } catch (Throwable $e) {
        echo "<pre style='background:#111;color:#eee;padding:10px;border-radius:8px'>DEBUG-virhe: " . h($e->getMessage()) . "</pre>";
    }
}

require_once __DIR__ . '/../include/header.php';
?>
<style>
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 14px;
        margin: 12px 0
    }

    .card {
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface-1);
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 8px
    }

    .card h3 {
        margin: 0;
        font-size: 18px
    }

    .card p {
        margin: 0;
        color: var(--muted)
    }

    .card a.btn {
        align-self: flex-start;
        padding: 8px 12px;
        border: 1px solid var(--border);
        background: var(--surface-2);
        color: var(--text);
        border-radius: 8px;
        text-decoration: none
    }

    .card a.primary {
        background: var(--accent);
        border-color: var(--accent);
        color: #fff
    }

    .section {
        margin-top: 18px
    }

    .kpis {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
        margin: 18px 0
    }

    .kpi {
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface-1);
        padding: 14px
    }

    .kpi h4 {
        margin: 0 0 8px 0;
        font-size: 14px;
        color: var(--label)
    }

    .kpi .num {
        font-size: 28px;
        font-weight: 700
    }

    .table {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 10px;
        overflow: hidden
    }

    .table table {
        width: 100%;
        border-collapse: collapse
    }

    .table th,
    .table td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--border);
        vertical-align: top
    }

    .table thead th {
        background: var(--panel);
        color: var(--label)
    }

    .small {
        font-size: 12px;
        opacity: .85
    }

    .nowrap {
        white-space: nowrap
    }
</style>

<h1>Ylläpito</h1>
<p class="section">Tervetuloa ylläpitonäkymään. Alla pika-tilastot sekä linkit työkaluihin.</p>

<!-- PIKA-KPI:t -->
<div class="kpis">
    <div class="kpi">
        <h4>Käyttäjiä yhteensä</h4>
        <div class="num"><?= number_format($stats['users_total'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi">
        <h4>Aktiiviset</h4>
        <div class="num"><?= number_format($stats['users_active'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi">
        <h4>Ei-aktiiviset</h4>
        <div class="num"><?= number_format($stats['users_inactive'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi">
        <h4>Admin</h4>
        <div class="num"><?= (int) $stats['roles']['admin'] ?></div>
    </div>
    <div class="kpi">
        <h4>Manager</h4>
        <div class="num"><?= (int) $stats['roles']['manager'] ?></div>
    </div>
    <div class="kpi">
        <h4>Eveo</h4>
        <div class="num"><?= (int) $stats['roles']['eveo'] ?></div>
    </div>
    <div class="kpi">
        <h4>User</h4>
        <div class="num"><?= (int) $stats['roles']['user'] ?></div>
    </div>
</div>

<!-- Linkkikortit -->
<div class="grid">

    <div class="card">
        <h3>Käyttäjähallinta</h3>
        <p>Luo ja hallitse käyttäjiä, rooleja ja salasanoja.</p>
        <a class="btn primary" href="admin_users.php">Avaa käyttäjähallinta</a>
    </div>

    <div class="card">
        <h3>Audit-loki</h3>
        <p>Selaa tapahtumia, suodata ja vie CSV:ksi.</p>
        <a class="btn" href="admin_audit.php">Avaa audit-loki</a>
    </div>

    <div class="card">
        <h3>Raportit</h3>
        <p>OAS/OATV-analytiikat ja raportit.</p>
        <a class="btn" href="../reports.php">Avaa raportit</a>
    </div>

    <div class="card">
        <h3>Eveo-työkalut</h3>
        <p>CSV-importit ja analyysit.</p>
        <a class="btn" href="../eveo/index.php">Avaa Eveo-työkalut</a>
    </div>

    <div class="card">
        <h3>Manager-työkalut</h3>
        <p>Prosessit ja asetukset.</p>
        <a class="btn" href="../manager/index.php">Avaa manager-työkalut</a>
    </div>

</div>

<!-- Viimeisimmät virheet -->
<h2 class="section">Viimeiset epäonnistuneet tapahtumat</h2>
<div class="table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Aika (FI)</th>
                <th>Event</th>
                <th>Actor</th>
                <th>IP</th>
                <th>Sivu</th>
                <th>Syy</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $tz = new DateTimeZone('Europe/Helsinki');
            foreach ($lastFails as $r):
                $dt = (new DateTime(($r['occurred_at'] ?? '') . ' UTC'))->setTimezone($tz)->format('Y-m-d H:i:s');
                $path = '';
                if (!empty($r['request_uri'])) {
                    $path = parse_url((string) $r['request_uri'], PHP_URL_PATH) ?? '';
                }
                ?>
                <tr>
                    <td class="nowrap"><?= (int) $r['id'] ?></td>
                    <td class="nowrap"><?= h($dt) ?></td>
                    <td><?= h($r['event_type'] ?? '') ?></td>
                    <td><?= h((string) ($r['user_id'] ?? '—')) ?> <span class="small"><?= h($r['role'] ?? '') ?></span></td>
                    <td class="nowrap"><?= h($r['ip_text'] ?? '') ?></td>
                    <td><?= h($path) ?></td>
                    <td><?= h($r['reason'] ?? '') ?></td>
                </tr>
            <?php endforeach;
            if (!$lastFails): ?>
                <tr>
                    <td colspan="7" class="small">Ei epäonnistuneita eventtejä</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Viimeiset onnistuneet kirjautumiset -->
<h2 class="section">Viimeiset kirjautumiset</h2>
<div class="table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Aika (FI)</th>
                <th>User ID</th>
                <th>Email</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lastLogins as $r):
                $dt = (new DateTime(($r['occurred_at'] ?? '') . ' UTC'))->setTimezone(new DateTimeZone('Europe/Helsinki'))->format('Y-m-d H:i:s');
                ?>
                <tr>
                    <td class="nowrap"><?= (int) $r['id'] ?></td>
                    <td class="nowrap"><?= h($dt) ?></td>
                    <td><?= h((string) ($r['user_id'] ?? '—')) ?></td>
                    <td><?= h($r['email'] ?? '') ?></td>
                    <td class="nowrap"><?= h($r['ip_text'] ?? '') ?></td>
                </tr>
            <?php endforeach;
            if (!$lastLogins): ?>
                <tr>
                    <td colspan="5" class="small">Ei kirjautumisia</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../include/footer.php'; ?>
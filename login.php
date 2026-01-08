<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/include/csrf.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/audit.php';

$err = null;
$info = null;

// Yhden pyynnön correlation-id auditointiin
$correlationId = bin2hex(random_bytes(16));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    // 1) CSRF-validointi
    try {
        csrf_validate();
    } catch (Throwable $e) {
        $err = 'invalid';
        audit_log('login_csrf_failed', [
            'page'            => 'login.php',
            'email'           => $email !== '' ? $email : null,
            'status'          => 'fail',
            'reason'          => 'csrf_failed',
            'correlation_id'  => $correlationId,
        ]);
        // ei paljasteta syytä käyttäjälle
    }

    if (!$err) {
        $pass = (string)($_POST['password'] ?? '');
        // HUOM: ÄLÄ KOSKAAN LOGITA SALASANAA

        $res = do_login($email, $pass);

        if (($res['ok'] ?? false) === true) {
            // Onnistunut login – kirjaa ennen redirectiä
            audit_log('login_success', [
                'page'            => 'login.php',
                'email'           => $email !== '' ? $email : null,
                'user_id'         => $res['user_id'] ?? ($_SESSION['user_id'] ?? null),
                'status'          => 'success',
                'correlation_id'  => $correlationId,
            ]);
            header('Location: index.php');
            exit;
        }

        // Epäonnistunut login – kirjaa virhekoodi (ilman salasanaa)
        $err = (string)($res['error'] ?? 'invalid');
        audit_log('login_failed', [
            'page'            => 'login.php',
            'email'           => $email !== '' ? $email : null,
            'status'          => 'fail',
            'reason'          => $err,   // esim. locked / invalid / throttled
            'correlation_id'  => $correlationId,
        ]);
    }
}
?>
<!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kirjaudu sisään</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0b1020;color:#e7ecff;display:grid;place-items:center;min-height:100vh}
        .card{background:#11193a;border:1px solid #2a3161;padding:32px;border-radius:16px;width:min(420px,92vw);box-shadow:0 10px 30px rgba(0,0,0,.35)}
        .card h1{margin:0 0 12px;font-size:24px}
        .muted{color:#aeb7e6;font-size:14px;margin-bottom:16px}
        label{display:block;font-size:14px;margin:10px 0 6px}
        input{width:100%;padding:12px;border-radius:10px;border:1px solid #2a3161;background:#0c1532;color:#fff}
        button{margin-top:16px;width:100%;padding:12px;border:0;border-radius:12px;background:#4c6fff;color:#fff;font-weight:600;cursor:pointer}
        .err{background:#3a1220;border:1px solid #7b1933;color:#ffb3c2;padding:10px 12px;border-radius:10px;margin-bottom:10px}
        .info{background:#123a20;border:1px solid #197b4f;color:#c7ffeb;padding:10px 12px;border-radius:10px;margin-bottom:10px}
        a{color:#b9c6ff}
    </style>
</head>
<body>
<form class="card" method="post" action="login.php" autocomplete="off">
    <h1>Kirjaudu sisään</h1>
    <p class="muted">Syötä sähköposti ja salasana.</p>

    <?php if ($err): ?>
        <div class="err">
            <?php if ($err === 'locked'): ?>
                Liian monta epäonnistunutta yritystä. Yritä uudelleen myöhemmin.
            <?php else: ?>
                Virheellinen sähköposti tai salasana.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <label for="email">Sähköposti</label>
    <input type="email" id="email" name="email" required autocomplete="username" autofocus>

    <label for="password">Salasana</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">

    <?php echo csrf_field(); ?>
    <button type="submit">Kirjaudu</button>
    <p class="muted" style="margin-top:14px">Unohtuiko salasana? (lisää tähän linkki kun toteutat resetoinnin)</p>
</form>
</body>
</html>

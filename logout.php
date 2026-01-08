<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/include/auth.php';

// varmista että tämä tiedosto on oikeassa polussa
require_once __DIR__ . '/include/audit.php';

if (function_exists('audit_log')) {
    audit_log('logout', [
        'page'    => 'logout.php',
        'user_id' => $_SESSION['user_id'] ?? null,
        'role'    => $_SESSION['role'] ?? null
    ]);
} else {
    error_log('audit_log() not found – include/audit.php missing or failed to load');
}

logout();

header('Location: login.php');
exit;
?>

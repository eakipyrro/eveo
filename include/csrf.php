<?php
require_once __DIR__ . '/config.php';

function csrf_token(): string {
if (empty($_SESSION[CSRF_KEY])) {
$_SESSION[CSRF_KEY] = bin2hex(random_bytes(32));
}
return $_SESSION[CSRF_KEY];
}

function csrf_field(): string {
$t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
return '<input type="hidden" name="csrf" value="'.$t.'">';
}

function csrf_validate(): void {
$ok = isset($_POST['csrf'], $_SESSION[CSRF_KEY]) && hash_equals($_SESSION[CSRF_KEY], $_POST['csrf']);
if (!$ok) {
http_response_code(400);
exit('CSRF check failed');
}
}
?>
<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
$curr = $_SESSION['theme'] ?? 'light';
$_SESSION['theme'] = ($curr === 'dark') ? 'light' : 'dark';
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
exit;

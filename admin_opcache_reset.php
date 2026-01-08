<?php
// admin_opcache_reset.php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
if (!function_exists('can') || !can('view_manager_tools')) { http_response_code(403); exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');

if (function_exists('opcache_reset')) {
    echo opcache_reset() ? "OPcache reset OK\n" : "OPcache reset FAILED\n";
} else {
    echo "OPcache not enabled\n";
}

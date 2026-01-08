<?php
// include/util.php
function ip_bin(string $ip = null): ?string {
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    if (!$ip) return null;
    return @inet_pton($ip) ?: null;
}

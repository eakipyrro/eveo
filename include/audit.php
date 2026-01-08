<?php
// include/audit.php
if (session_status() === PHP_SESSION_NONE) session_start();

// --- PHP 7 -yhteensopiva apu str_containsille ---
if (!function_exists('audit_str_contains')) {
    function audit_str_contains(string $haystack, string $needle): bool {
        // PHP 8: str_contains; PHP 7: strpos
        if (function_exists('str_contains')) return str_contains($haystack, $needle);
        return $needle === '' ? true : (strpos($haystack, $needle) !== false);
    }
}

function audit_ip_to_bin(?string $ip): ?string {
    if (!$ip) return null;
    $bin = @inet_pton($ip);
    return $bin === false ? null : $bin;
}

function audit_client_ip(): ?string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip && audit_str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip, 2)[0]);
    }
    return $ip ?: null;
}

/**
 * Kirjaa audit-tapahtuman.
 * $eventType: esim. 'login_success','login_failed','logout','report_generated'
 * $details:   taulukko, serialisoidaan JSONiksi. ÄLÄ laita salasanoja tms.
 */
function audit_log(string $eventType, array $details = []): void {
    try {
        // <<< MUUTOS: lataa db.php vasta tässä, ei tiedoston alussa >>>
        require_once __DIR__ . '/db.php'; // tarjoaa db() -> PDO

        if (!function_exists('db')) {
            error_log('audit_log: db() puuttuu (include/db.php ei määrittänyt sitä)');
            return;
        }

        $pdo = db();

        $userId    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $role      = $_SESSION['role'] ?? null;
        $sessionId = session_id() ?: null;
        $ip        = audit_client_ip();
        $ua        = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $method    = $_SERVER['REQUEST_METHOD'] ?? null;
        $uri       = $_SERVER['REQUEST_URI'] ?? null;

        $sql = "INSERT INTO audit_events
                (occurred_at, user_id, role, session_id, event_type,
                 ip_address, user_agent, http_method, request_uri, details_json)
                VALUES (NOW(), :uid, :role, :sid, :etype,
                        :ip, :ua, :method, :uri, :details)";
        $stmt = $pdo->prepare($sql);

        // selkeämmät bindit kuin bitwise OR:
        if ($userId === null) $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
        else                  $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);

        if ($role === null) $stmt->bindValue(':role', null, PDO::PARAM_NULL);
        else                $stmt->bindValue(':role', $role, PDO::PARAM_STR);

        if ($sessionId === null) $stmt->bindValue(':sid', null, PDO::PARAM_NULL);
        else                     $stmt->bindValue(':sid', $sessionId, PDO::PARAM_STR);

        $stmt->bindValue(':etype', $eventType, PDO::PARAM_STR);

        $ipBin = audit_ip_to_bin($ip);
        if ($ipBin === null) $stmt->bindValue(':ip', null, PDO::PARAM_NULL);
        else                 $stmt->bindValue(':ip', $ipBin, PDO::PARAM_STR);

        if ($ua === null) $stmt->bindValue(':ua', null, PDO::PARAM_NULL);
        else              $stmt->bindValue(':ua', $ua, PDO::PARAM_STR);

        if ($method === null) $stmt->bindValue(':method', null, PDO::PARAM_NULL);
        else                  $stmt->bindValue(':method', $method, PDO::PARAM_STR);

        if ($uri === null) $stmt->bindValue(':uri', null, PDO::PARAM_NULL);
        else               $stmt->bindValue(':uri', $uri, PDO::PARAM_STR);

        if ($details) $stmt->bindValue(':details', json_encode($details, JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        else          $stmt->bindValue(':details', null, PDO::PARAM_NULL);

        $stmt->execute();
    } catch (Throwable $e) {
        // Älä kaada pyyntöä audit-virheeseen; kirjoita lokiin
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

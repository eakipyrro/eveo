<?php
// Kehityksen aikaista lokitusta varten: kirjoita oman polun alle
// HUOM: tee varmuudeksi /logs -kansio projektijuureen ja anna web-käyttäjälle oikeudet.
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0775, true);
}

@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/logs/php_errors_import_oas.log');

// OAS-kirjastolle kerrotaan lokikansio, jos ei jo määritelty muualla
if (!defined('OAS_ERROR_LOG_DIR')) {
    define('OAS_ERROR_LOG_DIR', __DIR__ . '/logs');
}

// Pakota OPcache invalidoitumaan näille (poista kun valmista)
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    @opcache_invalidate(__DIR__ . '/include/importers/oas_lib.php', true);
}

// JSON + cache-bypass headerit JA build-leima
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Import-OAS-Build: ' . gmdate('c'));

// Kevyt debug-jälki error_logiin (näet, osuuko tähän tiedostoon)
error_log('HIT import_oas.php @ ' . date('c') . ' __DIR__=' . realpath(__DIR__));
// --- auth & perus-include't ---
require_once __DIR__ . '/include/auth.php';
require_login();
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/importers/oas_lib.php';

// --- jos oas_log puuttuu, tee varmistus-shimmi ---
if (!function_exists('oas_log')) {
    function oas_log(string $msg): void {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/oas_import_errors.log';
        @file_put_contents($file, '[OAS-SHIM] ' . $msg . PHP_EOL, FILE_APPEND);
        @error_log('[OAS-SHIM] ' . $msg);
    }
}

// --- ohjaa php-virheet ja poikkeukset oas_logiin ---
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    oas_log("PHP ERROR [$severity] $message at $file:$line");
    return false; // anna myös PHP:n oman handlerin tehdä työnsä
});
set_exception_handler(function ($ex) {
    oas_log('UNCAUGHT ' . get_class($ex) . ': ' . $ex->getMessage() . ' at ' . $ex->getFile() . ':' . $ex->getLine());
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        oas_log('FATAL@import_oas: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
});

oas_log('BOOT import_oas OK: build=' . gmdate('c'));

// --- tiukka virheenkäsittely -> JSON ---
$__errs = [];
set_error_handler(function ($no, $str, $file, $line) use (&$__errs) {
    $__errs[] = "PHP[$no] $str @ $file:$line";
});
function json_fail($msg, $extra = [])
{
    global $__errs;
    $payload = array_merge(['ok' => false, 'error' => $msg], $extra);
    if (!empty($__errs))
        $payload['php_errors'] = $__errs;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok($payload)
{
    global $__errs;
    if (!isset($payload['ok']))
        $payload['ok'] = true;
    if (!empty($__errs))
        $payload['php_errors'] = $__errs;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- auth ---
require_once __DIR__ . '/include/auth.php';
if (!function_exists('require_login'))
    json_fail('Auth bootstrap puuttuu');
require_login();
$u = current_user();
if (!function_exists('can') || !can('view_manager_tools'))
    json_fail('Forbidden');

// --- kirjastot ---
$libPath = __DIR__ . '/include/importers/oas_lib.php';
if (!is_file($libPath))
    json_fail('Puuttuva kirjasto: include/importers/oas_lib.php');
require_once $libPath;

// --- requestin perusvalidointi ---
$action = $_POST['action'] ?? '';
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_import'] ?? '', $_POST['csrf'])) {
    json_fail('Virheellinen CSRF');
}
if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_fail('CSV-tiedostoa ei saatu ladattua');
}

// --- optiot (sama malli kuin OATV) ---
$delReq = $_POST['delimiter'] ?? 'auto';
$delimiter = match ($delReq) {
    'auto' => 'auto',
    'comma' => ',',
    'semicolon' => ';',
    'tab' => '\\t',
    'pipe' => '|',
    ',', ';', '\\t', '|' => $delReq,
    default => 'auto'
};
$opts = [
    'encoding' => $_POST['encoding'] ?? 'UTF-8',
    'delimiter' => $delimiter,
    'has_header' => !empty($_POST['has_header']),
    'date_format' => $_POST['date_format'] ?? null,
    'preview_rows' => (int) ($_POST['preview_rows'] ?? 3),
];

// --- esikatselu ---
if ($action === 'preview') {
    $prev = oas_preview($_FILES['csv']['tmp_name'], $opts);
    if (empty($prev['ok']))
        json_fail($prev['error'] ?? 'Esikatselu epäonnistui');
    json_ok([
        'mode' => 'preview',
        'delimiter_label' => $prev['delimiter'],
        'detected_label' => $prev['detected'],
        'header_raw' => $prev['header_raw'],
        'header_norm' => $prev['header_norm'],
        'rows' => $prev['rows'],       // raw
        'rows_norm' => $prev['rows_norm'],  // normalized (sis. contract-split)
        'note' => $prev['note'],
    ]);
}

// --- import ---
if ($action === 'import') {
    // käytetään sinun db.php:tä
    $dbPath = __DIR__ . '/include/db.php';
    if (!is_file($dbPath))
        json_fail('Puuttuva DB bootstrap: include/db.php');
    require_once $dbPath;
    if (!function_exists('db'))
        json_fail('db() puuttuu include/db.php:sta');

    try {
        $pdo = db();
    } catch (Throwable $e) {
        json_fail('DB-yhteys epäonnistui: ' . $e->getMessage());
    }

    $res = oas_import($pdo, $_FILES['csv']['tmp_name'], $opts);
    $skipped = 0;
    if (!empty($res['total']) && isset($res['inserted'])) {
        $skipped = max(0, (int) $res['total'] - (int) $res['inserted']);
    }

    json_ok([
        'mode' => 'import',
        'delimiter_label' => $res['delimiter'] ?? '',
        'detected_label' => $res['detected'] ?? '',
        'result' => [
            'total' => (int) ($res['total'] ?? 0),
            'inserted' => (int) ($res['inserted'] ?? 0),
            'skipped' => (int) $skipped,
            'preview' => $res['preview'] ?? [],
            'errors' => $res['errors'] ?? [],
        ],
    ]);
}

// --- tuntematon ---
json_fail('Tuntematon toimenpide');

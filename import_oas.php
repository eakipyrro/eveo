<?php
declare(strict_types=1);

// --- LOKITUSKANSIO ---
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0775, true);
}

// Kirjoitetaan virheet omaan lokiin
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/logs/php_errors_import_oas.log');

// OAS-kirjastolle peruslokikansio, jos ei ole määritelty
if (!defined('OAS_ERROR_LOG_DIR')) {
    define('OAS_ERROR_LOG_DIR', __DIR__ . '/logs');
}

// Varmuuden vuoksi: opcache invalidate (voi poistaa kun valmista)
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    @opcache_invalidate(__DIR__ . '/include/importers/oas_lib.php', true);
}

// JSON-headerit
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Import-OAS-Build: ' . gmdate('c'));

// --- SESSION ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- VIRHEBUFFERI JSONIA VARTEN ---
$__errs = [];
$GLOBALS['__errs'] = &$__errs;

set_error_handler(function ($no, $str, $file, $line) {
    $msg = "PHP[$no] $str @ $file:$line";
    $GLOBALS['__errs'][] = $msg;
    // lisäksi normaalisti error_logiin
    error_log($msg);
});


// --- JSON-APURIT ---
function json_fail(string $msg, array $extra = []): void
{
    $payload = array_merge(['ok' => false, 'error' => $msg], $extra);
    if (!empty($GLOBALS['__errs'])) {
        $payload['php_errors'] = $GLOBALS['__errs'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $payload): void
{
    if (!isset($payload['ok'])) {
        $payload['ok'] = true;
    }
    if (!empty($GLOBALS['__errs'])) {
        $payload['php_errors'] = $GLOBALS['__errs'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AUTH ---
require_once __DIR__ . '/include/auth.php';
if (!function_exists('require_login')) {
    json_fail('Auth bootstrap puuttuu');
}
require_login();
$u = current_user();

// Vain manager/admin voi käyttää
if (function_exists('can') && !can('view_manager_tools')) {
    json_fail('Forbidden');
}

// --- KIRJASTOT ---
require_once __DIR__ . '/include/db.php';

$libPath = __DIR__ . '/include/importers/oas_lib.php';
if (!is_file($libPath)) {
    json_fail('Puuttuva kirjasto: include/importers/oas_lib.php');
}
require_once $libPath;

// --- REQUEST-VALIDOINTI ---
$action = $_POST['action'] ?? '';

if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_import'] ?? '', $_POST['csrf'])) {
    json_fail('Virheellinen CSRF');
}

if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_fail('CSV-tiedostoa ei saatu ladattua');
}

// --- OPTIOT (sama malli kuin OATV) ---
$delReq = $_POST['delimiter'] ?? 'auto';
$delimiter = match ($delReq) {
    'auto' => 'auto',
    'comma' => ',',
    'semicolon' => ';',
    'tab' => "\t",
    'pipe' => '|',
    ',', ';', "\t", '|' => $delReq,
    default => 'auto',
};

$opts = [
    'encoding' => $_POST['encoding'] ?? 'UTF-8',
    'delimiter' => $delimiter,
    'has_header' => !empty($_POST['has_header']),
    'date_format' => $_POST['date_format'] ?? null,
    'preview_rows' => (int) ($_POST['preview_rows'] ?? 3),
];

// --- ESIKATSELU ---
if ($action === 'preview') {
    try {
        if (!function_exists('oas_preview')) {
            json_fail('oas_preview() puuttuu oas_lib.php:stä');
        }

        $prev = oas_preview($_FILES['csv']['tmp_name'], $opts);
        if (empty($prev['ok'])) {
            json_fail($prev['error'] ?? 'Esikatselu epäonnistui', [
                'errors' => $prev['errors'] ?? [],
            ]);
        }

        json_ok([
            'mode' => 'preview',
            'delimiter_label' => $prev['delimiter'] ?? '',
            'detected_label' => $prev['detected'] ?? '',
            'header_raw' => $prev['header_raw'] ?? [],
            'header_norm' => $prev['header_norm'] ?? [],
            'rows' => $prev['rows'] ?? [],
            'rows_norm' => $prev['rows_norm'] ?? [],
            'note' => $prev['note'] ?? '',
        ]);
    } catch (Throwable $e) {
        json_fail('Esikatselu heitti poikkeuksen: ' . $e->getMessage());
    }
}

// --- VARSINAINEN IMPORT ---
if ($action === 'import') {
    try {
        if (!function_exists('db')) {
            json_fail('db() puuttuu include/db.php:stä');
        }

        $pdo = db();

        if (!function_exists('oas_import')) {
            json_fail('oas_import() puuttuu oas_lib.php:stä');
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
                'preview_raw' => $res['preview_raw'] ?? [],
                'errors' => $res['errors'] ?? [],
            ],
        ]);

    } catch (Throwable $e) {
        json_fail('Tuonti heitti poikkeuksen: ' . $e->getMessage());
    }
}

// --- TUNTEMATON TOIMENPIDE ---
json_fail('Tuntematon toimenpide: ' . $action);

<?php
// import_overnight.php – AJAX endpoint overnight-raportille (preview + import)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/importers/overnight_lib.php';

// CSRF kuten OAS/OATV
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_import']) || !hash_equals($_SESSION['csrf_import'], $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'CSRF virhe']);
    exit;
}

$action = $_POST['action'] ?? 'preview'; // preview | import
$encoding = $_POST['encoding'] ?? 'UTF-8';
$dateFmt = $_POST['date_format'] ?? 'Y-m-d';
$hasHeader = !empty($_POST['has_header']);
$forceChan = isset($_POST['force_channel']) && $_POST['force_channel'] !== '' ? (string) $_POST['force_channel'] : null;
$truncate = !empty($_POST['truncate']);

// Delimiter-käsittely samaan tyyliin
$delimRaw = $_POST['delimiter'] ?? 'auto';
$delimiter = null;
if ($delimRaw !== 'auto') {
    $map = ['comma' => ',', ';' => ';', 'semicolon' => ';', 'tab' => "\t", "\t" => "\t", 'pipe' => '|', '|' => '|', ',' => ','];
    $delimiter = $map[$delimRaw] ?? $delimRaw;
}

if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Tiedoston lataus epäonnistui']);
    exit;
}
$tmpPath = $_FILES['csv']['tmp_name'];

try {
    // import_overnight.php (only the preview branch changed)
    if ($action === 'preview') {
        $res = ovn_preview($tmpPath, $delimiter, $encoding, $hasHeader, $dateFmt, (int) ($_POST['preview_rows'] ?? 3));

        // Normalize payload to match OAS/OATV contract
        $payload = is_array($res) ? $res : [];
        $payload['ok'] = true;
        $payload['mode'] = 'preview';

        // Ensure delimiter_label exists (nice-to-have)
        if (!isset($payload['delimiter_label'])) {
            // Prefer value from $res if present, otherwise derive a readable label
            $payload['delimiter_label'] = $res['delimiter_label']
                ?? (function ($d, $raw) {
                    if ($d === "\t" || $raw === 'tab')
                        return 'tab';
                    if ($d === ';' || $raw === 'semicolon')
                        return 'semicolon';
                    if ($d === ',' || $raw === 'comma')
                        return 'comma';
                    if ($d === '|' || $raw === 'pipe')
                        return 'pipe';
                    return 'auto';
                })($delimiter, $delimRaw);
        }

        echo json_encode($payload);
        exit;
    } elseif ($action === 'import') {
        $res = ovn_import($tmpPath, $delimiter, $encoding, $hasHeader, $dateFmt, $forceChan, $truncate);
        echo json_encode([
            'ok' => true,
            'mode' => 'import',
            'result' => $res,
            'delimiter_label' => $res['delimiter_label'] ?? null,
        ]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Tuntematon action']);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

<?php
// import_oatv.php – vastaanottaa POSTin manager_tools.php:lta ja palauttaa JSONin (preview/import)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/include/auth.php';
require_login();
if (!can('view_manager_tools')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_import']) || !hash_equals($_SESSION['csrf_import'], $csrf)) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Virheellinen CSRF']); exit;
}

// Config
require_once __DIR__ . '/include/config.php';
// DB helperit
require_once __DIR__ . '/include/db.php';
// OATV lib
require_once __DIR__ . '/include/importers/oatv_lib.php';

// Inputit
$action = $_POST['action'] ?? 'preview';
$truncate = isset($_POST['truncate']) && $_POST['truncate'] === '1';
$forceChannel = trim((string)($_POST['force_channel'] ?? ''));
$delimSel = $_POST['delimiter'] ?? 'auto';
$chosenDelim = null;
if ($delimSel === 'comma') $chosenDelim = ',';
elseif ($delimSel === 'semicolon') $chosenDelim = ';';
elseif ($delimSel === 'tab') $chosenDelim = "\t";
elseif ($delimSel === 'pipe') $chosenDelim = '|'; // muuten null = auto

$previewRows = max(1, (int)($_POST['preview_rows'] ?? 3));

if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Tiedoston lataus epäonnistui']); exit;
}
$tmp = $_FILES['csv']['tmp_name'];
$name = (string)($_FILES['csv']['name'] ?? 'data.csv');
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt'], true)) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Sallitut muodot: CSV tai TXT']); exit;
}

try {
    if ($action === 'preview') {
        $info = oatv_read_header_preview($tmp, $chosenDelim, $previewRows);
        echo json_encode([
            'ok'=>true,
            'mode'=>'preview',
            'delimiter_used'=> $info['delimiter'],
            'delimiter_label'=> oatv_label_for_delim($info['delimiter']),
            'detected_label'=> oatv_label_for_delim($info['detected']),
            'header_raw'=>$info['header_raw'],
            'header_norm'=>$info['header_norm'],
            'rows'=>$info['preview_rows'],
        ]);
    } else { // import
        $res = oatv_import_file($tmp, $truncate, $chosenDelim, $forceChannel);
        echo json_encode([
            'ok'=>true,
            'mode'=>'import',
            'result'=>$res,
            'delimiter_label'=> oatv_safe_label_delim($chosenDelim),
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

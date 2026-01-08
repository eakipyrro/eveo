<?php
session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

header('Content-Type: application/json');

require_once __DIR__ . '/include/db.php';
$pdo = db();

$campaign = $_GET['campaign'] ?? '';
$account = $_GET['account'] ?? '';
$from = $_GET['start'] ?? '';
$to = $_GET['end'] ?? '';

try {
    // Same campaign filter logic as main report
    $campaignFilter = ($campaign === '(tyhjä)')
        ? "(o.artist IS NULL OR TRIM(o.artist) = '')"
        : "(
             LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.artist,'')), ' +', ' ')) = LOWER(REGEXP_REPLACE(:campaign, ' +', ' '))
          OR LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.title ,'')), ' +', ' ')) = LOWER(REGEXP_REPLACE(:campaign, ' +', ' '))
          OR LOWER(COALESCE(o.artist,'')) LIKE CONCAT('%', LOWER(:campaign), '%')
          OR LOWER(COALESCE(o.title ,'')) LIKE CONCAT('%', LOWER(:campaign), '%')
        )";

    $o_date = "
        COALESCE(
            STR_TO_DATE(o.`date`, '%Y-%m-%d'),
            STR_TO_DATE(o.`date`, '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
        )
    ";

    $sql = "SELECT COUNT(*) as total FROM oatv o WHERE $campaignFilter AND $o_date BETWEEN :dfrom AND :dto";
    
    $params = [':dfrom' => $from, ':dto' => $to];
    if ($campaign !== '(tyhjä)') {
        $params[':campaign'] = $campaign;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'total' => (int)$result['total'],
        'chunkSize' => 100 // Process 100 spots at a time
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

header('Content-Type: application/json; charset=utf-8');

try {
    // Get calculated results from AJAX
    $totalReach = isset($_GET['total_reach']) ? (int)$_GET['total_reach'] : 0;
    $totalAvgViewers = isset($_GET['total_avg_viewers']) ? (float)$_GET['total_avg_viewers'] : 0.0;
    $processedCount = isset($_GET['processed_count']) ? (int)$_GET['processed_count'] : 0;
    
    // Get duration-specific data (JSON encoded)
    $durationDataJson = $_GET['duration_data'] ?? '{}';
    $durationData = json_decode($durationDataJson, true) ?: [];
    
    // Save to session with timestamp
    $_SESSION['report_results'] = [
        'total_reach' => $totalReach,
        'total_avg_viewers' => $totalAvgViewers,
        'processed_count' => $processedCount,
        'duration_data' => $durationData,
        'timestamp' => time(),
        'params' => [
            'account' => $_GET['account'] ?? '',
            'campaign' => $_GET['campaign'] ?? '',
            'start' => $_GET['start'] ?? '',
            'end' => $_GET['end'] ?? '',
            'trp' => $_GET['trp'] ?? '',
            'pop' => $_GET['pop'] ?? '',
        ]
    ];
    
    echo json_encode([
        'success' => true,
        'saved' => true,
        'duration_count' => count($durationData)
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
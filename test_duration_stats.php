<?php
/**
 * Duration Statistics Diagnostic Test
 * 
 * This script tests why duration statistics are not showing up.
 * Upload this file to your server and visit it with your campaign parameters.
 * 
 * Example URL:
 * test_duration_stats.php?campaign=Sanytol+2710_231125&start=2025-10-27&end=2025-11-23
 */

session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

require_once __DIR__ . '/include/db.php';
$pdo = db();

$campaign = $_GET['campaign'] ?? '';
$from = $_GET['start'] ?? '';
$to = $_GET['end'] ?? '';

echo "<html><head><title>Duration Stats Diagnostic</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
.success { color: #4CAF50; font-weight: bold; }
.error { color: #f44336; font-weight: bold; }
.warning { color: #ff9800; font-weight: bold; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #4CAF50; color: white; }
pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
.info { background: #e3f2fd; padding: 10px; border-left: 4px solid #2196F3; margin: 10px 0; }
</style></head><body>";

echo "<h1>🔍 Duration Statistics Diagnostic Test</h1>";

echo "<div class='info'>";
echo "<strong>Parameters:</strong><br>";
echo "Campaign: " . htmlspecialchars($campaign) . "<br>";
echo "From: " . htmlspecialchars($from) . "<br>";
echo "To: " . htmlspecialchars($to) . "<br>";
echo "</div>";

if (empty($campaign) || empty($from) || empty($to)) {
    echo "<div class='section'><p class='error'>❌ Missing required parameters. Please provide: campaign, start, and end dates.</p></div>";
    echo "</body></html>";
    exit;
}

// Test 1: Check if campaign exists in OATV
echo "<div class='section'>";
echo "<h2>Test 1: Campaign Existence</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM oatv 
        WHERE TRIM(artist) = :campaign
    ");
    $stmt->execute([':campaign' => $campaign]);
    $result = $stmt->fetch();
    
    if ($result['total'] > 0) {
        echo "<p class='success'>✅ Found {$result['total']} spots for this campaign</p>";
    } else {
        echo "<p class='error'>❌ No spots found for this campaign</p>";
        echo "<p>Try checking with LIKE:</p>";
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT artist, COUNT(*) as cnt
            FROM oatv 
            WHERE LOWER(artist) LIKE LOWER(:campaign)
            GROUP BY artist
            LIMIT 10
        ");
        $stmt->execute([':campaign' => '%' . $campaign . '%']);
        $results = $stmt->fetchAll();
        
        if ($results) {
            echo "<table><tr><th>Artist</th><th>Count</th></tr>";
            foreach ($results as $row) {
                echo "<tr><td>" . htmlspecialchars($row['artist']) . "</td><td>{$row['cnt']}</td></tr>";
            }
            echo "</table>";
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// Test 2: Check duration data format
echo "<div class='section'>";
echo "<h2>Test 2: Duration Data Format</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT 
            duration,
            COUNT(*) as count,
            MIN(duration) as min_val,
            MAX(duration) as max_val
        FROM oatv 
        WHERE TRIM(artist) = :campaign
          AND duration IS NOT NULL
          AND TRIM(duration) != ''
        GROUP BY duration
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute([':campaign' => $campaign]);
    $results = $stmt->fetchAll();
    
    if ($results) {
        echo "<p class='success'>✅ Found " . count($results) . " distinct duration values</p>";
        echo "<table>";
        echo "<tr><th>Duration Value</th><th>Count</th><th>Type</th></tr>";
        foreach ($results as $row) {
            $type = "Unknown";
            if (preg_match('/^[0-9]+$/', $row['duration'])) {
                $type = "Number (seconds)";
            } elseif (preg_match('/^[0-9]{1,2}:[0-9]{2}(:[0-9]{2})?$/', $row['duration'])) {
                $type = "Time format";
            }
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['duration']) . "</td>";
            echo "<td>{$row['count']}</td>";
            echo "<td>{$type}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>❌ No duration data found for this campaign</p>";
        
        // Check if ANY spots have duration
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN duration IS NULL OR TRIM(duration) = '' THEN 1 ELSE 0 END) as null_count
            FROM oatv 
            WHERE TRIM(artist) = :campaign
        ");
        $stmt->execute([':campaign' => $campaign]);
        $result = $stmt->fetch();
        echo "<p>Total spots: {$result['total']}, Missing duration: {$result['null_count']}</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// Test 3: Test the actual duration parsing SQL
echo "<div class='section'>";
echo "<h2>Test 3: Duration Parsing Test</h2>";

try {
    $durSec = "
        CASE
          WHEN o.`duration` REGEXP '^[0-9]+$'
            THEN CAST(o.`duration` AS UNSIGNED)
          WHEN o.`duration` REGEXP '^[0-9]{1,2}:[0-9]{2}(:[0-9]{2})?$'
            THEN TIME_TO_SEC(STR_TO_DATE(o.`duration`, '%H:%i:%s'))
          ELSE NULL
        END
    ";
    
    $stmt = $pdo->prepare("
        SELECT 
            o.duration as raw_duration,
            $durSec as parsed_seconds,
            COUNT(*) as count
        FROM oatv o
        WHERE TRIM(o.artist) = :campaign
          AND o.duration IS NOT NULL
          AND TRIM(o.duration) != ''
        GROUP BY o.duration, parsed_seconds
        ORDER BY count DESC
    ");
    $stmt->execute([':campaign' => $campaign]);
    $results = $stmt->fetchAll();
    
    if ($results) {
        echo "<p class='success'>✅ Duration parsing successful</p>";
        echo "<table>";
        echo "<tr><th>Raw Duration</th><th>Parsed (seconds)</th><th>Count</th><th>Status</th></tr>";
        foreach ($results as $row) {
            $status = $row['parsed_seconds'] !== null ? "✅ OK" : "❌ Failed";
            $statusClass = $row['parsed_seconds'] !== null ? "success" : "error";
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['raw_duration']) . "</td>";
            echo "<td>" . htmlspecialchars($row['parsed_seconds']) . "</td>";
            echo "<td>{$row['count']}</td>";
            echo "<td class='$statusClass'>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Count NULL parses
        $nullCount = array_sum(array_column(array_filter($results, function($r) { 
            return $r['parsed_seconds'] === null; 
        }), 'count'));
        
        if ($nullCount > 0) {
            echo "<p class='warning'>⚠️ Warning: {$nullCount} spots have duration values that couldn't be parsed</p>";
        }
    } else {
        echo "<p class='error'>❌ No results from parsing test</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// Test 4: Full duration stats query (simplified)
echo "<div class='section'>";
echo "<h2>Test 4: Full Duration Stats Query</h2>";

try {
    $o_date = "
        COALESCE(
            STR_TO_DATE(o.`date`, '%Y-%m-%d'),
            STR_TO_DATE(o.`date`, '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
        )
    ";
    
    $durSec = "
        CASE
          WHEN o.`duration` REGEXP '^[0-9]+$'
            THEN CAST(o.`duration` AS UNSIGNED)
          WHEN o.`duration` REGEXP '^[0-9]{1,2}:[0-9]{2}(:[0-9]{2})?$'
            THEN TIME_TO_SEC(STR_TO_DATE(o.`duration`, '%H:%i:%s'))
          ELSE NULL
        END
    ";
    
    $stmt = $pdo->prepare("
        SELECT 
            $durSec AS dur_sec,
            COUNT(*) AS spot_count
        FROM oatv o
        WHERE TRIM(o.artist) = :campaign
          AND $o_date BETWEEN :dfrom AND :dto
          AND $durSec IS NOT NULL
        GROUP BY dur_sec
        ORDER BY dur_sec ASC
    ");
    $stmt->execute([
        ':campaign' => $campaign,
        ':dfrom' => $from,
        ':dto' => $to
    ]);
    $results = $stmt->fetchAll();
    
    if ($results) {
        echo "<p class='success'>✅ Query returned " . count($results) . " duration groups</p>";
        echo "<table>";
        echo "<tr><th>Duration (seconds)</th><th>Duration (HH:MM:SS)</th><th>Spot Count</th></tr>";
        foreach ($results as $row) {
            $hhmmss = sprintf('%02d:%02d:%02d', 
                floor($row['dur_sec'] / 3600),
                floor(($row['dur_sec'] % 3600) / 60),
                $row['dur_sec'] % 60
            );
            echo "<tr>";
            echo "<td>{$row['dur_sec']}</td>";
            echo "<td>{$hhmmss}</td>";
            echo "<td>{$row['spot_count']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p class='success'>✅ This data SHOULD be showing in your report!</p>";
    } else {
        echo "<p class='error'>❌ Query returned no results</p>";
        echo "<p>Possible reasons:</p>";
        echo "<ul>";
        echo "<li>Date range filter is excluding all spots</li>";
        echo "<li>All duration values are NULL after parsing</li>";
        echo "<li>Campaign name doesn't match exactly</li>";
        echo "</ul>";
        
        // Try without date range
        echo "<p>Testing without date range filter...</p>";
        $stmt = $pdo->prepare("
            SELECT 
                $durSec AS dur_sec,
                COUNT(*) AS spot_count
            FROM oatv o
            WHERE TRIM(o.artist) = :campaign
              AND $durSec IS NOT NULL
            GROUP BY dur_sec
            ORDER BY dur_sec ASC
        ");
        $stmt->execute([':campaign' => $campaign]);
        $results2 = $stmt->fetchAll();
        
        if ($results2) {
            echo "<p class='warning'>⚠️ Found data WITHOUT date filter: " . count($results2) . " groups</p>";
            echo "<p>This means your date range [{$from} to {$to}] is filtering out all spots!</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
echo "</div>";

// Summary
echo "<div class='section'>";
echo "<h2>📋 Summary</h2>";
echo "<p>If all tests passed but you still don't see duration stats in the main report:</p>";
echo "<ol>";
echo "<li>Check that you're using the updated PHP file (reports_v2_0_3_final.php)</li>";
echo "<li>Check that JavaScript is loaded and working (should see progress bar)</li>";
echo "<li>Try adding <code>&debug=1</code> to your URL to see debug output</li>";
echo "<li>Check browser console (F12) for JavaScript errors</li>";
echo "</ol>";
echo "</div>";

echo "</body></html>";
?>
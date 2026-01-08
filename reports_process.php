<?php
session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

header('Content-Type: application/json');

require_once __DIR__ . '/include/db.php';
$pdo = db();

$campaign = $_GET['campaign'] ?? '';
$from = $_GET['start'] ?? '';
$to = $_GET['end'] ?? '';
$offset = (int)($_GET['offset'] ?? 0);
$limit = (int)($_GET['limit'] ?? 100);

try {
    $campaignFilter = ($campaign === '(tyhjä)')
        ? "((o.artist IS NULL OR TRIM(o.artist)='') AND (o.title IS NULL OR TRIM(o.title)=''))"
        : "(
             LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.artist,'')), ' +', ' ')) = LOWER(REGEXP_REPLACE(:campaign, ' +', ' '))
          OR LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.title ,'')), ' +', ' ')) = LOWER(REGEXP_REPLACE(:campaign, ' +', ' '))
          OR LOWER(COALESCE(o.artist,'')) LIKE CONCAT('%', LOWER(:campaign), '%')
          OR LOWER(COALESCE(o.title ,'')) LIKE CONCAT('%', LOWER(:campaign), '%')
        )";

    $o_date = "
        COALESCE(
            CASE WHEN o.`date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(o.`date`, '%Y-%m-%d') END,
            STR_TO_DATE(o.`date`, '%d.%m.%Y'),
            STR_TO_DATE(o.`date`, '%e.%c.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
        )
    ";

    $o_spotdt = "
        COALESCE(
            STR_TO_DATE(CONCAT($o_date, ' ', o.`time`), '%Y-%m-%d %H:%i:%s'),
            STR_TO_DATE(CONCAT($o_date, ' ', o.`time`), '%Y-%m-%d %H:%i'),
            STR_TO_DATE(CONCAT(DATE_FORMAT($o_date,'%Y-%m-%d'), ' ', o.`time`), '%Y-%m-%d %H:%i:%s'),
            STR_TO_DATE(CONCAT(DATE_FORMAT($o_date,'%Y-%m-%d'), ' ', o.`time`), '%Y-%m-%d %H:%i'),
            STR_TO_DATE(CONCAT(DATE_FORMAT($o_date,'%Y-%m-%d'), ' ', REPLACE(o.`time`,'.',':')), '%Y-%m-%d %H:%i'),
            DATE_ADD($o_date, INTERVAL CAST(o.`hour` AS UNSIGNED) HOUR)
        )
    ";

    $or_start = "
        COALESCE(
            STR_TO_DATE(r.start_time, '%Y-%m-%d %H:%i:%s'),
            STR_TO_DATE(r.start_time, '%Y-%m-%d %H:%i'),
            STR_TO_DATE(r.start_time, '%d.%m.%Y %H:%i:%s'),
            STR_TO_DATE(r.start_time, '%d.%m.%Y %H:%i'),
            STR_TO_DATE(r.start_time, '%m.%d.%Y %r')
        )
    ";

    // Duration parsing
    $durSec = "
        CASE
          WHEN o.`duration` REGEXP '^[0-9]+$'
            THEN CAST(o.`duration` AS UNSIGNED)
          WHEN o.`duration` REGEXP '^[0-9]{1,2}:[0-9]{2}(:[0-9]{2})?$'
            THEN TIME_TO_SEC(STR_TO_DATE(o.`duration`, '%H:%i:%s'))
          ELSE NULL
        END
    ";

    // Process this chunk - using the SAME logic as your main report, now WITH duration grouping
    $sql = "
    WITH oatv_rows AS (
        SELECT 
            o.id, 
            $o_date AS o_date_parsed, 
            $o_spotdt AS spot_dt,
            $durSec AS dur_sec
        FROM oatv o
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
          AND $durSec IS NOT NULL
        ORDER BY o.id
        LIMIT :limit OFFSET :offset
    ),
    joined AS (
        SELECT
            x.spot_dt,
            x.dur_sec,
            (
                SELECT r.over60_on_channel
                FROM overnightreport r
                WHERE
                    x.spot_dt IS NOT NULL
                    AND $or_start IS NOT NULL
                    AND $or_start <= x.spot_dt
                    AND DATE($or_start) BETWEEN :dfrom AND :dto
                ORDER BY $or_start DESC
                LIMIT 1
            ) AS over60_on_channel,
            (
                SELECT r.avg_viewers
                FROM overnightreport r
                WHERE
                    x.spot_dt IS NOT NULL
                    AND $or_start IS NOT NULL
                    AND $or_start <= x.spot_dt
                    AND DATE($or_start) BETWEEN :dfrom AND :dto
                ORDER BY $or_start DESC
                LIMIT 1
            ) AS avg_viewers
        FROM oatv_rows x
    )
    SELECT
        dur_sec,
        COUNT(*) AS spot_count,
        SUM(CASE
                WHEN rtrim(ltrim(COALESCE(over60_on_channel,''))) <> ''
                 AND over60_on_channel IS NOT NULL
            THEN 1 ELSE 0 END) AS matched_spots,
        SUM(CAST(COALESCE(over60_on_channel, 0) AS UNSIGNED)) AS sum_over60,
        SUM(
            CASE
                WHEN avg_viewers IS NULL OR rtrim(ltrim(COALESCE(avg_viewers,''))) = '' THEN 0
                ELSE CAST(avg_viewers AS DECIMAL(18,2))
            END
        ) AS sum_avg_viewers,
        AVG(
            CASE
                WHEN avg_viewers IS NULL OR rtrim(ltrim(COALESCE(avg_viewers,''))) = '' THEN 0
                ELSE CAST(avg_viewers AS DECIMAL(18,2))
            END
        ) AS avg_avg_viewers
    FROM joined
    GROUP BY dur_sec
    ";

    $params = [
        ':dfrom' => $from,
        ':dto' => $to,
        ':offset' => $offset,
        ':limit' => $limit
    ];
    
    if ($campaign !== '(tyhjä)') {
        $params[':campaign'] = $campaign;
    }

    $stmt = $pdo->prepare($sql);
    
    // Bind parameters properly
    $stmt->bindValue(':dfrom', $from, PDO::PARAM_STR);
    $stmt->bindValue(':dto', $to, PDO::PARAM_STR);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    if ($campaign !== '(tyhjä)') {
        $stmt->bindValue(':campaign', $campaign, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate results by duration
    $durationData = [];
    $totalProcessed = 0;
    $totalMatched = 0;
    $totalReach = 0;
    $totalAvgViewers = 0;

    foreach ($results as $row) {
        $durSec = (int)$row['dur_sec'];
        $spotCount = (int)$row['spot_count'];
        $matched = (int)$row['matched_spots'];
        $reach = (int)$row['sum_over60'];
        $avgViewers = (float)$row['sum_avg_viewers'];
        $avgAvg = (float)$row['avg_avg_viewers'];

        if (!isset($durationData[$durSec])) {
            $durationData[$durSec] = [
                'count' => 0,
                'matched' => 0,
                'reach' => 0,
                'avg_viewers_sum' => 0,
                'avg_viewers_avg' => 0,
            ];
        }

        $durationData[$durSec]['count'] += $spotCount;
        $durationData[$durSec]['matched'] += $matched;
        $durationData[$durSec]['reach'] += $reach;
        $durationData[$durSec]['avg_viewers_sum'] += $avgViewers;
        // For average, we'll recalculate later from sum/count

        $totalProcessed += $spotCount;
        $totalMatched += $matched;
        $totalReach += $reach;
        $totalAvgViewers += $avgViewers;
    }

    echo json_encode([
        'success' => true,
        'processed' => $totalProcessed,
        'matched' => $totalMatched,
        'reachSum' => $totalReach,
        'avgViewersSum' => $totalAvgViewers,
        'durationData' => $durationData
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

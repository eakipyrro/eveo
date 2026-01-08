<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

$isAdmin = in_array($_SESSION['role'] ?? '', ['admin'], true);
$debugOn = $isAdmin && (isset($_GET['debug']) && $_GET['debug'] === '1');

// reports.php - OAS-raporttisivu (parametrien valinta)

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/include/db.php';
$pdo = db();

require_once __DIR__ . '/include/audit.php';

// Sivukohtaiset asetukset headerille:
$PAGE_TITLE = 'Raportit';
$REQUIRE_LOGIN = true;
$REQUIRE_PERMISSION = 'view_reports';
$BACK_HREF = 'index.php';

// Otsikko + palkki
require_once __DIR__ . '/include/header.php';

// Ei nÃƒÂ¤ytetÃƒÂ¤ tuloksia ennenkuin valinnat on olemassa ja Painetaan nÃƒÂ¤ppÃƒÂ¤intÃƒÂ¤
$isRun = (isset($_GET['run']) && $_GET['run'] === '1');

// Placeholder tyhjÃƒÂ¤lle account-arvolle
const EMPTY_LABEL = '(tyhjÃƒÂ¤)';

// --- Lue nykyiset valinnat (GET) ---
$selectedAccount = isset($_GET['account']) ? trim($_GET['account']) : '';
$selectedCampaign = isset($_GET['campaign']) ? trim($_GET['campaign']) : '';

// Lomakekenttien oletusarvot (kÃƒÂ¤ytetÃƒÂ¤ÃƒÂ¤n myÃƒÂ¶s auditissa)
$trpFactor = $_GET['trp'] ?? '0.63';
$population = $_GET['pop'] ?? '2109000';

// --- Audit: page view + mittaus ---
$t0 = microtime(true);

// Nykyiset valinnat sessioon vertailua varten (parametrimuutos ilman ajoa)
$currParams = [
    'account' => $selectedAccount,
    'campaign' => $selectedCampaign,
    'start' => $_GET['start'] ?? '',
    'end' => $_GET['end'] ?? '',
    'trp' => (string) $trpFactor,
    'pop' => (string) $population,
    'debug' => $debugOn ? 1 : 0,
    'run' => $isRun ? 1 : 0,
];

// Kirjaa aina sivulle tulo (GET), jotta admin nÃƒÂ¶kee kÃƒÂ¤ytÃƒÂ¶n
audit_log('report_view', [
    'page' => 'reports.php',
    'params' => $currParams,
    'is_run' => (bool) $isRun,
    'role' => $_SESSION['role'] ?? null,
]);

// Jos kÃƒÂ¤yttÃƒÂ¤jÃƒÂ¤ vaihteli valintoja, mutta ei vielÃƒÂ¤ ajanut raporttia -> parametrimuutos
$prevParams = $_SESSION['reports_prev_params'] ?? null;
if (!$isRun && $prevParams && $prevParams !== $currParams) {
    audit_log('report_params_changed', [
        'page' => 'reports.php',
        'from' => $prevParams,
        'to' => $currParams,
    ]);
}
$_SESSION['reports_prev_params'] = $currParams;

// Apufunktio XSS-suojattuun tulostukseen

// --- Hae uniikit accountit ---
$accounts = [];
try {
    $q = $pdo->query("SELECT DISTINCT NULLIF(TRIM(account),'') AS acc FROM oas");
    while ($row = $q->fetch()) {
        $val = $row['acc'];
        if ($val === null || $val === '')
            $val = EMPTY_LABEL;
        $accounts[$val] = true;
    }
    $accounts = array_keys($accounts);
    usort($accounts, function ($a, $b) {
        if ($a === EMPTY_LABEL && $b !== EMPTY_LABEL)
            return 1;
        if ($b === EMPTY_LABEL && $a !== EMPTY_LABEL)
            return -1;
        return strcasecmp($a, $b);
    });
} catch (Throwable $e) {
    $accounts = [];
    audit_log('report_query_failed', [
        'page' => 'reports.php',
        'where' => 'fetch_accounts_distinct',
        'error' => $e->getMessage(),
    ]);
}

// --- Jos account valittu, hae sen kampanjat ---
$campaigns = [];
if ($selectedAccount !== '') {
    try {
        if ($selectedAccount === EMPTY_LABEL) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT NULLIF(TRIM(campaign),'') AS camp
                FROM oas
                WHERE account IS NULL OR TRIM(account) = ''
                ORDER BY camp IS NULL, camp ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT NULLIF(TRIM(campaign),'') AS camp
                FROM oas
                WHERE TRIM(account) = :acc
                ORDER BY camp IS NULL, camp ASC
            ");
            $stmt->execute([':acc' => $selectedAccount]);
        }
        while ($r = $stmt->fetch())
            $campaigns[] = $r['camp'] ?? '';
        $campaigns = array_map(fn($c) => ($c === '' || $c === null) ? EMPTY_LABEL : $c, $campaigns);
        $campaigns = array_values(array_unique($campaigns, SORT_STRING));
    } catch (Throwable $e) {
        $campaigns = [];
        audit_log('report_query_failed', [
            'page' => 'reports.php',
            'where' => 'fetch_campaigns_for_account',
            'acc' => $selectedAccount,
            'error' => $e->getMessage(),
        ]);
    }
}
$minISO = $maxISO = null;
$minDisp = $maxDisp = 'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â';

// --- Jos sekÃƒÂ¤ account ettÃƒÂ¤ campaign on valittu, yliaja pÃƒÂ¤ivÃƒÂ¤t OATV-taulun perusteella ---
if ($selectedAccount !== '' && $selectedCampaign !== '') {
    try {
        $dateExprMin = "
            DATE_FORMAT(
              MIN(
                COALESCE(
                  CASE WHEN `date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(`date`, '%Y-%m-%d') END,
                  STR_TO_DATE(`date`, '%d.%m.%Y'),
                  STR_TO_DATE(`date`, '%e.%c.%Y'),
                  STR_TO_DATE(SUBSTRING_INDEX(`date`, ' ', 1), '%d.%m.%Y'),
                  STR_TO_DATE(SUBSTRING_INDEX(`date`, ' ', 1), '%e.%c.%Y')
                )
              ),
              '%Y-%m-%d'
            )
        ";
        $dateExprMax = "
            DATE_FORMAT(
              MAX(
                COALESCE(
                  CASE WHEN `date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(`date`, '%Y-%m-%d') END,
                  STR_TO_DATE(`date`, '%d.%m.%Y'),
                  STR_TO_DATE(`date`, '%e.%c.%Y'),
                  STR_TO_DATE(SUBSTRING_INDEX(`date`, ' ', 1), '%d.%m.%Y'),
                  STR_TO_DATE(SUBSTRING_INDEX(`date`, ' ', 1), '%e.%c.%Y')
                )
              ),
              '%Y-%m-%d'
            )
        ";

        if ($selectedCampaign === EMPTY_LABEL) {
            $sql = "SELECT $dateExprMin AS min_d, $dateExprMax AS max_d
                    FROM oatv
                    WHERE artist IS NULL OR TRIM(artist) = ''";
            $stmtOatv = $pdo->prepare($sql);
            $stmtOatv->execute();
        } else {
            $sql = "SELECT $dateExprMin AS min_d, $dateExprMax AS max_d
                    FROM oatv
                    WHERE TRIM(artist) = :artist";
            $stmtOatv = $pdo->prepare($sql);
            $stmtOatv->execute([':artist' => $selectedCampaign]);
        }

        if ($rowOatv = $stmtOatv->fetch()) {
            if (!empty($rowOatv['min_d'])) {
                $minISO = $rowOatv['min_d'];
                $minDisp = date('d.m.Y', strtotime($minISO));
            }
            if (!empty($rowOatv['max_d'])) {
                $maxISO = $rowOatv['max_d'];
                $maxDisp = date('d.m.Y', strtotime($maxISO));
            }
        }
    } catch (Throwable $e) { /* ignore */
    }
}

// Lomakekenttien oletusarvot:
$startOverride = $_GET['start'] ?? ($minISO ?? '');
$endOverride = $_GET['end'] ?? ($maxISO ?? '');

// --- Apufunktiot pÃƒÂ¤iville ---
function parse_fi_date($s)
{
    $s = trim((string) $s);
    if ($s === '')
        return null;
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s))
        return $s;
    if (preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', $s, $m))
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    return null;
}
function fi_date($iso)
{
    if (!$iso)
        return '';
    [$y, $m, $d] = explode('-', $iso);
    return sprintf('%02d.%02d.%04d', (int) $d, (int) $m, (int) $y);
}
// Apuri: sekunnit -> HH:MM:SS
if (!function_exists('sec_to_hhmmss')) {
    function sec_to_hhmmss(int $s): string
    {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
    }
}

// --- Poimi lomakkeen arvot raportointiin ---
$account = $_GET['account'] ?? $_POST['account'] ?? '';
$campaign = $_GET['campaign'] ?? $_POST['campaign'] ?? '';
$fromRaw = $_GET['start'] ?? $_POST['start'] ?? '';
$toRaw = $_GET['end'] ?? $_POST['end'] ?? '';

$ovStart = parse_fi_date($fromRaw);
$ovEnd = parse_fi_date($toRaw);
$baseFrom = $minISO ?: null;
$baseTo = $maxISO ?: null;
$effFrom = $baseFrom;
$effTo = $baseTo;

if ($ovStart && $baseFrom && $ovStart > $baseFrom)
    $effFrom = $ovStart;
if ($ovEnd && $baseTo && $ovEnd < $baseTo)
    $effTo = $ovEnd;
if ($effFrom && $baseFrom && $effFrom < $baseFrom)
    $effFrom = $baseFrom;
if ($effTo && $baseTo && $effTo > $baseTo)
    $effTo = $baseTo;

$rangeError = '';
if ($effFrom && $effTo && $effFrom > $effTo) {
    $rangeError = 'AikavÃƒÂ¤li on virheellinen: aloituspÃƒÂ¤ivÃƒÂ¤ on suurempi kuin lopetuspÃƒÂ¤ivÃƒÂ¤.';
}
if ($isRun && $rangeError !== '') {
    audit_log('report_invalid_range', [
        'page' => 'reports.php',
        'account' => $account !== '' ? $account : null,
        'campaign' => $campaign !== '' ? $campaign : null,
        'start_input' => $fromRaw,
        'end_input' => $toRaw,
        'effective_from' => $effFrom,
        'effective_to' => $effTo,
        'error' => $rangeError,
    ]);
}

// --- Laske toteutuneet spotit ---
$playedCount = 0;
if ($isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    if ($campaign === EMPTY_LABEL) {
        $sql = "SELECT COUNT(*) FROM oatv
                WHERE (artist IS NULL OR TRIM(artist) = '')
                  AND COALESCE(
                        STR_TO_DATE(`date`, '%Y-%m-%d'),
                        STR_TO_DATE(`date`, '%d.%m.%Y'),
                        DATE(`date`)
                      ) BETWEEN :dfrom AND :dto";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':dfrom' => $effFrom, ':dto' => $effTo]);
    } else {
        $sql = "SELECT COUNT(*) FROM oatv
                WHERE TRIM(artist) = :campaign
                  AND COALESCE(
                        STR_TO_DATE(`date`, '%Y-%m-%d'),
                        STR_TO_DATE(`date`, '%d.%m.%Y'),
                        DATE(`date`)
                      ) BETWEEN :dfrom AND :dto";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':campaign' => $campaign, ':dfrom' => $effFrom, ':dto' => $effTo]);
    }
    $playedCount = (int) $stmt->fetchColumn();
}

$reach3pSum = 0;
$matchedSpots = 0;
$avgViewersSum = 0.0;
$durationStats = []; // [ duration_sec => ['count' => N, 'avg_viewers_sum' => X, 'avg_viewers_avg' => Y, 'trp' => Z] ]

// Check if we should use pre-calculated results from AJAX
$useSessionData = false;
if (isset($_GET['from_ajax']) && $_GET['from_ajax'] === '1' && isset($_SESSION['report_results'])) {
    $savedResults = $_SESSION['report_results'];
    
    // Verify the results are for the same parameters (within last 5 minutes)
    $paramsMatch = (
        ($savedResults['params']['account'] ?? '') === $selectedAccount &&
        ($savedResults['params']['campaign'] ?? '') === $selectedCampaign &&
        ($savedResults['params']['start'] ?? '') === $startOverride &&
        ($savedResults['params']['end'] ?? '') === $endOverride &&
        (time() - ($savedResults['timestamp'] ?? 0)) < 300 // 5 minutes
    );
    
    if ($paramsMatch) {
        $useSessionData = true;
        // Use pre-calculated values
        $reach3pSum = $savedResults['total_reach'] ?? 0;
        $avgViewersSum = $savedResults['total_avg_viewers'] ?? 0.0;
        $matchedSpots = $savedResults['processed_count'] ?? 0;
        $durationStats = $savedResults['duration_data'] ?? [];
        
        // Calculate missing fields for duration stats (reached_contacts, trp, avg_viewers_avg)
        $populationN = max(0, (int) $population);
        $trpFactorNum = (float) $trpFactor;
        
        foreach ($durationStats as $durSec => &$stats) {
            // Calculate average viewers average
            if (!isset($stats['avg_viewers_avg']) && isset($stats['count']) && $stats['count'] > 0) {
                $stats['avg_viewers_avg'] = $stats['avg_viewers_sum'] / $stats['count'];
            }
            
            // Calculate reached contacts
            if (!isset($stats['reached_contacts'])) {
                $stats['reached_contacts'] = ($stats['avg_viewers_sum'] ?? 0) * $trpFactorNum;
            }
            
            // Calculate TRP
            if (!isset($stats['trp'])) {
                $reachedContacts = $stats['reached_contacts'];
                $stats['trp'] = ($populationN > 0)
                    ? round(100 * ($reachedContacts / $populationN), 2)
                    : 0.00;
            }
        }
        unset($stats); // Break reference
        
        audit_log('report_used_session_data', [
            'page' => 'reports.php',
            'campaign' => $campaign,
            'from' => $effFrom,
            'to' => $effTo,
            'age_seconds' => time() - ($savedResults['timestamp'] ?? 0),
        ]);
    }
    
    // Clear session data after use
    unset($_SESSION['report_results']);
}

// --- Laske Tavoitettu yleisÃƒÂ¶ (3+) ---
// ENSIN: Kokonaislaskenta (kaikki spotit)
// SITTEN: Pituuskohtainen laskenta (vain spotit joilla on duration)

if ($isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo && !$useSessionData) {
    $campaignFilter = ($campaign === EMPTY_LABEL)
        ? "( (o.artist IS NULL OR TRIM(o.artist)='') AND (o.title IS NULL OR TRIM(o.title)='') )"
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
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y')
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
    $or_end = "
        COALESCE(
            STR_TO_DATE(r.end_time, '%Y-%m-%d %H:%i:%s'),
            STR_TO_DATE(r.end_time, '%Y-%m-%d %H:%i'),
            STR_TO_DATE(r.end_time, '%d.%m.%Y %H:%i:%s'),
            STR_TO_DATE(r.end_time, '%d.%m.%Y %H:%i'),
            STR_TO_DATE(r.end_time, '%m.%d.%Y %r')
        )
    ";

    // === KYSELY 1: KOKONAISLASKENTA (kaikki spotit, EI duration-rajausta) ===
    $sqlTotal = "
    WITH oatv_rows AS (
        SELECT o.id, $o_date AS o_date_parsed, $o_spotdt AS spot_dt
        FROM oatv o
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
    ),
    joined AS (
    SELECT
        x.spot_dt,
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
        COUNT(*) AS total_spots,
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
        ) AS sum_avg_viewers
    FROM joined
";

    $params = [':dfrom' => $effFrom, ':dto' => $effTo];
    if ($campaign !== EMPTY_LABEL)
        $params[':campaign'] = $campaign;

    try {
        $stmt = $pdo->prepare($sqlTotal);
        $stmt->execute($params);
        $row = $stmt->fetch();

        $matchedSpots = (int) ($row['matched_spots'] ?? 0);
        $reach3pSum = (int) ($row['sum_over60'] ?? 0);
        $avgViewersSum = (float) ($row['sum_avg_viewers'] ?? 0.0);
    } catch (Throwable $e) {
        audit_log('report_query_failed', [
            'page' => 'reports.php',
            'where' => 'ctereport_total',
            'error' => $e->getMessage(),
        ]);
        $matchedSpots = 0;
        $reach3pSum = 0;
        $avgViewersSum = 0.0;
    }

    // === KYSELY 2: PITUUSKOHTAINEN LASKENTA (vain spotit joilla on duration) ===
    $durSec = "
        CASE
          WHEN o.`duration` REGEXP '^[0-9]+$'
            THEN CAST(o.`duration` AS UNSIGNED)
          WHEN o.`duration` REGEXP '^[0-9]{1,2}:[0-9]{2}(:[0-9]{2})?$'
            THEN TIME_TO_SEC(STR_TO_DATE(o.`duration`, '%H:%i:%s'))
          ELSE NULL
        END
    ";

    $sqlByDuration = "
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
    ORDER BY dur_sec ASC
";

    try {
        $stmt = $pdo->prepare($sqlByDuration);
        $stmt->execute($params);

        $populationN = max(0, (int) $population);
        $trpFactorNum = (float) $trpFactor;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $durSecs = (int) $row['dur_sec'];
            $spotCount = (int) $row['spot_count'];
            $sumAvgViewers = (float) $row['sum_avg_viewers'];
            $avgAvgViewers = (float) $row['avg_avg_viewers'];

            // Calculate reached contacts and TRP for this duration
            $reachedContacts = $sumAvgViewers * $trpFactorNum;
            $trpPct = ($populationN > 0)
                ? round(100 * ($reachedContacts / $populationN), 2)
                : 0.00;

            $durationStats[$durSecs] = [
                'count' => $spotCount,
                'avg_viewers_sum' => $sumAvgViewers,
                'avg_viewers_avg' => $avgAvgViewers,
                'reached_contacts' => $reachedContacts,
                'trp' => $trpPct,
            ];
        }
    } catch (Throwable $e) {
        audit_log('report_query_failed', [
            'page' => 'reports.php',
            'where' => 'ctereport_join_by_duration',
            'error' => $e->getMessage(),
        ]);
        $durationStats = [];
    }
}

$diagCounts = ['artist_eq' => 0, 'title_eq' => 0, 'artist_like' => 0, 'title_like' => 0];

if ($isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    $baseDateCond = "
        COALESCE(
            CASE WHEN o.`date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(o.`date`, '%Y-%m-%d') END,
            STR_TO_DATE(o.`date`, '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
        ) BETWEEN :dfrom AND :dto
    ";

    if ($campaign !== EMPTY_LABEL) {
        $diagSqls = [
            'artist_eq' => "SELECT COUNT(*) FROM oatv o WHERE $baseDateCond AND LOWER(TRIM(o.artist)) = LOWER(TRIM(:c))",
            'title_eq' => "SELECT COUNT(*) FROM oatv o WHERE $baseDateCond AND LOWER(TRIM(o.title )) = LOWER(TRIM(:c))",
            'artist_like' => "SELECT COUNT(*) FROM oatv o WHERE $baseDateCond AND LOWER(o.artist) LIKE CONCAT('%', LOWER(:c), '%')",
            'title_like' => "SELECT COUNT(*) FROM oatv o WHERE $baseDateCond AND LOWER(o.title ) LIKE CONCAT('%', LOWER(:c), '%')",
        ];
        foreach ($diagSqls as $k => $qsql) {
            $st = $pdo->prepare($qsql);
            $st->execute([':dfrom' => $effFrom, ':dto' => $effTo, ':c' => $campaign]);
            $diagCounts[$k] = (int) $st->fetchColumn();
        }
    }
}
// Sama kampanjafiltteri kuin pÃƒÂ¤ÃƒÂ¤raportissa
$campaignFilter = ($campaign === EMPTY_LABEL)
    ? "(o.artist IS NULL OR TRIM(o.artist) = '')"
    : "TRIM(o.artist) = :campaign";

// Sama pÃƒÂ¤ivÃƒÂ¤mÃƒÂ¤ÃƒÂ¤rÃƒÂ¤ OATV:lle (sis. %e.%c.%Y)
$o_date = "
    COALESCE(
        CASE WHEN o.`date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(o.`date`, '%Y-%m-%d') END,
        STR_TO_DATE(o.`date`, '%d.%m.%Y'),
        STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
        STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
    )
";

// Sama spotin aikaleima OATV:lle
$o_spotdt = "
    COALESCE(
        STR_TO_DATE(CONCAT(DATE_FORMAT($o_date, '%Y-%m-%d'), ' ', o.`time`), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(CONCAT(DATE_FORMAT($o_date, '%Y-%m-%d'), ' ', o.`time`), '%Y-%m-%d %H:%i'),
        STR_TO_DATE(CONCAT(DATE_FORMAT($o_date, '%Y-%m-%d'), ' ', REPLACE(o.`time`,'.',':')), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(CONCAT(DATE_FORMAT($o_date, '%Y-%m-%d'), ' ', REPLACE(o.`time`,'.',':')), '%Y-%m-%d %H:%i'),
        DATE_ADD($o_date, INTERVAL CAST(o.`hour` AS SIGNED) HOUR)
    )
";

// Sama overnightin parseri (tukee 12.31.2024 2:00:00 PM)
$or_start = "
    COALESCE(
        STR_TO_DATE(r.start_time, '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(r.start_time, '%Y-%m-%d %H:%i'),
        STR_TO_DATE(r.start_time, '%d.%m.%Y %H:%i:%s'),
        STR_TO_DATE(r.start_time, '%d.%m.%Y %H:%i'),
        STR_TO_DATE(r.start_time, '%m.%d.%Y %r')
    )
";
$or_end = "
    COALESCE(
        STR_TO_DATE(r.end_time, '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(r.end_time, '%Y-%m-%d %H:%i'),
        STR_TO_DATE(r.end_time, '%d.%m.%Y %H:%i:%s'),
        STR_TO_DATE(r.end_time, '%d.%m.%Y %H:%i'),
        STR_TO_DATE(r.end_time, '%m.%d.%Y %r')
    )
";

// --- Debug-listaus (ei ylikirjoita laskentaa) ---
$debugRows = [];
$dbg_oatv_in = 0;
$dbg_join_hit = 0;
$spotProbe = [];

if ($isRun && $debugOn && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    $params = [':dfrom' => $effFrom, ':dto' => $effTo];
    if ($campaign !== EMPTY_LABEL)
        $params[':campaign'] = $campaign;

    // 1) Kuinka monta OATV-riviÃƒÂ¤ syÃƒÂ¶tetÃƒÂ¤ÃƒÂ¤n lÃƒÂ¤pi (SAMA rajaus kuin pÃƒÂ¤ÃƒÂ¤raportissa)
    $sqlIn = "
        SELECT COUNT(*)
        FROM oatv o
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
    ";
    $stIn = $pdo->prepare($sqlIn);
    $stIn->execute($params);
    $dbg_oatv_in = (int) $stIn->fetchColumn();

    // 2) Varsinainen debug-listaus - TÃƒâ€žSMÃƒâ€žLLEEN sama parsinta & join kuin pÃƒÂ¤ÃƒÂ¤raportissa
    $sqlDbg = "
    SELECT
      o.id AS oatv_id,
      DATE_FORMAT($o_spotdt, '%Y-%m-%d %H:%i:%s') AS spot_datetime,

      -- Edellisen ohjelman nimi
      (
        SELECT COALESCE(r.program, '')
        FROM overnightreport r
        WHERE
          $or_start IS NOT NULL
          AND $or_start <= $o_spotdt
          AND DATE($or_start) BETWEEN :dfrom AND :dto
        ORDER BY $or_start DESC
        LIMIT 1
      ) AS program_name,

      -- Edellisen ohjelman alku
      (
        SELECT DATE_FORMAT($or_start, '%Y-%m-%d %H:%i:%s')
        FROM overnightreport r
        WHERE
          $or_start IS NOT NULL
          AND $or_start <= $o_spotdt
          AND DATE($or_start) BETWEEN :dfrom AND :dto
        ORDER BY $or_start DESC
        LIMIT 1
      ) AS r_start_parsed,

      -- Edellisen ohjelman loppu
      (
        SELECT DATE_FORMAT($or_end, '%Y-%m-%d %H:%i:%s')
        FROM overnightreport r
        WHERE
          $or_start IS NOT NULL
          AND $or_start <= $o_spotdt
          AND DATE($or_start) BETWEEN :dfrom AND :dto
        ORDER BY $or_start DESC
        LIMIT 1
      ) AS r_end_parsed,

      -- 3+ ja avg_viewers samasta edellisestÃƒÆ’Ã‚Â¤ ohjelmasta
      (
        SELECT r.over60_on_channel
        FROM overnightreport r
        WHERE
          $o_spotdt IS NOT NULL
          AND $or_start IS NOT NULL
          AND $or_start <= $o_spotdt
          AND DATE($or_start) BETWEEN :dfrom AND :dto
        ORDER BY
          ($or_end > $o_spotdt) DESC,
          $or_start DESC
        LIMIT 1
      ) AS over60_on_channel,

      (
        SELECT r.avg_viewers
        FROM overnightreport r
        WHERE
          $o_spotdt IS NOT NULL
          AND $or_start IS NOT NULL
          AND $or_start <= $o_spotdt
          AND DATE($or_start) BETWEEN :dfrom AND :dto
        ORDER BY
          ($or_end > $o_spotdt) DESC,
          $or_start DESC
        LIMIT 1
      ) AS avg_viewers

    FROM oatv o
    WHERE $campaignFilter
      AND $o_date BETWEEN :dfrom AND :dto
    ORDER BY o.id ASC
    LIMIT 2000
";

    try {
        $stDbg = $pdo->prepare($sqlDbg);
        $stDbg->execute($params);
        while ($r = $stDbg->fetch(PDO::FETCH_ASSOC)) {
            $debugRows[] = $r;
        }
        // JOIN-osumia = rivit, joissa r_start_parsed EI ole NULL
        foreach ($debugRows as $r) {
            if (!empty($r['r_start_parsed'])) {
                $dbg_join_hit++;
            }
        }

        // --- POISTETTU: Debug-rivien summan laskenta ja ylikirjoitus ---
        // Aiemmin tÃƒÂ¤ssÃƒÂ¤ laskettiin $avgViewersSumFromDebug ja ylikirjoitettiin
        // pÃƒÂ¤ÃƒÂ¤laskenta, mikÃƒÆ’Ã‚Â¤ aiheutti eron. Nyt kÃƒÂ¤ytetÃƒÂ¤ÃƒÂ¤n aina pÃƒÂ¤ÃƒÂ¤laskentaa.

    } catch (Throwable $e) {
        $debugRows = [];
        if ($isAdmin && $debugOn) {
            echo '<div class="error-box">Debug-kysely epÃƒÂ¤onnistui: ' . h($e->getMessage()) . '</div>';
        }
    }
}

if ($debugOn && $isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    audit_log('report_debug_stats', [
        'page' => 'reports.php',
        'oatv_in' => (int) $dbg_oatv_in,
        'join_hits' => (int) $dbg_join_hit,
        'probe_count' => is_array($spotProbe) ? count($spotProbe) : 0,
        'debug_rows' => (int) count($debugRows),
    ]);
}

// --- Spotin pituudet (kaikki, yleisimmÃƒÂ¤stÃƒÂ¤ harvinaisimpaan) ---
$spotDurations = []; // [ [sec => 20, cnt => 123], ... ]
if ($isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    $campaignFilter = ($campaign === EMPTY_LABEL)
        ? "( (o.artist IS NULL OR TRIM(o.artist)='') AND (o.title IS NULL OR TRIM(o.title)='') )"
        : "(
         LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.artist,'')), ' +', ' ')) = LOWER(REGEXP_REPLACE(:campaign, ' +', ' '))
      OR LOWER(REGEXP_REPLACE(TRIM(COALESCE(o.title ,'')), ' +', ' ')) = LOWER(REGEXP_REPLACE(:campaign, ' +', ' '))
      OR LOWER(COALESCE(o.artist,'')) LIKE CONCAT('%', LOWER(:campaign), '%')
      OR LOWER(COALESCE(o.title ,'')) LIKE CONCAT('%', LOWER(:campaign), '%')
    )";

    // Sama date/time-parsinta kuin muuallakin:
    $o_date = "
        COALESCE(
            STR_TO_DATE(o.`date`, '%Y-%m-%d'),
            STR_TO_DATE(o.`date`, '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
        )
    ";

    // Tulkitaan pituus: jos o.duration on sekunteina, CAST riittÃƒÂ¤ÃƒÂ¤.
    // Jos se on '00:00:20'-muodossa, parsitaan TIME_TO_SEC.
    $durSec = "
        CASE
          WHEN o.`duration` REGEXP '^[0-9]+$'
            THEN CAST(o.`duration` AS UNSIGNED)
          WHEN o.`duration` REGEXP '^[0-9]{1,2}:[0-9]{2}(:[0-9]{2})?$'
            THEN TIME_TO_SEC(STR_TO_DATE(o.`duration`, '%H:%i:%s'))
          ELSE NULL
        END
    ";

    $sql = "
        SELECT
          $durSec AS dur_sec,
          COUNT(*) AS cnt
        FROM oatv o
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
          AND $durSec IS NOT NULL
        GROUP BY dur_sec
        ORDER BY cnt DESC, dur_sec ASC
    ";

    $params = [':dfrom' => $effFrom, ':dto' => $effTo];
    if ($campaign !== EMPTY_LABEL)
        $params[':campaign'] = $campaign;

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $spotDurations[] = [
                'sec' => (int) $r['dur_sec'],
                'cnt' => (int) $r['cnt'],
            ];
        }
    } catch (Throwable $e) {
        // optional: error_log('Duration fetch failed: '.$e->getMessage());
    }
}

// Muodosta esitysteksti
$spotLengthDisplay = 'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â';
if (!empty($spotDurations)) {
    $parts = [];
    foreach ($spotDurations as $d) {
        $parts[] = sec_to_hhmmss($d['sec']) . ' (' . number_format($d['cnt'], 0, ',', ' ') . ')';
    }
    $spotLengthDisplay = implode(', ', $parts);
} else {
    // fallback: nÃƒÂ¤ytÃƒÂ¤ aiempi oletus tai viiva
    // $spotLengthDisplay = '00:00:20';
}

// --- KÃƒÂ¤yttÃƒÂ¤jÃƒÂ¤n muokattavat kentÃƒÂ¤t (sÃƒÂ¤ilyvÃƒÂ¤t GET:ssÃƒÂ¤) ---
$soldSpots = isset($_GET['sold_spots']) ? trim($_GET['sold_spots']) : '';
$soldTrp = isset($_GET['sold_trp']) ? trim($_GET['sold_trp']) : '';

// --- TRP (35-64): Kerroin * Tavoitettu yleisÃƒÂ¶ (3+) ---
$populationN = max(0, (int) $population);
$trp3564_pct_3p = ($populationN > 0)
    ? round(100 * (($trpFactor * (float) $reach3pSum) / $populationN), 2)
    : 0.00;
$trp3564_pct_avg = ($populationN > 0)
    ? round(100 * (($trpFactor * (float) $avgViewersSum) / $populationN), 2)
    : 0.00;

$reachedContacts = $avgViewersSum * $trpFactor;

/* === AUDIT: report_generated (onnistunut ajo) === */
if ($isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    $durationMs = (int) round((microtime(true) - $t0) * 1000);

    audit_log('report_generated', [
        'page' => 'reports.php',
        'account' => $account !== '' ? $account : null,
        'campaign' => $campaign !== '' ? $campaign : null,

        // Datafound / effective range
        'date_min_found' => $minISO,
        'date_max_found' => $maxISO,
        'effective_from' => $effFrom,
        'effective_to' => $effTo,

        // Tulokset
        'played_spots' => (int) $playedCount,
        'matched_spots' => (int) $matchedSpots,
        'reach_sum3p' => (int) $reach3pSum,
        'avg_viewers_sum' => (float) $avgViewersSum,

        // Parametrit
        'trp_factor' => (float) $trpFactor,
        'population' => (int) $population,
        'trp3564_pct_3p' => (float) $trp3564_pct_3p,
        'trp3564_pct_avg' => (float) $trp3564_pct_avg,
        'sold_spots' => ($soldSpots !== '' ? (int) $soldSpots : null),
        'sold_trp' => ($soldTrp !== '' ? (float) $soldTrp : null),

        // YmpÃƒÆ’Ã‚Â¤ristÃƒÆ’Ã‚Â¶
        'debug' => (bool) $debugOn,
        'duration_ms' => $durationMs,
        'role' => $_SESSION['role'] ?? null,
    ]);
}

if ($debugOn) {
    audit_log('report_debug_enabled', [
        'page' => 'reports.php',
        'params' => $currParams,
    ]);
}

// Esitysmuotoja
$spotLength = '00:00:20';
?>
<link rel="stylesheet" href="reports_styles.css">

<form id="filtersForm" method="get" action="">
    <input type="hidden" name="run" value="0">
    <div class="panel">
        <div class="head">Valitse asetukset:</div>

        <div class="row">
            <div class="label">Mainostaja:</div>
            <div>
                <select name="account">
                    <option value="">- Valitse -</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= h($acc) ?>" <?= $selectedAccount === $acc ? 'selected' : '' ?>>
                            <?= h($acc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="label">Mainoskampanjan nimi:</div>
            <div>
                <select name="campaign" <?= $selectedAccount === '' ? 'disabled' : '' ?>>
                    <option value="">-
                        <?= $selectedAccount === '' ? 'Valitse ensin mainostaja' : 'Kaikki kampanjat' ?>
                        -</option>
                    <?php foreach ($campaigns as $camp): ?>
                        <option value="<?= h($camp) ?>" <?= $selectedCampaign === $camp ? 'selected' : '' ?>>
                            <?= h($camp) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($selectedAccount !== '' && $selectedCampaign !== ''): ?>
            <div class="subhead">Tiedolla lÃƒÂ¶ytyi kampanja, joka on:</div>
            <div class="two">
                <div class="row">
                    <div class="label">-aloitettu:</div>
                    <div><?= h($minDisp) ?></div>
                </div>
                <div class="row">
                    <div class="label">-lopetettu:</div>
                    <div><?= h($maxDisp) ?></div>
                </div>
            </div>

            <div class="subhead">Jos kuitenkin haluat rajata tuloksia ajan mukaan;</div>
            <div class="two">
                <div class="row">
                    <div class="label">Valitse uusi aloituspÃƒÂ¤ivÃƒÂ¤:</div>
                    <div><input type="date" name="start" value="<?= h($startOverride) ?>"></div>
                </div>
                <div class="row">
                    <div class="label">Valitse uusi lopetuspÃƒÂ¤ivÃƒÂ¤:</div>
                    <div><input type="date" name="end" value="<?= h($endOverride) ?>"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="label">Kerroin (TRP:ia varten):</div>
            <div><input type="number" step="0.01" min="0" name="trp" value="<?= h($trpFactor) ?>"></div>
        </div>

        <div class="row">
            <div class="label">Suomen vÃƒÂ¤kiluku:</div>
            <div><input type="number" step="1" min="0" name="pop" value="<?= h($population) ?>"></div>
        </div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="row">
            <div class="label">Debug (admin)</div>
            <div>
                <label style="display:inline-flex;gap:8px;align-items:center;">
                    <input type="checkbox" name="debug" value="1" <?= $debugOn ? 'checked' : '' ?>>
                    <span>NÃƒÂ¤ytÃƒÂ¤ kaikki spotin osumat (debug-modal)</span>
                </label>
            </div>
        </div>
    <?php endif; ?>

    <div class="controls">
        <div>
            <button type="submit" name="run" value="1" class="primary">Laske TRP</button>
            <button type="button" onclick="window.location.href='reports.php'">TyhjennÃƒÂ¤</button>
        </div>
        <!-- Progress indicator with bar -->
        <div class="progress-container" aria-live="polite">
            <div class="progress-bar-wrapper">
                <div class="progress-bar-fill" id="progressFill"></div>
            </div>
            <div class="progress-text" id="progressText">Valmistellaan...</div>
        </div>
    </div>
</form>

<?php if ($isRun && $rangeError): ?>
    <div class="error-box"><?= h($rangeError) ?></div>
<?php endif; ?>
<?php
// === DEBUG PROBE: listaa 10 parsittua spot-aikaleimaa ilman joinia ===
$spotProbe = [];
if ($debugOn && $effFrom && $effTo && $campaign !== '') {
    $params = [':dfrom' => $effFrom, ':dto' => $effTo];
    if ($campaign !== EMPTY_LABEL)
        $params[':campaign'] = $campaign;

    $sqlProbe = "
      SELECT
        o.id,
        o.`date` AS date_raw,
        o.`time` AS time_raw,
        DATE_FORMAT($o_spotdt, '%Y-%m-%d %H:%i:%s') AS spot_dt
      FROM oatv o
      WHERE $campaignFilter
        AND $o_date BETWEEN :dfrom AND :dto
      ORDER BY o.id ASC
      LIMIT 10
    ";
    try {
        $stPr = $pdo->prepare($sqlProbe);
        $stPr->execute($params);
        $spotProbe = $stPr->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($isAdmin && $debugOn) {
            echo '<div class="error-box">Probe epÃƒÂ¤onnistui: ' . h($e->getMessage()) . '</div>';
            audit_log('report_query_failed', [
                'page' => 'reports.php',
                'where' => 'probe_spot_dt',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
?>

<?php if ($debugOn): ?>
    <div style="margin:8px 0;color:var(--muted);">
        OATV sisÃƒÆ’Ã‚Â¤ÃƒÆ’Ã‚Â¤n: <?= (int) $dbg_oatv_in ?> riviÃƒÆ’Ã‚Â¤ Ãƒâ€šÃ‚Â· JOIN-osumia: <?= (int) $dbg_join_hit ?> riviÃƒÆ’Ã‚Â¤
    </div>
    <?php if ($debugOn && !empty($spotProbe)): ?>
        <div class="h-scroll" style="margin:8px 0;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:6px 8px;">oatv_id</th>
                        <th style="text-align:left;padding:6px 8px;">date_raw</th>
                        <th style="text-align:left;padding:6px 8px;">time_raw</th>
                        <th style="text-align:left;padding:6px 8px;">spot_dt (parsed)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spotProbe as $r): ?>
                        <tr>
                            <td style="padding:6px 8px;"><?= h($r['id']) ?></td>
                            <td style="padding:6px 8px;"><?= h($r['date_raw']) ?></td>
                            <td style="padding:6px 8px;"><?= h($r['time_raw']) ?></td>
                            <td style="padding:6px 8px;"><?= h($r['spot_dt']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin:8px 0;">
        <button type="button" class="btn" id="btnOpenDebug" <?= empty($debugRows) ? 'disabled' : '' ?>>
            NÃƒÂ¤ytÃƒÂ¤ debug-rivit (<?= count($debugRows) ?>)
        </button>
    </div>
    <?php if ($isRun && !$rangeError && $campaign !== '' && array_sum($diagCounts) === 0): {
                audit_log('report_campaign_not_found', [
                    'page' => 'reports.php',
                    'campaign' => $campaign,
                    'from' => $effFrom,
                    'to' => $effTo,
                ]);
            } ?>
        <div class="error-box">
            Valittu kampanjanimi "<?= h($campaign) ?>"" ei lÃƒÂ¶ydy OATV:stÃƒÂ¤
            (artist/title) valitulla aikavÃƒÂ¤lillÃƒÂ¤. Kokeile hakua vÃƒÂ¤ljemmin
            (LIKE on jo kÃƒÂ¤ytÃƒÂ¶ssÃƒÂ¤) tai varmista, kÃƒÂ¤ytetÃƒÂ¤ÃƒÂ¤nkÃƒÂ¶ OATV:ssÃƒÂ¤ eri nimeÃƒÂ¤.
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($isRun && !$rangeError): ?>
    <!-- Taulukko on oma pikalomakkeensa, jotta muokattavat kentÃƒÂ¤t saa talteen ilman ylÃƒÂ¤osan valintojen katoamista -->
    <form method="get" action="">
        <!-- kuljeta valinnat mukana -->
        <input type="hidden" name="run" value="1">
        <input type="hidden" name="account" value="<?= h($selectedAccount) ?>">
        <input type="hidden" name="campaign" value="<?= h($selectedCampaign) ?>">
        <input type="hidden" name="start" value="<?= h($startOverride) ?>">
        <input type="hidden" name="end" value="<?= h($endOverride) ?>">
        <input type="hidden" name="trp" value="<?= h($trpFactor) ?>">
        <input type="hidden" name="pop" value="<?= h($population) ?>">

        <div class="report-summary" style="max-width:1100px;margin-top:18px;">

            <!-- LeveÃƒÂ¤ taulukko: nÃƒÂ¤kyy desktopilla / isolla nytÃƒÂ¶llÃƒÂ¤, ja mobiilissa sivuttaisvieritys toimii -->
            <div class="h-scroll">
                <table class="table table-bordered" style="width:100%; border-collapse:collapse;">
                    <tbody>
                        <tr>
                            <td><strong>Mainostaja</strong></td>
                            <td><?= h($account) ?></td>
                            <td><strong>Myydyt spotit (kpl)</strong></td>
                            <td>
                                <input type="number" name="sold_spots" step="1" min="0" value="<?= h($soldSpots) ?>"
                                    placeholder="SyÃƒÂ¶tÃƒÂ¤ mÃƒÂ¤ÃƒÂ¤rÃƒÂ¤">
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Mainoskampanjan nimi</strong></td>
                            <td><?= h($campaign) ?></td>
                            <td><strong>Myyty TRP (kpl)</strong></td>
                            <td>
                                <input type="number" name="sold_trp" step="0.01" min="0" value="<?= h($soldTrp) ?>"
                                    placeholder="SyÃƒÂ¶tÃƒÂ¤ TRP">
                            </td>
                        </tr>
                        <tr>
                            <td colspan="4" class="spacer-row"></td>
                        </tr>
                        <tr>
                            <td><strong>Raportti alkaen</strong></td>
                            <td><?= h(fi_date($effFrom)) ?></td>
                            <td><strong>Toteutuneet spotit (kpl)</strong></td>
                            <td><?= number_format($playedCount, 0, ',', ' ') ?></td>
                        </tr>
                        <tr>
                            <td><strong>Raportti loppuen</strong></td>
                            <td><?= h(fi_date($effTo)) ?></td>
                            <td><strong>KeskikatsojamÃƒÂ¤ÃƒÂ¤rÃƒÂ¤ (summa)</strong></td>
                            <td><?= number_format($avgViewersSum, 0, ',', ' ') ?></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td></td>
                            <td><strong>Saavutetut kontaktit</strong></td>
                            <td><?= number_format($reachedContacts, 0, ',', ' ') ?></td>
                        </tr>
                        <tr>
                        <tr>
                            <td colspan="4" class="spacer-row"></td>
                        </tr>
                        <tr>
                            <td colspan="4" style="background: var(--panel-2); padding: 10px 12px; font-weight: 700;">
                                Spotin pituuskohtaiset tilastot
                            </td>
                        </tr>
                        <?php if (!empty($durationStats)): ?>
                            <tr>
                                <td><strong>Pituus</strong></td>
                                <td><strong>Spotit (kpl)</strong></td>
                                <td><strong>KokonaiskatsojamÃƒÂ¤ÃƒÂ¤rÃƒÂ¤</strong></td>
                                <td><strong>KeskikatsojamÃƒÂ¤ÃƒÂ¤rÃƒÂ¤</strong></td>
                            </tr>
                            <?php foreach ($durationStats as $durSec => $stats): ?>
                                <tr>
                                    <td><?= h(sec_to_hhmmss($durSec)) ?></td>
                                    <td><?= number_format($stats['count'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($stats['avg_viewers_sum'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($stats['avg_viewers_avg'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4" class="spacer-row"></td>
                            </tr>
                            <tr>
                                <td><strong>Pituus</strong></td>
                                <td><strong>Saavutetut kontaktit</strong></td>
                                <td><strong>TRP (35-64)</strong></td>
                                <td></td>
                            </tr>
                            <?php foreach ($durationStats as $durSec => $stats): ?>
                                <tr>
                                    <td><?= h(sec_to_hhmmss($durSec)) ?></td>
                                    <td><?= number_format($stats['reached_contacts'], 0, ',', ' ') ?></td>
                                    <td><?= number_format($stats['trp'], 2, ',', ' ') ?> %</td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px; color: var(--muted);">
                                    Ei pituuskohtaisia tilastoja saatavilla
                                </td>
                            </tr>
                        <?php endif; ?>
                        <td><strong>Spotin pituus</strong></td>
                        <td><?= h($spotLengthDisplay) ?></td>
                        <td><strong>TRP (35-64)</strong></td>
                        <td><?= number_format($trp3564_pct_avg, 2, ',', ' ') ?> </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobiilikortit: nÃƒÆ’Ã‚Â¤kyy vain alle 640px -->
            <div class="report-cards">
                <div class="report-card">
                    <div class="row">
                        <div class="label">Mainostaja</div>
                        <div class="value"><?= h($account) ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Mainoskampanjan nimi</div>
                        <div class="value"><?= h($campaign) ?></div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="row">
                        <div class="label">Myydyt spotit (kpl)</div>
                        <div class="value"><input type="number" name="sold_spots" step="1" min="0"
                                value="<?= h($soldSpots) ?>" placeholder="SyÃƒÆ’Ã‚Â¶tÃƒÆ’Ã‚Â¤ mÃƒÆ’Ã‚Â¤ÃƒÆ’Ã‚Â¤rÃƒÆ’Ã‚Â¤"></div>
                    </div>
                    <div class="row">
                        <div class="label">Myyty TRP (kpl)</div>
                        <div class="value"><input type="number" name="sold_trp" step="0.01" min="0"
                                value="<?= h($soldTrp) ?>" placeholder="SyÃƒÂ¶tÃƒÂ¤ TRP"></div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="row">
                        <div class="label">Raportti alkaen</div>
                        <div class="value"><?= h(fi_date($effFrom)) ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Raportti loppuen</div>
                        <div class="value"><?= h(fi_date($effTo)) ?></div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="row">
                        <div class="label">Spotin pituus</div>
                        <div class="value"><?= h($spotLengthDisplay) ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Toteutuneet spotit (kpl)</div>
                        <div class="value"><?= number_format($playedCount, 0, ',', ' ') ?></div>
                    </div>
                    <div class="row">
                        <!--                        <div class="label">Tavoitettu yleisÃƒÂ¶ (3+)</div>
                        <div class="value"><?= number_format((int) $reach3pSum, 0, ',', ' ') ?>
                        </div> -->
                        <div class="row">
                            <div class="label">KeskikatsojamÃƒÂ¤ÃƒÂ¤rÃƒÂ¤ (summa)</div>
                            <div class="value"><?= number_format($avgViewersSum, 0, ',', ' ') ?></div>
                        </div>
                        <!--                        <div class="row">
                            <div class="label">TRP (35-64)</div>
                            <div class="value"><?= number_format($trp3564_pct_3p, 2, ',', ' ') ?> %</div>
                        </div> -->
                        <div class="row">
                            <div class="label">TRP (35-64), avg_viewers</div>
                            <div class="value"><?= number_format($trp3564_pct_avg, 2, ',', ' ') ?> %</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($durationStats)): ?>
                    <div class="report-card">
                        <div class="row">
                            <div class="label" style="font-size: 14px; font-weight: 700; color: var(--text);">Spotin
                                pituuskohtaiset tilastot</div>
                        </div>
                    </div>

                    <?php foreach ($durationStats as $durSec => $stats): ?>
                        <div class="report-card">
                            <div class="row">
                                <div class="label">Pituus</div>
                                <div class="value"><?= h(sec_to_hhmmss($durSec)) ?></div>
                            </div>
                            <div class="row">
                                <div class="label">Spotit (kpl)</div>
                                <div class="value"><?= number_format($stats['count'], 0, ',', ' ') ?></div>
                            </div>
                            <div class="row">
                                <div class="label">KokonaiskatsojamÃƒÂ¤ÃƒÂ¤rÃƒÂ¤</div>
                                <div class="value"><?= number_format($stats['avg_viewers_sum'], 0, ',', ' ') ?></div>
                            </div>
                            <div class="row">
                                <div class="label">KeskikatsojamÃƒÂ¤ÃƒÂ¤rÃƒÂ¤</div>
                                <div class="value"><?= number_format($stats['avg_viewers_avg'], 0, ',', ' ') ?></div>
                            </div>
                            <div class="row">
                                <div class="label">Saavutetut kontaktit</div>
                                <div class="value"><?= number_format($stats['reached_contacts'], 0, ',', ' ') ?></div>
                            </div>
                            <div class="row">
                                <div class="label">TRP (35-64)</div>
                                <div class="value"><?= number_format($stats['trp'], 2, ',', ' ') ?> %</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- <div class="controls" style="justify-content:flex-end;max-width:1100px;">
            <button type="submit" class="primary">PÃƒÆ’Ã‚Â¤ivitÃƒÆ’Ã‚Â¤ nÃƒÆ’Ã‚Â¤mÃƒÆ’Ã‚Â¤ kentÃƒÆ’Ã‚Â¤t</button>
        </div> -->
    </form>

    <?php
    // --- OATV peek (admin debug) ---
// NÃƒÆ’Ã‚Â¤yttÃƒÆ’Ã‚Â¤ÃƒÆ’Ã‚Â¤ 20 viimeisintÃƒÆ’Ã‚Â¤ OATV-riviÃƒÆ’Ã‚Â¤ valitulta aikavÃƒÆ’Ã‚Â¤liltÃƒÆ’Ã‚Â¤,
// jotta nÃƒÆ’Ã‚Â¤et millÃƒÆ’Ã‚Â¤ nimillÃƒÆ’Ã‚Â¤ kampanja oikeasti esiintyy.
    $peekRows = [];
    if ($debugOn && $effFrom && $effTo) {
        try {
            $sqlPeek = "
          SELECT o.id, o.channel, o.artist, o.title, o.`date`, o.`time`, o.`duration`, o.`hour`
          FROM oatv o
          WHERE
            COALESCE(
              CASE WHEN o.`date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'
                   THEN STR_TO_DATE(o.`date`, '%Y-%m-%d') END,
              STR_TO_DATE(o.`date`, '%d.%m.%Y'),
              STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y'),
              STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%e.%c.%Y')
            ) BETWEEN :dfrom AND :dto
          ORDER BY o.id DESC
          LIMIT 20
        ";
            $st = $pdo->prepare($sqlPeek);
            $st->execute([':dfrom' => $effFrom, ':dto' => $effTo]);
            $peekRows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $peekRows = [];
            audit_log('report_query_failed', [
                'page' => 'reports.php',
                'where' => 'peek_oatv_recent',
                'error' => $e->getMessage(),
            ]);
        }
    }
    ?>

    <?php if ($debugOn): ?>
        <div id="debugModal" class="modal" aria-hidden="true">
            <div class="modal-backdrop"></div>
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="dbgTitle">
                <div class="modal-header">
                    <h3 id="dbgTitle">Debug - spotin kaikki osumat</h3>
                    <button type="button" class="modal-close" id="btnCloseDebug" aria-label="Sulje">ÃƒÆ’Ã¢â‚¬â€</button>
                </div>
                <div class="modal-body">
                    <?php if (empty($debugRows)): ?>
                        <p>Ei osumia.</p>
                    <?php else: ?>
                        <div class="h-scroll">
                            <table style="width:100%;border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th style="text-align:left;padding:6px 8px;">OATV ID</th>
                                        <th style="text-align:left;padding:6px 8px;">Spot datetime</th>
                                        <th style="text-align:left;padding:6px 8px;">Ohjelma</th>
                                        <th style="text-align:left;padding:6px 8px;">OR start</th>
                                        <th style="text-align:left;padding:6px 8px;">OR end</th>
                                        <th style="text-align:right;padding:6px 8px;">3+ (over60_on_channel)</th>
                                        <th style="text-align:right;padding:6px 8px;">avg_viewers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($debugRows as $d): ?>
                                        <tr>
                                            <td style="padding:6px 8px;"><?= h($d['oatv_id']) ?></td>
                                            <td style="padding:6px 8px;"><?= h($d['spot_datetime']) ?></td>
                                            <td style="padding:6px 8px;"><?= h($d['program_name']) ?></td>
                                            <td style="padding:6px 8px;"><?= h($d['r_start_parsed']) ?></td>
                                            <td style="padding:6px 8px;"><?= h($d['r_end_parsed']) ?></td>
                                            <td style="padding:6px 8px;text-align:right;"><?= h($d['over60_on_channel']) ?></td>
                                            <td style="padding:6px 8px;text-align:right;"><?= h($d['avg_viewers']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p style="margin-top:8px;color:var(--muted);">
                            NÃƒÂ¤ytetÃƒÂ¤ÃƒÂ¤n enintÃƒÂ¤ÃƒÂ¤n 5000 riviÃƒÂ¤. Suodata tarkemmin, jos tarvitset tÃƒÂ¤ydellisen listan.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($debugOn && !empty($peekRows)): ?>
            <div class="h-scroll" style="margin-top:8px;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:6px 8px;">id</th>
                            <th style="text-align:left;padding:6px 8px;">channel</th>
                            <th style="text-align:left;padding:6px 8px;">artist</th>
                            <th style="text-align:left;padding:6px 8px;">title</th>
                            <th style="text-align:left;padding:6px 8px;">date</th>
                            <th style="text-align:left;padding:6px 8px;">time</th>
                            <th style="text-align:left;padding:6px 8px;">duration</th>
                            <th style="text-align:left;padding:6px 8px;">hour</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($peekRows as $r): ?>
                            <tr>
                                <td style="padding:6px 8px;"><?= h($r['id']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['channel']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['artist']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['title']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['date']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['time']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['duration']) ?></td>
                                <td style="padding:6px 8px;"><?= h($r['hour']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>
<script src="reports_scripts.js"></script>

<?php require_once __DIR__ . '/include/footer.php'; ?>
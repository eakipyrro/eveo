<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

$isAdmin = in_array($_SESSION['role'] ?? '', ['admin'], true);
$debugOn = $isAdmin && (isset($_GET['debug']) && $_GET['debug'] === '1');

// reports.php – OAS-raporttisivu (parametrien valinta)

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

// Ei näytetä tuloksia ennenkuin valinnat on olemassa ja Painetaan näppäintä
$isRun = (isset($_GET['run']) && $_GET['run'] === '1');

// Placeholder tyhjälle account-arvolle
const EMPTY_LABEL = '(tyhjä)';

// --- Lue nykyiset valinnat (GET) ---
$selectedAccount = isset($_GET['account']) ? trim($_GET['account']) : '';
$selectedCampaign = isset($_GET['campaign']) ? trim($_GET['campaign']) : '';

// Lomakekenttien oletusarvot (käytetään myös auditissa)
$trpFactor = $_GET['trp'] ?? '0.58';
$population = $_GET['pop'] ?? '2100000';

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

// Kirjaa aina sivulle tulo (GET), jotta admin näkee käytön
audit_log('report_view', [
    'page' => 'reports.php',
    'params' => $currParams,
    'is_run' => (bool) $isRun,
    'role' => $_SESSION['role'] ?? null,
]);

// Jos käyttäjä vaihteli valintoja, mutta ei vielä ajanut raporttia -> parametrimuutos
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
$minDisp = $maxDisp = '—';

// --- Jos sekä account että campaign on valittu, yliaja päivät OATV-taulun perusteella ---
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

// --- Apufunktiot päiville ---
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
    $rangeError = 'Aikaväli on virheellinen: aloituspäivä on suurempi kuin lopetuspäivä.';
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

// --- Laske Tavoitettu yleisö (3+) ---
//$reachAvg = 0.0;
//$reachSum = 0.0;
//$matchedSpots = 0;
$reach3pSum = 0;
$matchedSpots = 0;
$avgViewersSum = 0.0;

if ($isRun && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
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

    $sql = "
    WITH oatv_rows AS (
        SELECT o.id, $o_date AS o_date_parsed, $o_spotdt AS spot_dt
        FROM oatv o
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
    ),
    joined AS (
    SELECT
        x.spot_dt,

        -- Tavoitettu yleisö (3+): haetaan edellinen ohjelma
        (
            SELECT r.over60_on_channel
            FROM overnightreport r
            WHERE
                x.spot_dt IS NOT NULL
                AND $or_start IS NOT NULL
                -- edellinen tai käynnissä oleva ohjelma:
                AND $or_start <= x.spot_dt
                -- rajataan tarvittaessa myös päivämääräväliin
                AND DATE($or_start) BETWEEN :dfrom AND :dto
            ORDER BY $or_start DESC
            LIMIT 1
        ) AS over60_on_channel,

        -- Keskimääräiset katsojat: sama logiikka
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
        SUM(CAST(over60_on_channel AS UNSIGNED)) AS sum_over60,
        /* avg_viewers summataan myös – toleroidaan merkkijonoarvot CASTilla */
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        audit_log('report_query_failed', [
            'page' => 'reports.php',
            'where' => 'ctereport_join',
            'error' => $e->getMessage(),
        ]);
        $row = ['matched_spots' => 0, 'sum_over60' => 0, 'sum_avg_viewers' => 0];
    }


    $matchedSpots = (int) ($row['matched_spots'] ?? 0);
    $reach3pSum = (int) ($row['sum_over60'] ?? 0);
    $avgViewersSum = (float) ($row['sum_avg_viewers'] ?? 0.0);
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
// Sama kampanjafiltteri kuin pääraportissa
$campaignFilter = ($campaign === EMPTY_LABEL)
    ? "(o.artist IS NULL OR TRIM(o.artist) = '')"
    : "TRIM(o.artist) = :campaign";

// Sama päivämäärä OATV:lle (sis. %e.%c.%Y)
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

$debugRows = [];
$dbg_oatv_in = 0;
$dbg_join_hit = 0;
$spotProbe = []; // <-- lisää tämä

if ($isRun && $debugOn && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    $params = [':dfrom' => $effFrom, ':dto' => $effTo];
    if ($campaign !== EMPTY_LABEL)
        $params[':campaign'] = $campaign;

    // 1) Kuinka monta OATV-riviä syötetään läpi (SAMA rajaus kuin pääraportissa)
    $sqlIn = "
        SELECT COUNT(*)
        FROM oatv o
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
    ";
    $stIn = $pdo->prepare($sqlIn);
    $stIn->execute($params);
    $dbg_oatv_in = (int) $stIn->fetchColumn();

    // 2) Varsinainen debug-listaus – TÄSMÄLLEEN sama parsinta & join kuin pääraportissa
    $sqlDbg = "
        SELECT
  o.id AS oatv_id,
  o.artist, o.title, o.channel,
  o.`date` AS oatv_date_raw,
  o.`time` AS oatv_time_raw,
  DATE_FORMAT($o_spotdt, '%Y-%m-%d %H:%i:%s') AS spot_datetime,
  r.start_time AS r_start_raw,
  r.end_time   AS r_end_raw,
  DATE_FORMAT($or_start, '%Y-%m-%d %H:%i:%s') AS r_start_parsed,
  DATE_FORMAT($or_end,   '%Y-%m-%d %H:%i:%s') AS r_end_parsed,
  COALESCE(r.program, '') AS program_name,
  r.over60_on_channel,
  r.avg_viewers,
  CASE
    WHEN ($o_spotdt) IS NULL OR $or_start IS NULL OR $or_end IS NULL THEN 'NO_PARSING'
    WHEN ($o_spotdt) < $or_start OR ($o_spotdt) >= $or_end THEN 'OUT_OF_RANGE'
    ELSE 'MATCH'
  END AS match_flag

        FROM oatv o
        LEFT JOIN overnightreport r
          ON ($o_spotdt) IS NOT NULL
         AND $or_start IS NOT NULL
         AND $or_end   IS NOT NULL
         AND ($o_spotdt) >= $or_start
         AND ($o_spotdt)  < $or_end
        WHERE $campaignFilter
          AND $o_date BETWEEN :dfrom AND :dto
        ORDER BY o.id ASC, r_start_parsed ASC
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
            if (!empty($r['r_start_parsed']))
                $dbg_join_hit++;
        }
    } catch (Throwable $e) {
        $debugRows = [];
        if ($isAdmin && $debugOn) {
            echo '<div class="error-box">Debug-kysely epäonnistui: ' . h($e->getMessage()) . '</div>';

        }
        // echo '<div class="error-box">Debug-kysely epäonnistui: '.h($e->getMessage()).'</div>';

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

// --- Spotin pituudet (kaikki, yleisimmästä harvinaisimpaan) ---
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

    // Tulkitaan pituus: jos o.duration on sekunteina, CAST riittää.
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
$spotLengthDisplay = '—';
if (!empty($spotDurations)) {
    $parts = [];
    foreach ($spotDurations as $d) {
        $parts[] = sec_to_hhmmss($d['sec']) . ' (' . number_format($d['cnt'], 0, ',', ' ') . ')';
    }
    $spotLengthDisplay = implode(', ', $parts);
} else {
    // fallback: näytä aiempi oletus tai viiva
    // $spotLengthDisplay = '00:00:20';
}

// --- Käyttäjän muokattavat kentät (säilyvät GET:ssä) ---
$soldSpots = isset($_GET['sold_spots']) ? trim($_GET['sold_spots']) : '';
$soldTrp = isset($_GET['sold_trp']) ? trim($_GET['sold_trp']) : '';

// --- TRP (35–64): Kerroin * Tavoitettu yleisö (3+) ---
$populationN = max(0, (int) $population);
$trp3564_pct_3p = ($populationN > 0)
    ? round(100 * (($trpFactor * (float) $reach3pSum) / $populationN), 2)
    : 0.00;
$trp3564_pct_avg = ($populationN > 0)
    ? round(100 * (($trpFactor * (float) $avgViewersSum) / $populationN), 2)
    : 0.00;

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

        // Ympäristö
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
<style>
    body.dark {
        --bg: #0b1020;
        --surface: #0f1730;
        --surface-2: #0d142a;
        --panel: #122246;
        --panel-2: #16325f;
        --border: #2a3161;
        --text: #e7ecff;
        --muted: #aeb7e6;
        --label: #d2defa;
        --accent: #4c6fff;
        --accent-2: #3857dd;
        --danger: #ff5577;
        --input-bg: #0b132b;
        --input-border: #3a4580;
        --input-text: #e7ecff;
        --input-placeholder: #9fb0f8;
        --shadow: 0 1px 3px rgba(0, 0, 0, .35);
    }

    body {
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        margin: 24px;
        background: var(--bg);
        color: var(--text)
    }

    h1 {
        margin: 0 0 16px;
        color: var(--text)
    }

    .panel {
        border: 1px solid var(--border);
        border-radius: 10px;
        width: min(980px, 100%);
        background: var(--surface);
        box-shadow: var(--shadow);
        overflow: hidden
    }

    .panel .row {
        display: grid;
        grid-template-columns: 1fr 2fr;
        border-bottom: 1px solid var(--border);
        background: var(--surface)
    }

    .panel .row:nth-child(even) {
        background: var(--surface-2)
    }

    .panel .row>div {
        padding: 10px 12px
    }

    .head {
        background: var(--panel);
        color: var(--label);
        font-weight: 700;
        border-bottom: 1px solid var(--border);
        padding: 12px
    }

    .subhead {
        background: var(--panel-2);
        color: #fff;
        border-top: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
        padding: 10px 12px;
        font-weight: 700
    }

    .label {
        color: var(--label);
        font-weight: 600
    }

    select,
    input[type="date"],
    input[type="number"],
    input[type="text"] {
        width: 100%;
        padding: 8px 10px;
        font-size: 14px;
        color: var(--input-text);
        background: var(--input-bg);
        border: 1px solid var(--input-border);
        border-radius: 8px;
        outline: none;
        transition: border-color .15s, box-shadow .15s, background .15s
    }

    select:disabled {
        opacity: .6;
        cursor: not-allowed
    }

    ::placeholder {
        color: var(--input-placeholder)
    }

    input:focus,
    select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(76, 111, 255, .2)
    }

    select {
        color-scheme: dark
    }

    select,
    select option,
    select optgroup {
        background-color: var(--input-bg);
        color: var(--input-text)
    }

    select option:checked {
        background-color: var(--accent);
        color: #fff
    }

    select option:hover {
        background-color: #1a2650
    }

    .controls {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        margin-top: 14px;
        width: min(980px, 100%);
        gap: 10px
    }

    .btn,
    .controls button,
    .controls a.btn-back {
        display: inline-block;
        padding: 9px 14px;
        font-size: 14px;
        border-radius: 8px;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid var(--border);
        background: var(--surface-2);
        color: var(--text);
        transition: background .15s, border-color .15s, transform .02s
    }

    .controls button.primary {
        background: var(--accent);
        border-color: var(--accent);
        color: #fff
    }

    .controls button.primary:hover {
        background: var(--accent-2);
        border-color: var(--accent-2)
    }

    .controls .btn:hover,
    .controls a.btn-back:hover {
        background: #0f1b3a;
        border-color: #3a4580
    }

    .controls button:active {
        transform: translateY(1px)
    }

    .report-summary {
        width: min(980px, 100%);
        margin-top: 18px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 10px;
        box-shadow: var(--shadow);
        overflow: hidden
    }

    .report-summary table {
        width: 100%;
        border-collapse: collapse;
        color: var(--text)
    }

    .report-summary td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border)
    }

    .report-summary tr:nth-child(odd) td {
        background: var(--surface)
    }

    .report-summary tr:nth-child(even) td {
        background: var(--surface-2)
    }

    .report-summary tr:last-child td {
        border-bottom: none
    }

    .report-summary strong {
        color: #fff;
        font-weight: 700
    }

    .spacer-row {
        height: 8px;
        background: var(--surface)
    }

    .error-box {
        max-width: 1100px;
        margin: 16px 0;
        padding: 10px 12px;
        border: 1px solid var(--danger);
        background: #2a0d19;
        color: #ffb0bf;
        border-radius: 8px
    }

    /* pientoisto: numerokentät taulukossa täysleveiksi */
    .report-summary input[type="number"] {
        width: 100%
    }

    /* --- Vaakavieritettävä kontti tauluille --- */
    .h-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        /* pehmeä vieritys iOS */
        border: 1px solid var(--border);
        border-radius: 10px;
        /* Poistetaan päällekkäinen rajaus sisätaulukosta */
    }

    .h-scroll::-webkit-scrollbar {
        height: 8px;
    }

    .h-scroll::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 4px;
    }

    /* Taulukolle vähimmäisleveys, jotta sarakkeet eivät romahda */
    .h-scroll>table {
        min-width: 820px;
        /* säädä tarvittaessa */
        border: 0;
        /* koska kontilla on jo border */
    }

    /* --- Mobiilin "stacked" kortti-layout alle 640px --- */
    @media (max-width: 640px) {
        .report-summary {
            border: none;
            background: transparent;
            box-shadow: none;
        }

        .report-summary table {
            display: none;
        }

        /* piilotetaan leveä taulukko */
        .report-cards {
            display: grid;
            gap: 10px;
        }

        .report-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow);
            padding: 10px 12px;
        }

        .report-card .row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 4px;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
        }

        .report-card .row:last-child {
            border-bottom: none;
        }

        .report-card .label {
            color: var(--label);
            font-weight: 600;
            font-size: 13px;
        }

        .report-card .value {
            color: var(--text);
            font-weight: 700;
        }

        .report-card input[type="number"],
        .report-card input[type="text"] {
            width: 100%;
        }
    }

    @media (min-width: 641px) {
        .report-cards {
            display: none;
        }
    }

    /* --- Busy indicator --- */
    .controls {
        position: relative;
    }

    .busy-indicator {
        display: none;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        color: var(--muted);
        white-space: nowrap;
    }

    .is-busy .busy-indicator {
        display: inline-flex;
    }

    /* Pieni border-spinner */
    .spinner {
        width: 16px;
        height: 16px;
        border: 2px solid var(--border);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: spin .8s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* --- Kun lomake on busy, harmaannutetaan napit --- */
    .is-busy .controls button {
        opacity: 0.5;
        cursor: not-allowed;
        filter: grayscale(0.7);
    }

    /* Lisäksi varmistetaan että hover-efekti ei muuta väriä disabled-tilassa */
    .controls button:disabled,
    .controls button:disabled:hover {
        background: var(--surface-2);
        border-color: var(--border);
        color: var(--muted);
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
    }

    .modal.show {
        display: block;
    }

    .modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, .5);
    }

    .modal-content {
        position: absolute;
        top: 5%;
        left: 50%;
        transform: translateX(-50%);
        width: min(1100px, 96%);
        max-height: 90%;
        background: var(--surface);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        background: var(--panel);
        border-bottom: 1px solid var(--border);
    }

    .modal-body {
        padding: 10px 12px;
        overflow: auto;
    }

    .modal-close {
        border: 1px solid var(--border);
        background: var(--surface-2);
        color: var(--text);
        border-radius: 8px;
        padding: 6px 10px;
        cursor: pointer;
    }

    .modal-close:hover {
        background: #0f1b3a;
        border-color: #3a4580;
    }
</style>

<form id="filtersForm" method="get" action="">
    <input type="hidden" name="run" value="0">
    <div class="panel">
        <div class="head">Valitse asetukset:</div>

        <div class="row">
            <div class="label">Mainostaja:</div>
            <div>
                <select name="account">
                    <option value="">— Valitse —</option>
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
                    <option value="">— <?= $selectedAccount === '' ? 'Valitse ensin mainostaja' : 'Kaikki kampanjat' ?>
                        —</option>
                    <?php foreach ($campaigns as $camp): ?>
                        <option value="<?= h($camp) ?>" <?= $selectedCampaign === $camp ? 'selected' : '' ?>>
                            <?= h($camp) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($selectedAccount !== '' && $selectedCampaign !== ''): ?>
            <div class="subhead">Tiedolla löytyi kampanja, joka on…</div>
            <div class="two">
                <div class="row">
                    <div class="label">…aloitettu:</div>
                    <div><?= h($minDisp) ?></div>
                </div>
                <div class="row">
                    <div class="label">…lopetettu:</div>
                    <div><?= h($maxDisp) ?></div>
                </div>
            </div>

            <div class="subhead">Jos kuitenkin haluat rajata tuloksia ajan mukaan;</div>
            <div class="two">
                <div class="row">
                    <div class="label">Valitse uusi aloituspäivä:</div>
                    <div><input type="date" name="start" value="<?= h($startOverride) ?>"></div>
                </div>
                <div class="row">
                    <div class="label">Valitse uusi lopetuspäivä:</div>
                    <div><input type="date" name="end" value="<?= h($endOverride) ?>"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="label">Kerroin (TRP:ia varten):</div>
            <div><input type="number" step="0.01" min="0" name="trp" value="<?= h($trpFactor) ?>"></div>
        </div>

        <div class="row">
            <div class="label">Suomen väkiluku:</div>
            <div><input type="number" step="1" min="0" name="pop" value="<?= h($population) ?>"></div>
        </div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="row">
            <div class="label">Debug (admin)</div>
            <div>
                <label style="display:inline-flex;gap:8px;align-items:center;">
                    <input type="checkbox" name="debug" value="1" <?= $debugOn ? 'checked' : '' ?>>
                    <span>Näytä kaikki spotin osumat (debug-modal)</span>
                </label>
            </div>
        </div>
    <?php endif; ?>

    <div class="controls">
        <div>
            <button type="submit" name="run" value="1" class="primary">Laske TRP</button>
            <button type="button" onclick="window.location.href='reports.php'">Tyhjennä</button>
        </div>
        <!-- Busy-indikaattori -->
        <span class="busy-indicator" aria-live="polite">
            <span class="spinner" aria-hidden="true"></span>
            <span class="busy-text">Lasketaan…</span>
        </span>
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
            echo '<div class="error-box">Probe epäonnistui: ' . h($e->getMessage()) . '</div>';
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
        OATV sisään: <?= (int) $dbg_oatv_in ?> riviä · JOIN-osumia: <?= (int) $dbg_join_hit ?> riviä
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
            Näytä debug-rivit (<?= count($debugRows) ?>)
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
            Valittu kampanjanimi “<?= h($campaign) ?>” ei löydy OATV:stä
            (artist/title) valitulla aikavälillä. Kokeile hakua väljemmin
            (LIKE on jo käytössä) tai varmista, käytetäänkö OATV:ssä eri nimeä.
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($isRun && !$rangeError): ?>
    <!-- Taulukko on oma pikalomakkeensa, jotta muokattavat kentät saa talteen ilman yläosan valintojen katoamista -->
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

            <!-- Leveä taulukko: näkyy desktopilla / isolla näytöllä, ja mobiilissa sivuttaisvieritys toimii -->
            <div class="h-scroll">
                <table class="table table-bordered" style="width:100%; border-collapse:collapse;">
                    <tbody>
                        <tr>
                            <td><strong>Mainostaja</strong></td>
                            <td><?= h($account) ?></td>
                            <td><strong>Myydyt spotit (kpl)</strong></td>
                            <td>
                                <input type="number" name="sold_spots" step="1" min="0" value="<?= h($soldSpots) ?>"
                                    placeholder="Syötä määrä">
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Mainoskampanjan nimi</strong></td>
                            <td><?= h($campaign) ?></td>
                            <td><strong>Myyty TRP (kpl)</strong></td>
                            <td>
                                <input type="number" name="sold_trp" step="0.01" min="0" value="<?= h($soldTrp) ?>"
                                    placeholder="Syötä TRP">
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
                            <td><strong>Keskikatsojamäärä (summa)</strong></td>
                            <td><?= number_format($avgViewersSum, 0, ',', ' ') ?></td>
                        </tr>
                        <tr>
                            <td><strong>Spotin pituus</strong></td>
                            <td><?= h($spotLengthDisplay) ?></td>
                            <td><strong>TRP (35–64)</strong></td>
                            <td><?= number_format($trp3564_pct_avg, 2, ',', ' ') ?> </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobiilikortit: näkyy vain alle 640px -->
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
                                value="<?= h($soldSpots) ?>" placeholder="Syötä määrä"></div>
                    </div>
                    <div class="row">
                        <div class="label">Myyty TRP (kpl)</div>
                        <div class="value"><input type="number" name="sold_trp" step="0.01" min="0"
                                value="<?= h($soldTrp) ?>" placeholder="Syötä TRP"></div>
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
<!--                        <div class="label">Tavoitettu yleisö (3+)</div>
                        <div class="value"><?= number_format((int) $reach3pSum, 0, ',', ' ') ?>
                        </div> -->
                        <div class="row">
                            <div class="label">Keskikatsojamäärä (summa)</div>
                            <div class="value"><?= number_format($avgViewersSum, 0, ',', ' ') ?></div>
                        </div>
<!--                        <div class="row">
                            <div class="label">TRP (35–64)</div>
                            <div class="value"><?= number_format($trp3564_pct_3p, 2, ',', ' ') ?> %</div>
                        </div> -->
                        <div class="row">
                            <div class="label">TRP (35–64), avg_viewers</div>
                            <div class="value"><?= number_format($trp3564_pct_avg, 2, ',', ' ') ?> %</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- <div class="controls" style="justify-content:flex-end;max-width:1100px;">
            <button type="submit" class="primary">Päivitä nämä kentät</button>
        </div> -->
    </form>

    <?php
    // --- OATV peek (admin debug) ---
// Näyttää 20 viimeisintä OATV-riviä valitulta aikaväliltä,
// jotta näet millä nimillä kampanja oikeasti esiintyy.
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
                    <h3 id="dbgTitle">Debug – spotin kaikki osumat</h3>
                    <button type="button" class="modal-close" id="btnCloseDebug" aria-label="Sulje">×</button>
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
                            Näytetään enintään 5000 riviä. Suodata tarkemmin, jos tarvitset täydellisen listan.
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
<script>
    (() => {
        const form = document.getElementById('filtersForm');
        if (!form) return;

        const calcBtn = form.querySelector('button[name="run"][value="1"]');
        const resetBtn = form.querySelector('button[type="button"]');
        const selects = form.querySelectorAll('select');

        let busy = false;

        function setBusyUI(on) {
            form.classList.toggle('is-busy', on);
            if (on) form.setAttribute('aria-busy', 'true'); else form.removeAttribute('aria-busy');
        }

        function ensureRun(val) {
            let h = form.querySelector('input[name="run"]');
            if (!h) {
                h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'run';
                form.appendChild(h);
            }
            h.value = String(val);
        }

        // Laske TRP
        if (calcBtn) {
            calcBtn.addEventListener('click', (e) => {
                if (busy) { e.preventDefault(); return; }
                e.preventDefault();
                busy = true;
                setBusyUI(true);

                // ÄLÄ disabloi calcBtn vielä -> submitteri pysyy “kelvollisena”
                // Kirjoitetaan silti varmuuden vuoksi run=1 piilokenttään
                ensureRun(1);

                // Lähetä lomake – jos requestSubmit ei toimi, fallback submit()
                try {
                    if (form.requestSubmit) form.requestSubmit(calcBtn);
                    else form.submit();
                } finally {
                    // Disabloi napit vasta kun navigointi on jo jonossa
                    setTimeout(() => {
                        calcBtn.disabled = true;
                        if (resetBtn) resetBtn.disabled = true;
                    }, 0);
                }
            });
        }

        // Selectin muutos -> kevyt päivitys ilman run=1
        selects.forEach(sel => {
            sel.addEventListener('change', () => {
                if (busy) return;
                busy = true;
                setBusyUI(true);
                ensureRun(0);
                // Ei käytetä submitteriä -> tavallinen submit
                requestAnimationFrame(() => form.submit());
            });
        });

        // Turvaverkko: jos submit tulee Enterillä tms.
        form.addEventListener('submit', () => {
            if (busy) return;
            busy = true;
            setBusyUI(true);
        }, true);

        // bfcache (Back) -> nollaa UI
        window.addEventListener('pageshow', () => {
            busy = false;
            setBusyUI(false);
            if (calcBtn) calcBtn.disabled = false;
            if (resetBtn) resetBtn.disabled = false;
        });
        (() => {
            const openBtn = document.getElementById('btnOpenDebug');
            const closeBtn = document.getElementById('btnCloseDebug');
            const modal = document.getElementById('debugModal');
            if (!modal || !openBtn || !closeBtn) return;

            function open() { modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); }
            function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }

            openBtn.addEventListener('click', open);
            closeBtn.addEventListener('click', close);
            modal.querySelector('.modal-backdrop')?.addEventListener('click', close);
            window.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
        })();

    })();
</script>


<?php require_once __DIR__ . '/include/footer.php'; ?>
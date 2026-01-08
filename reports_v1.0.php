<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
require_permission('view_reports');

// reports.php – OAS-raporttisivu (parametrien valinta)

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/include/db.php';
$pdo = db();

// Sivukohtaiset asetukset headerille:
$PAGE_TITLE = 'Raportit';
$REQUIRE_LOGIN = true;
$REQUIRE_PERMISSION = 'view_reports';
$BACK_HREF = 'index.php';

// Otsikko + palkki
require_once __DIR__ . '/include/header.php';

// Ei näytetä tuloksia ennenkuin valinnat on olemassa ja Painentaan näppäintä
$isRun = (isset($_GET['run']) && $_GET['run'] === '1');

// Apufunktio XSS-suojattuun tulostukseen
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Placeholder tyhjälle account-arvolle
const EMPTY_LABEL = '(tyhjä)';

// --- Lue nykyiset valinnat (GET) ---
$selectedAccount = isset($_GET['account']) ? trim($_GET['account']) : '';
$selectedCampaign = isset($_GET['campaign']) ? trim($_GET['campaign']) : '';

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
}

// --- Jos account valittu, hae sen kampanjat ---
$campaigns = [];
if ($selectedAccount !== '') {
    try {
        // Ehto tyhjälle accountille
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
        while ($r = $stmt->fetch()) {
            $campaigns[] = $r['camp'] ?? '';
        }
        // Korvaa mahdolliset NULLit näkyvällä '(tyhjä)'
        $campaigns = array_map(fn($c) => $c === '' || $c === null ? EMPTY_LABEL : $c, $campaigns);
        $campaigns = array_values(array_unique($campaigns, SORT_STRING));
    } catch (Throwable $e) {
        $campaigns = [];
    }
}

$minISO = $maxISO = null;
$minDisp = $maxDisp = '—';

// --- Jos sekä account että campaign on valittu, yliaja päivät OATV-taulun perusteella ---
if ($selectedAccount !== '' && $selectedCampaign !== '') {
    try {
        // Muunnos: yritä useita formaatteja (DATE, yyyy-mm-dd, dd.mm.yyyy, d.m.yyyy, dd.mm.yyyy HH:MM:SS)
        $dateExprMin = "
            DATE_FORMAT(
              MIN(
                COALESCE(
                  /* jos sarake on jo DATE/DATETIME-tyyppiä */
                  CASE WHEN `date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(`date`, '%Y-%m-%d') END,
                  /* dd.mm.yyyy */
                  STR_TO_DATE(`date`, '%d.%m.%Y'),
                  /* d.m.Y  (yksinumeroiset päivät/kuukaudet) */
                  STR_TO_DATE(`date`, '%e.%c.%Y'),
                  /* jos perässä on kellonaika -> otetaan vain ensimmäinen osa ennen välilyöntiä */
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
            $sql = "
                SELECT
                  $dateExprMin AS min_d,
                  $dateExprMax AS max_d
                FROM oatv
                WHERE artist IS NULL OR TRIM(artist) = ''
            ";
            $stmtOatv = $pdo->prepare($sql);
            $stmtOatv->execute();
        } else {
            $sql = "
                SELECT
                  $dateExprMin AS min_d,
                  $dateExprMax AS max_d
                FROM oatv
                WHERE TRIM(artist) = :artist
            ";
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
    } catch (Throwable $e) {
        // Halutessa: error_log('OATV override failed: '.$e->getMessage());
    }
}

// Lomakekenttien oletusarvot:
$startOverride = $_GET['start'] ?? ($minISO ?? '');
$endOverride = $_GET['end'] ?? ($maxISO ?? '');
$trpFactor = $_GET['trp'] ?? '0.58';
$population = $_GET['pop'] ?? '2100000';
?>
<style>
    /* --- Teemamuuttujat tummalle --- */
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

    /* Perusrunko */
    body {
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        margin: 24px;
        background: var(--bg);
        color: var(--text);
    }

    h1 {
        margin: 0 0 16px;
        color: var(--text);
    }

    /* Paneeli / lomakerunko */
    .panel {
        border: 1px solid var(--border);
        border-radius: 10px;
        width: min(980px, 100%);
        background: var(--surface);
        box-shadow: var(--shadow);
        overflow: hidden;
        /* pyöristykset myös otsikolle */
    }

    .panel .row {
        display: grid;
        grid-template-columns: 1fr 2fr;
        border-bottom: 1px solid var(--border);
        background: var(--surface);
    }

    .panel .row:nth-child(even) {
        background: var(--surface-2);
    }

    .panel .row>div {
        padding: 10px 12px;
    }

    .head {
        background: var(--panel);
        color: var(--label);
        font-weight: 700;
        border-bottom: 1px solid var(--border);
        padding: 12px;
    }

    .subhead {
        background: var(--panel-2);
        color: #fff;
        border-top: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
        padding: 10px 12px;
        font-weight: 700;
    }

    .label {
        color: var(--label);
        font-weight: 600;
    }

    /* Kentät */
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
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }

    select:disabled {
        opacity: .6;
        cursor: not-allowed;
    }

    ::placeholder {
        color: var(--input-placeholder);
    }

    input:focus,
    select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(76, 111, 255, .2);
    }

    .hint {
        color: var(--muted);
        font-size: 13px;
        margin: 6px 0 0;
    }

    /* Ohjaa selainta käyttämään tummaa natiivi-UI:ta (Chrome, Edge, Safari, FF) */
    select {
        color-scheme: dark;
    }

    /* Varmuuden vuoksi värit myös option/optgroupille (Firefox ja osa WebKitistä kunnioittaa näitä) */
    select,
    select option,
    select optgroup {
        background-color: var(--input-bg);
        color: var(--input-text);
    }

    /* Valitun rivin korostus pudotuslistassa */
    select option:checked {
        background-color: var(--accent);
        color: #fff;
    }

    /* Hover-tila (Firefox) */
    select option:hover {
        background-color: #1a2650;
    }

    /* Estä "haalea" look – poistetaan mahdollinen läpinäkyvyys */
    select:disabled {
        opacity: .6;
    }

    select:not(:disabled) {
        opacity: 1;
    }

    /* Napit ja kontrollialue */
    .controls {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        margin-top: 14px;
        width: min(980px, 100%);
        gap: 10px;
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
        transition: background .15s ease, border-color .15s ease, transform .02s ease;
    }

    .controls button.primary {
        background: var(--accent);
        border-color: var(--accent);
        color: #fff;
    }

    .controls button.primary:hover {
        background: var(--accent-2);
        border-color: var(--accent-2);
    }

    .controls .btn:hover,
    .controls a.btn-back:hover {
        background: #0f1b3a;
        border-color: #3a4580;
    }

    .controls button:active {
        transform: translateY(1px);
    }

    /* Raporttitaulukko */
    .report-summary {
        width: min(980px, 100%);
        margin-top: 18px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 10px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .report-summary table {
        width: 100%;
        border-collapse: collapse;
        color: var(--text);
    }

    .report-summary td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border);
    }

    .report-summary tr:nth-child(odd) td {
        background: var(--surface);
    }

    .report-summary tr:nth-child(even) td {
        background: var(--surface-2);
    }

    .report-summary tr:last-child td {
        border-bottom: none;
    }

    .report-summary strong {
        color: #fff;
        font-weight: 700;
    }

    /* Takaisin-linkin “välikkörivi” korvaus */
    .spacer-row {
        height: 8px;
        background: var(--surface);
    }

    /* Virheboksi */
    .error-box {
        max-width: 1100px;
        margin: 16px 0;
        padding: 10px 12px;
        border: 1px solid var(--danger);
        background: #2a0d19;
        color: #ffb0bf;
        border-radius: 8px;
    }
</style>

<!--
<h1>Raportit</h1>
-->

<form method="get" action="">
    <div class="panel">
        <div class="head">Valitse asetukset:</div>

        <div class="row">
            <div class="label">Mainostaja:</div>
            <div>
                <select name="account" onchange="this.form.submit()">
                    <option value="">— Valitse —</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= h($acc) ?>" <?= $selectedAccount === $acc ? 'selected' : '' ?>>
                            <?= h($acc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- <p class="hint">Dropdown on muodostettu oas.account uniikeista arvoista.</p> -->
            </div>
        </div>

        <div class="row">
            <div class="label">Mainoskampanjan nimi:</div>
            <div>
                <select name="campaign" <?= $selectedAccount === '' ? 'disabled' : '' ?> onchange="this.form.submit()">
                    <option value="">—
                        <?= $selectedAccount === '' ? 'Valitse ensin mainostaja' : 'Kaikki kampanjat' ?> —
                    </option>
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

    <div class="controls">
        <div>
            <button type="submit" name="run" value="1" class="primary">Päivitä tulokset</button>
            <button type="button" onclick="window.location.href='reports.php'">Tyhjennä</button>
        </div>
    </div>

</form>

<!-- Seuraava vaihe: tulostaulun ja mittareiden rakentaminen valittujen parametrien perusteella -->
<!-- Tähän vielä isolla se Eveon logo --->
<?php
// --- Apufunktiot ---
function parse_fi_date($s)
{
    $s = trim((string) $s);
    if ($s === '')
        return null;
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s))
        return $s;
    if (preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    return null;
}
function fi_date($iso)
{
    if (!$iso)
        return '';
    [$y, $m, $d] = explode('-', $iso);
    return sprintf('%02d.%02d.%04d', (int) $d, (int) $m, (int) $y);
}

// --- Poimi lomakkeen arvot ---
$account = $_GET['account'] ?? $_POST['account'] ?? '';
$campaign = $_GET['campaign'] ?? $_POST['campaign'] ?? '';

// form-kentät ovat nimillä "start" ja "end"
$fromRaw = $_GET['start'] ?? $_POST['start'] ?? '';
$toRaw = $_GET['end'] ?? $_POST['end'] ?? '';

// --- Lomakkeen raakaarvot ("Valitse uusi ...") ---
$fromRaw = $_GET['start'] ?? $_POST['start'] ?? '';
$toRaw = $_GET['end'] ?? $_POST['end'] ?? '';

$ovStart = parse_fi_date($fromRaw); // käyttäjän mahdollinen uusi aloitus
$ovEnd = parse_fi_date($toRaw);   // käyttäjän mahdollinen uusi lopetus

// Perusväli kampanjasta (minISO/maxISO haettu ylempänä OATV:stä)
$baseFrom = $minISO ?: null;
$baseTo = $maxISO ?: null;

// Rakennetaan tehokas aikaväli: käyttäjän päivillä saa VAIN SUPISTAA perusväliä
$effFrom = $baseFrom;
$effTo = $baseTo;

if ($ovStart && $baseFrom && $ovStart > $baseFrom) {
    $effFrom = $ovStart;
}
if ($ovEnd && $baseTo && $ovEnd < $baseTo) {
    $effTo = $ovEnd;
}

// Varmista, ettei mennä kampanjan ulkopuolelle
if ($effFrom && $baseFrom && $effFrom < $baseFrom)
    $effFrom = $baseFrom;
if ($effTo && $baseTo && $effTo > $baseTo)
    $effTo = $baseTo;

// Järjestystarkastus
$rangeError = '';
if ($effFrom && $effTo && $effFrom > $effTo) {
    $rangeError = 'Aikaväli on virheellinen: aloituspäivä on suurempi kuin lopetuspäivä.';
}

// --- Laske toteutuneet spotit (vain kun "Päivitä tulokset") ---
$playedCount = 0;
if (isset($_GET['run']) && $_GET['run'] === '1' && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
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
// PÄÄAJATUS: Etsi jokaiselle spotille spot_dt ja liitä se overnightreportin ohjelmajaksoon (or_start <= spot_dt < or_end).

$reachAvg = 0;
$reachSum = 0;
$matchedSpots = 0;

if (isset($_GET['run']) && $_GET['run'] === '1' && !$rangeError && $campaign !== '' && $effFrom && $effTo) {
    // Suodata kampanjalla (tyhjä = EMPTY_LABEL) ja aikavälillä; rakenna spotin datetime
    $campaignFilter = ($campaign === EMPTY_LABEL)
        ? "(o.artist IS NULL OR TRIM(o.artist) = '')"
        : "TRIM(o.artist) = :campaign";

    // Päivämäärä (DATE) OATV:stä
    $o_date = "
        COALESCE(
            STR_TO_DATE(o.`date`, '%Y-%m-%d'),
            STR_TO_DATE(o.`date`, '%d.%m.%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(o.`date`, ' ', 1), '%d.%m.%Y')
        )
    ";

    // Aika OATV:stä – koetetaan yhdistää date + time, tai fallback hour-kenttään
    // Tuetaan muotoja HH:MM[:SS] ja HH.MM (joissain CSV:issä)
    $o_spotdt = "
        COALESCE(
            /* ISO date + HH:MM:SS */
            STR_TO_DATE(CONCAT($o_date, ' ', o.`time`), '%Y-%m-%d %H:%i:%s'),
            /* ISO date + HH:MM */
            STR_TO_DATE(CONCAT($o_date, ' ', o.`time`), '%Y-%m-%d %H:%i'),
            /* FI date + HH:MM:SS */
            STR_TO_DATE(CONCAT(DATE_FORMAT($o_date,'%Y-%m-%d'), ' ', o.`time`), '%Y-%m-%d %H:%i:%s'),
            /* FI date + HH:MM */
            STR_TO_DATE(CONCAT(DATE_FORMAT($o_date,'%Y-%m-%d'), ' ', o.`time`), '%Y-%m-%d %H:%i'),
            /* FI date + HH.MM */
            STR_TO_DATE(CONCAT(DATE_FORMAT($o_date,'%Y-%m-%d'), ' ', REPLACE(o.`time`,'.',':')), '%Y-%m-%d %H:%i'),
            /* Fallback: hour-kenttä kokonaisina tunteina */
            DATE_ADD($o_date, INTERVAL CAST(o.`hour` AS UNSIGNED) HOUR)
        )
    ";

    // overnightreportin alku/loppu – tuetaan yleisimmät muodot:
    //  - DATETIME 'YYYY-MM-DD HH:MM:SS'
    //  - FI 'dd.mm.yyyy HH:MM[:SS]'
    //  - US 'mm.dd.yyyy hh:mm:ss AM/PM' (CSV-esimerkkisi)
    $or_start = "
        COALESCE(
            /* ISO DATETIME */
            STR_TO_DATE(r.start_time, '%Y-%m-%d %H:%i:%s'),
            STR_TO_DATE(r.start_time, '%Y-%m-%d %H:%i'),
            /* FI DATETIME */
            STR_TO_DATE(r.start_time, '%d.%m.%Y %H:%i:%s'),
            STR_TO_DATE(r.start_time, '%d.%m.%Y %H:%i'),
            /* US 12h AM/PM */
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

    // Rakennetaan kysely, joka:
    //  1) Poimii OATV-spotit ja niiden spot_dt
    //  2) Suodattaa päivillä (date) [fromIso..toIso]
    //  3) LEFT JOIN ohjelmajaksoon väliltä [or_start, or_end)
    //  4) Kerää vain matchattujen avg_viewersien SUM ja keskiarvon
    $sql = "
        WITH oatv_rows AS (
            SELECT
                o.id,
                $o_date   AS o_date_parsed,
                $o_spotdt AS spot_dt
            FROM oatv o
            WHERE $campaignFilter
              AND $o_date BETWEEN :dfrom AND :dto
        ),
        joined AS (
            SELECT
                x.spot_dt,
                r.avg_viewers
            FROM oatv_rows x
            LEFT JOIN overnightreport r
              ON x.spot_dt IS NOT NULL
             AND $or_start IS NOT NULL
             AND $or_end   IS NOT NULL
             AND x.spot_dt >= $or_start
             AND x.spot_dt  < $or_end
        )
        SELECT
            COUNT(*) AS total_spots,
            SUM(CASE WHEN avg_viewers IS NOT NULL THEN 1 ELSE 0 END) AS matched_spots,
            SUM(avg_viewers) AS sum_viewers,
            AVG(NULLIF(avg_viewers, NULL)) AS avg_viewers_matched
        FROM joined
    ";

    $params = [':dfrom' => $effFrom, ':dto' => $effTo];
    if ($campaign !== EMPTY_LABEL) {
        $params[':campaign'] = $campaign;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $matchedSpots = (int) ($row['matched_spots'] ?? 0);
    $reachSum = (float) ($row['sum_viewers'] ?? 0);
    $reachAvg = $matchedSpots > 0 ? (float) $row['avg_viewers_matched'] : 0.0;
}
// --- Testaa mitä arvoja oikeasti tulee ---
// var_dump($account, $campaign, $fromIso, $toIso);

$spotLength = '00:00:20';
?>
<?php if ($isRun && $rangeError): ?>
<div class="error-box">
    <?= h($rangeError) ?>
</div>
<?php endif; ?>

<?php if ($isRun && !$rangeError): ?>
<div class="report-summary" style="max-width:1100px;margin-top:18px;">
    <table class="table table-bordered" style="width:100%; border-collapse:collapse;">
        <tbody>
            <tr>
                <td><strong>Mainostaja</strong></td>
                <td>
                    <?= h($account) ?>
                </td>
                <td><strong>Myydyt spotit (kpl)</strong></td>
                <td></td>
            </tr>
            <tr>
                <td><strong>Mainoskampanjan nimi</strong></td>
                <td>
                    <?= h($campaign) ?>
                </td>
                <td><strong>Myyty TRP (kpl)</strong></td>
                <td></td>
            </tr>
            <tr>
            <tr>
                <td colspan="4" class="spacer-row"></td>
            </tr>
            </tr>
            <tr>
                <td><strong>Raportti alkaen</strong></td>
                <td>
                    <?= h(fi_date($effFrom)) ?>
                </td>
                <td><strong>Toteutuneet spotit (kpl)</strong></td>
                <td>
                    <?= number_format($playedCount, 0, ',', ' ') ?>
                </td>
            </tr>
            <tr>
                <td><strong>Raportti loppuen</strong></td>
                <td>
                    <?= h(fi_date($effTo)) ?>
                </td>
                <td><strong>Tavoitettu yleisö (3+)</strong></td>
                <td>
                    <?= number_format(round($reachAvg), 0, ',', ' ') ?>
                </td>
            </tr>
            <tr>
                <td><strong>Spotin pituus</strong></td>
                <td>
                    <?= h($spotLength) ?>
                </td>
                <td><strong>TRP (35–64)</strong></td>
                <td>0,00</td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/include/footer.php';
?>
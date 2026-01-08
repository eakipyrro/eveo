<?php
// csv_importer_unified.php — Yhdistetty CSV-importeri kolmelle eri muodolle (OATV, OAS, OVERNIGHT)
// - Esikatselu (dry run) ja oikea tuonti
// - Erotin: automaattinen, ; , , \t, |
// - Kestää puuttuvia kenttiä -> tallentaa "<empty>"
//
// SÄÄDÄ NÄMÄ TARVITTAESSA OMAAN YMPÄRISTÖÖSI:
$DB_HOST = 'www.fissi.fi';
$DB_NAME = 'fissifi_eveo';
$DB_USER = 'fissifi_eveo_admin';
$DB_PASS = '23ufa3}A1CaG%Ica';
$DB_CHARSET = 'utf8mb4';

if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: text/html; charset=utf-8');

date_default_timezone_set('Europe/Helsinki');

// ---- Pieni apufunktio CSRF:lle ----
if (empty($_SESSION['csv_csrf'])) {
    $_SESSION['csv_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csv_csrf'];

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function safe_get($arr, $idx, $fallback = "<empty>")
{
    return array_key_exists($idx, $arr) && $arr[$idx] !== '' ? $arr[$idx] : $fallback;
}

// ---- Profiilit (kolme muotoa) ----
// "columns" = halutut DB-kolumnit järjestyksessä
// "header_map" = CSV-otsikoiden (normaalistettuna) map kolumni-indekseihin (automaattinen löytäminen)
// "table" = kohde-taulu
// HUOM: voit säätää kolumninimet vastaamaan tietokannan todellisia nimiä
$PROFILES = [
    'oatv' => [
        'label' => 'OATV (channel, artist, title, date, time, type, duration, hour)',
        'table' => 'oatv',
        'columns' => ['channel', 'artist', 'title', 'date', 'time', 'type', 'duration', 'hour'],
        'header_map' => [
            'channel' => 'channel',
            'artist' => 'artist',
            'title' => 'title',
            'date' => 'date',
            'time' => 'time',
            'type' => 'type',
            'duration' => 'duration',
            'hour' => 'hour',
        ],
    ],
    'oas' => [
        'label' => 'OAS (Account;Campaign;Contract nr;Date;Hour;Block;Code;Commercial;Length;Edition)',
        'table' => 'oas',
        'columns' => ['account', 'campaign', 'contract_nr', 'date_played', 'hours', 'block', 'code', 'commercial', 'length_sec', 'edition'],
        'header_map' => [
            'account' => 'account',
            'campaign' => 'campaign',
            'contract nr' => 'contract_nr',
            'contract no' => 'contract_nr',
            'date' => 'date_played',
            'hour' => 'hours',
            'block' => 'block',
            'code' => 'code',
            'commercial' => 'commercial',
            'length' => 'length_sec',
            'edition' => 'edition',
        ],
    ],
    'overnight' => [
        'label' => 'OVERNIGHT (Päivämäärä;Ohjelma;Alku;Päättymisaika;Yli 60s kanavalla;Keskikatsojamäärä;Katsojaosuus)',
        'table' => 'overnightreport',
        'columns' => ['report_date', 'program', 'start_time', 'end_time', 'over60_on_channel', 'avg_viewers', 'share_pct'],
        'header_map' => [
            'päivämäärä' => 'report_date',
            'paivamaara' => 'report_date',
            'ohjelma' => 'program',
            'alku' => 'start_time',
            'päättymisaika' => 'end_time',
            'paattymisaika' => 'end_time',
            'yli 60s kanavalla' => 'over60_on_channel',
            'keskikatsojamäärä' => 'avg_viewers',
            'katsojaosuus' => 'share_pct',
        ],
    ],
];

// ---- Erotin tunnistus ----
function detect_delimiter($line)
{
    $candidates = [';', '\t', ',', '|'];
    $best = ';';
    $bestCount = -1;
    foreach ($candidates as $d) {
        $cnt = substr_count($line, $d);
        if ($cnt > $bestCount) {
            $bestCount = $cnt;
            $best = $d;
        }
    }
    return $best;
}

function normalize($s)
{
    // Muunna merkkijono unicodeksi ja pieneen
    $s = (string) $s;

    // Poista mahdolliset BOM-merkinnät myös solun sisältä
    $s = preg_replace("/^\x{FEFF}|\x{FEFF}/u", "", $s);

    // Korvaa “kova väli” (NBSP) ja muut harvinaiset välit tavalliseksi välilyönniksi
    // NBSP = \xC2\xA0, EN SPACE = \xE2\x80\x82, EM SPACE = \xE2\x80\x83, THIN SPACE = \xE2\x80\x89 jne.
    $s = str_replace(
        [
            "\xC2\xA0",
            "\xE2\x80\x82",
            "\xE2\x80\x83",
            "\xE2\x80\x84",
            "\xE2\x80\x85",
            "\xE2\x80\x86",
            "\xE2\x80\x87",
            "\xE2\x80\x88",
            "\xE2\x80\x89",
            "\xE2\x80\x8A"
        ],
        " ",
        $s
    );

    // Pienennä
    $s = mb_strtolower($s, 'UTF-8');

    // Tiivistä välit (UNICODE-lipulla) ja trimmaa
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);

    return $s;
}


function open_db($host, $db, $user, $pass, $charset)
{
    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}"
    ];
    return new PDO($dsn, $user, $pass, $opt);
}

function parse_csv_rows($tmpFile, $delimiter, $maxRows = 20000)
{
    $rows = [];
    $fh = fopen($tmpFile, 'r');
    if (!$fh)
        return $rows;
    $i = 0;
    while (($line = fgets($fh)) !== false) {
        $i++;
        if ($i > $maxRows)
            break; // karkea suojaraja
        // Poistetaan UTF-8 BOM tarvittaessa
        if ($i === 1)
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $parts = str_getcsv(rtrim($line, "\r\n"), $delimiter, '"');
        $rows[] = $parts;
    }
    fclose($fh);
    return $rows;
}

function build_insert_sql($table, $columns)
{
    $cols = implode(',', array_map(fn($c) => "`$c`", $columns));
    $place = implode(',', array_fill(0, count($columns), '?'));
    return "INSERT INTO `{$table}` ({$cols}) VALUES ({$place})";
}

function duration_to_seconds($s)
{
    $s = trim((string) $s);
    if ($s === '')
        return null;

    // Hyväksy mm:ss, hh:mm:ss tai pelkkä numero (sekunteja)
    if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
        $h = isset($m[3]) ? (int) $m[1] : 0;
        $m1 = isset($m[3]) ? (int) $m[2] : (int) $m[1];
        $s1 = isset($m[3]) ? (int) $m[3] : (int) $m[2];
        return $h * 3600 + $m1 * 60 + $s1;
    }

    // Poista tekstit (esim. "60 sec", "60s")
    if (preg_match('/^\d+$/', preg_replace('/\D+/', '', $s))) {
        return (int) preg_replace('/\D+/', '', $s);
    }

    // Viimeinen yritys: strtotime ei ole hyvä kestoihin -> palauta null
    return null;
}
function is_repeat_header_row(array $row, string $profileKey): bool
{
    if ($profileKey !== 'oas')
        return false;
    // Normalisoi solut vertailua varten (pienet kirjaimet, trimmattu, väliyhdisteet poistettu)
    $norm = array_map('normalize', $row);
    // Hakulista – sallitaan sekä "contract nr" että "contract no"
    $candidates = ['account', 'campaign', 'contract nr', 'contract no', 'date', 'hour', 'block', 'code', 'commercial', 'length', 'edition'];
    $hits = 0;
    foreach ($norm as $v) {
        if (in_array($v, $candidates, true))
            $hits++;
    }
    // Jos riviltä löytyy useita otsikkoja, se on todennäköisesti header
    return $hits >= 4;
}

function html_header()
{
    echo "<!doctype html><html lang=\"fi\"><head><meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>CSV Importeri (yhdistetty)</title>\n";
    echo '<style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:20px;background:#0b0d10;color:#e8e8e8}
        a{color:#7db3ff}
        .wrap{max-width:1100px;margin:0 auto}
        .card{background:#12151a;border:1px solid #1f2430;border-radius:14px;padding:16px;margin-bottom:16px}
        .row{display:flex;gap:16px;flex-wrap:wrap}
        label{display:block;font-weight:600;margin:8px 0 4px}
        input[type=file],select,input[type=text]{width:100%;padding:10px;border:1px solid #2a3140;border-radius:10px;background:#0f1318;color:#e8e8e8}
        .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #3a4358;background:#1a2030;color:#fff;text-decoration:none;font-weight:600}
        .btn:hover{filter:brightness(1.1)}
        table{width:100%;border-collapse:collapse;font-size:13px}
        th,td{border-bottom:1px solid #2a3140;padding:6px 8px;text-align:left}
        th{position:sticky;top:0;background:#0f1318;z-index:1}
        .scroll{max-height:420px;overflow:auto;border:1px solid #2a3140;border-radius:12px}
        .muted{opacity:.75}
        .ok{color:#76e39f}
        .warn{color:#ffd479}
        .err{color:#ff6e6e}
        .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #2a3140;margin-right:6px;background:#0f1318}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:16px}
        @media (max-width:800px){.grid{grid-template-columns:1fr}}
    </style>';
    echo "</head><body><div class=\"wrap\">";
}

function html_footer()
{
    echo "</div></body></html>";
}
function looks_like_date($s)
{
    $s = trim((string) $s);
    if ($s === '')
        return false;
    $formats = [
        'Y-m-d',
        'd.m.Y',
        'd/m/Y',
        'm/d/Y',
        'd.m.y',
        'm/d/y',
        'l, d F Y',
        'm.d.Y',
        'd.m.Y H:i:s',
        'm.d.Y h:i:s A',
        'Y-m-d H:i:s'
    ];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt) {
            $errs = $dt->getLastErrors();
            if (!$errs['warning_count'] && !$errs['error_count'])
                return true;
        }
    }
    if (preg_match('/\d{1,2}[\.\/]\d{1,2}[\.\/]\d{2,4}/', $s))
        return true;
    if (preg_match('/\d{4}-\d{2}-\d{2}/', $s))
        return true;
    return false;
}
// ---- Renderöi lomake ----
function render_form($PROFILES, $csrf)
{
    echo '<div class="card">';
    echo '<h2>Yhdistetty CSV-importeri</h2>';
    echo '<p class="muted">Valitse profiili, erotin ja lähetä CSV. Ensin näkyy esikatselu (oletuksena), jonka jälkeen voit suorittaa oikean tuonnin.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
    echo '<div class="grid">';

    echo '<div><label>Profiili</label><select name="profile" required>';
    foreach ($PROFILES as $key => $p) {
        echo '<option value="' . h($key) . '">' . h($p['label']) . '</option>';
    }
    echo '</select></div>';

    echo '<div><label>Erotin</label><select name="delimiter">';
    echo '<option value="auto">Automaattinen</option>';
    echo '<option value=";">Piste&komma (;)</option>';
    echo '<option value=",">Pilkku (,)</option>';
    echo '<option value="\t">Sarkain (TAB)</option>';
    echo '<option value="|">Pystyviiva (|)</option>';
    echo '</select></div>';

    echo '<div><label>Dry run / esikatselu</label><select name="mode">';
    echo '<option value="preview">Esikatselu</option>';
    echo '<option value="import">Tuo tietokantaan</option>';
    echo '</select></div>';

    echo '<div><label>CSV tiedosto</label><input type="file" name="csv" accept=".csv,text/csv" required></div>';

    echo '</div>'; // grid
    echo '<div style="margin-top:12px"><button class="btn" type="submit">Jatka</button></div>';
    echo '</form>';
    echo '</div>';
}

// ---- Näytä esikatselu ----
function render_preview($profileKey, $prof, $header, $rows, $delimiter, $table, $columns, $sqlExample)
{
    echo '<div class="card">';
    echo '<h3>Esikatselu – ' . h($prof['label']) . '</h3>';
    echo '<p class="muted">Erotin: <span class="pill">' . ($delimiter === "\t" ? 'TAB' : h($delimiter)) . '</span> Kohdetaulu: <span class="pill">' . h($table) . '</span></p>';

    echo '<div class="scroll"><table><thead><tr>';
    foreach ($header as $hcell)
        echo '<th>' . h($hcell) . '</th>';
    echo '</tr></thead><tbody>';
    $limit = min(50, count($rows));
    for ($i = 0; $i < $limit; $i++) {
        echo '<tr>';
        foreach ($rows[$i] as $cell)
            echo '<td>' . h($cell) . '</td>';
        echo '</tr>';
    }
    if (count($rows) > $limit) {
        echo '<tr><td class="muted" colspan="' . count($header) . '">…' . (count($rows) - $limit) . ' lisäriviä</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<details style="margin-top:10px"><summary><b>Näytä esimerkkikysely</b></summary>';
    echo '<pre style="white-space:pre-wrap">' . h($sqlExample) . "\n-- arvot sidotaan valmistelemalla (prepared statement)" . ';</pre>';
    echo '</details>';

    echo '<form method="post" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="csrf" value="' . h($_SESSION['csv_csrf']) . '">';
    echo '<input type="hidden" name="profile" value="' . h($profileKey) . '">';
    echo '<input type="hidden" name="delimiter" value="' . h($delimiter) . '">';
    // upotetaan koko alkuperäinen tiedosto base64:na, jotta ei tarvitse ladata uudelleen importia varten
    echo '<input type="hidden" name="mode" value="import">';
    echo '<input type="hidden" name="payload_b64" value="' . h(base64_encode(file_get_contents($_FILES['csv']['tmp_name']))) . '">';
    echo '<button class="btn" type="submit">Tuo nämä tiedot tauluun ' . h($table) . '</button>';
    echo '</form>';

    echo '</div>';
}

// ---- Päälogiikka ----
html_header();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    render_form($PROFILES, $csrf);
    html_footer();
    exit;
}

// POST
if (!hash_equals($_SESSION['csv_csrf'] ?? '', $_POST['csrf'] ?? '')) {
    echo '<div class="card"><p class="err">Virheellinen CSRF-tunniste.</p></div>';
    render_form($PROFILES, $csrf);
    html_footer();
    exit;
}

$profileKey = $_POST['profile'] ?? '';
$mode = $_POST['mode'] ?? 'preview';
if (!isset($PROFILES[$profileKey])) {
    echo '<div class="card"><p class="err">Tuntematon profiili.</p></div>';
    render_form($PROFILES, $csrf);
    html_footer();
    exit;
}
$prof = $PROFILES[$profileKey];
$table = $prof['table'];
$columns = $prof['columns'];

// Hanki CSV-data: esikatselussa tiedosto tulee uploadista, importissa nappia painetaan esikatselusta ja data tulee base64:sta
$tmpFile = null;
$cleanup = false;
if ($mode === 'import' && !empty($_POST['payload_b64'])) {
    $bin = base64_decode($_POST['payload_b64'], true);
    if ($bin === false) {
        echo '<div class="card"><p class="err">Virhe: esikatselun data ei ollut kelvollista.</p></div>';
        render_form($PROFILES, $csrf);
        html_footer();
        exit;
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'csvimp_');
    file_put_contents($tmpFile, $bin);
    $cleanup = true;
    $delimiter = $_POST['delimiter'] ?? 'auto';
} else {
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        echo '<div class="card"><p class="err">Tiedostoa ei vastaanotettu.</p></div>';
        render_form($PROFILES, $csrf);
        html_footer();
        exit;
    }
    $tmpFile = $_FILES['csv']['tmp_name'];
    $delimiter = $_POST['delimiter'] ?? 'auto';
}

// Päätä erotin
if ($delimiter === 'auto') {
    $firstLine = '';
    $fh = fopen($tmpFile, 'r');
    if ($fh) {
        $firstLine = fgets($fh) ?: '';
        fclose($fh);
    }
    $delimiter = detect_delimiter($firstLine);
}

$rows = parse_csv_rows($tmpFile, $delimiter, 500000); // sallitaan isoja tiedostoja
if ($cleanup && $tmpFile)
    @unlink($tmpFile);

if (count($rows) === 0) {
    echo '<div class="card"><p class="err">CSV-tiedosto oli tyhjä tai ei luettavissa.</p></div>';
    render_form($PROFILES, $csrf);
    html_footer();
    exit;
}

$header = array_shift($rows);
$headerNorm = array_map('normalize', $header);

// CSV -> kohdekolumnien indeksit profiilin header_mapin avulla
$map = []; // kohdekolumni => CSV-indeksi
foreach ($columns as $col) {
    $map[$col] = null;
}
foreach ($headerNorm as $i => $hn) {
    foreach ($prof['header_map'] as $from => $toCol) {
        if ($hn === normalize($from) && array_key_exists($toCol, $map) && $map[$toCol] === null) {
            $map[$toCol] = $i;
        }
    }
    // lisäksi suora 1:1 jos otsikko vastaa jo kohdekolumnin nimeä
    if (array_key_exists($hn, $map) && $map[$hn] === null) {
        $map[$hn] = $i;
    }
}

// Esimerkkikyselynäytölle
$sqlExample = build_insert_sql($table, $columns);

if ($mode === 'preview') {
    // Näytä esikatselu raakadatasta + kerro mihin tauluun ja millä kolumneilla mennään
    echo '<div class="card">';
    echo '<h3>Kenttäyhteenveto</h3>';
    echo '<ul class="muted">';
    foreach ($columns as $col) {
        $idx = $map[$col] ?? null;
        $label = $idx === null ? '<span class="err">(ei löydy otsikoista)</span>' : 'CSV-sarake #' . ($idx + 1) . ' "' . h($header[$idx] ?? '?') . '"';
        echo '<li><b>' . h($col) . '</b>: ' . $label . '</li>';
    }
    echo '</ul>';
    echo '</div>';

    render_preview($profileKey, $prof, $header, $rows, $delimiter, $table, $columns, $sqlExample);
    html_footer();
    exit;
}

// ---- TUONTI ----
try {
    $pdo = open_db($GLOBALS['DB_HOST'], $GLOBALS['DB_NAME'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $GLOBALS['DB_CHARSET']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $sql = build_insert_sql($table, $columns);
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    $failed = 0;
    $lineNo = 1;
    $errors = [];
    $rows = array_values(array_filter($rows, fn($row) => !is_repeat_header_row($row, $profileKey)));
    
    foreach ($rows as $r) {
        
        $lineNo++;
        // --- OAS: ohita toistuvat otsikkorivit kokonaan ---
        if (is_repeat_header_row($r, $profileKey)) {
            // ei kasvateta $failed -laskuria, nämä eivät ole virheitä
            continue;
        }
        // OAS: tarkista puuttuuko Contract nr (3. kenttä), jos 3. kenttä näyttää päivämäärältä -> shiftataan sarakkeet vasemmalle yhdellä
        $rowMap = $map;
        if ($profileKey === 'oas' && isset($map['contract_nr'])) {
            $cnIdx = $map['contract_nr']; // pitäisi olla 3. sarake CSV:ssä
            if ($cnIdx !== null) {
                $thirdVal = safe_get($r, $cnIdx, '');
                if (looks_like_date($thirdVal)) {
                    // contract_nr puuttuu -> siirrä loput yhden vasemmalle
                    $rowMap['contract_nr'] = null;                       // jää tyhjäksi
                    $rowMap['date_played'] = $map['contract_nr'];
                    $rowMap['hours'] = $map['date_played'];
                    $rowMap['block'] = $map['hours'];
                    $rowMap['code'] = $map['block'];
                    $rowMap['commercial'] = $map['code'];
                    $rowMap['length_sec'] = $map['commercial'];
                    $rowMap['edition'] = $map['length_sec'];
                }
            }
        }
        $vals = [];
        foreach ($columns as $col) {
            $idx = $rowMap[$col] ?? null;
            $val = $idx === null ? '<empty>' : safe_get($r, $idx, '<empty>');
            /* … jatkuu normaalit OATV/OVERNIGHT/OAS normalisoinnit … */
            // $vals[] = $val;

            /* -------------------- OATV: date & time normalisointi -------------------- */
            if ($profileKey === 'oatv') {
                // date -> Y-m-d (NULL jos ei parsittavissa)
                if ($col === 'date' && $val && $val !== '<empty>') {
                    $raw = trim((string) $val);
                    $parsed = null;
                    foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd.m.y', 'm/d/y'] as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, $raw);
                        $errs = $dt ? $dt->getLastErrors() : null;
                        if ($dt !== false) {
                            $parsed = $dt;
                            break;
                        }
                    }
                    if (!$parsed) {
                        $ts = strtotime($raw);
                        if ($ts !== false)
                            $parsed = (new DateTime())->setTimestamp($ts);
                    }
                    $val = $parsed ? $parsed->format('Y-m-d') : null;
                }

                // time -> H:i:s (tukee 9:05, 9:05:30, 9:05 AM, 09.05.00, jne.)
                if ($col === 'time' && $val && $val !== '<empty>') {
                    $src = trim((string) $val);
                    $parsed = null;
                    foreach (['H:i:s', 'H:i', 'g:i A', 'g:i:s A', 'H.i.s', 'H.i'] as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, $src);
                        $errs = $dt ? $dt->getLastErrors() : null;
                        if ($dt !== false) {
                            $parsed = $dt;
                            break;
                        }
                    }
                    if (!$parsed) {
                        if (preg_match('/(\d{1,2}[:\.]\d{2}(?::\d{2})?\s*(?:[AP]M)?)/i', $src, $m)) {
                            $ts = strtotime(str_replace('.', ':', $m[1]));
                            if ($ts !== false)
                                $parsed = (new DateTime())->setTimestamp($ts);
                        } else {
                            $ts = strtotime(str_replace('.', ':', $src));
                            if ($ts !== false)
                                $parsed = (new DateTime())->setTimestamp($ts);
                        }
                    }
                    $val = $parsed ? $parsed->format('H:i:s') : null;
                }
            }

            /* ------------- OVERNIGHT (overnightreport): päivämäärä & kellonajat ------------- */
            if ($profileKey === 'overnight') {
                // Numeriset: pilkku -> piste, tyhjät -> NULL
                if (in_array($col, ['over60_on_channel', 'avg_viewers', 'share_pct'], true)) {
                    $val = strtr((string) $val, [',' => '.', ' ' => '']);
                    if ($val === '' || $val === '<empty>')
                        $val = null;
                }

                // report_date -> Y-m-d
                if ($col === 'report_date' && $val && $val !== '<empty>') {
                    $raw = trim((string) $val);
                    $parsed = null;
                    foreach (['l, d F Y', 'd.m.Y', 'm.d.Y', 'Y-m-d'] as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, $raw);
                        $errs = $dt ? $dt->getLastErrors() : null;
                        if ($dt !== false) {
                            $parsed = $dt;
                            break;
                        }
                    }
                    if (!$parsed) {
                        $ts = strtotime($raw);
                        if ($ts !== false)
                            $parsed = (new DateTime())->setTimestamp($ts);
                    }
                    if ($parsed)
                        $val = $parsed->format('Y-m-d');
                }

                // start_time / end_time -> H:i:s (tai halutessasi Y-m-d H:i:s – katso kommentti)
                if (($col === 'start_time' || $col === 'end_time') && $val && $val !== '<empty>') {
                    $src = trim((string) $val);
                    $parsed = null;
                    foreach (['m.d.Y h:i:s A', 'd.m.Y H:i:s', 'Y-m-d H:i:s'] as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, $src);
                        $errs = $dt ? $dt->getLastErrors() : null;
                        if ($dt !== false) {
                            $parsed = $dt;
                            break;
                        }
                    }
                    if (!$parsed) {
                        $ts = strtotime($src);
                        if ($ts !== false)
                            $parsed = (new DateTime())->setTimestamp($ts);
                    }
                    if ($parsed) {
                        // Jos overnightreport.start_time / end_time on TIME-tyyppiä:
                        // $val = $parsed->format('H:i:s');
                        // Jos ne ovat DATETIME-tyyppiä, käytä tätä:
                        $val = $parsed->format('Y-m-d H:i:s');
                    } else {
                        // Viimeinen yritys: nouki pelkkä aika ja konvertoi 24h muotoon
                        if (preg_match('/(\d{1,2}:\d{2}:\d{2}\s*[AP]M|\d{1,2}:\d{2}:\d{2})/i', $src, $m)) {
                            $ts = strtotime($m[1]);
                            if ($ts !== false)
                                $val = date('H:i:s', $ts);
                        }
                    }
                }
            }
            // --- OAS: normalisoi date (DATE), hour (INT 0-23), length (sekunteja) ---
            if ($profileKey === 'oas') {
                //$rows = array_values(array_filter($rows, fn($row) => !is_repeat_header_row($row, $profileKey)));
                // 1) Päivä -> Y-m-d
                if ($col === 'date_played' && $val && $val !== '<empty>') {
                    $raw = trim((string) $val);
                    $parsed = null;
                    foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd.m.y', 'm/d/y'] as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, $raw);
                        $errs = $dt ? $dt->getLastErrors() : null;
                        if ($dt !== false) {
                            $parsed = $dt;
                            break;
                        }
                    }
                    if (!$parsed) {
                        $ts = strtotime($raw);
                        if ($ts !== false)
                            $parsed = (new DateTime())->setTimestamp($ts);
                    }
                    $val = $parsed ? $parsed->format('Y-m-d') : null; // mieluummin NULL kuin 0000-00-00
                }

                // 2) Hour -> integer 0..23 (CSV:ssä usein 7, 08, 15, tai "15:00")
                if ($col === 'hours' && $val && $val !== '<empty>') {
                    $raw = trim((string) $val);
                    if (preg_match('/^(\d{1,2})(?::\d{2})?$/', $raw, $m)) {
                        $h = (int) $m[1];
                    } else {
                        $h = (int) preg_replace('/\D+/', '', $raw);
                    }
                    if ($h < 0 || $h > 23)
                        $h = null;
                    $val = $h;
                }

                // 3) Length -> sekunteja (INT)
                if ($col === 'length_sec' && $val && $val !== '<empty>') {
                    $secs = duration_to_seconds($val);
                    $val = $secs !== null ? $secs : null;
                }
            }
            $vals[] = $val;
        }

        try {
            $stmt->execute($vals);
            $inserted++;
        } catch (Throwable $e) {
            $failed++;
            // Tallenna virhe ensimmäisiltä riveiltä näkyviin
            if (count($errors) < 20)
                $errors[] = "Rivi {$lineNo}: " . $e->getMessage();
        }
    }

    $pdo->commit();

    echo '<div class="card">';
    echo '<h3>Tuonti valmis</h3>';
    echo '<p class="ok">Lisätyt rivit: ' . h($inserted) . '</p>';
    if ($failed)
        echo '<p class="warn">Epäonnistuneet rivit: ' . h($failed) . '</p>';
    if ($errors) {
        echo '<details><summary><b>Ensimmäiset virheet</b></summary><ul>';
        foreach ($errors as $er)
            echo '<li class="err">' . h($er) . '</li>';
        echo '</ul></details>';
    }
    echo '<p style="margin-top:12px"><a class="btn" href="' . h($_SERVER['PHP_SELF']) . '">Uusi tuonti</a></p>';
    echo '</div>';

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    echo '<div class="card"><h3>Virhe tuonnissa</h3><p class="err">' . h($e->getMessage()) . '</p>';
    echo '<p><a class="btn" href="' . h($_SERVER['PHP_SELF']) . '">Takaisin</a></p></div>';
}

html_footer();

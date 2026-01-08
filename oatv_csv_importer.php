<?php
// oatv_import.php — CSV → MySQL importer for table th_eveo.oatv, with delimiter choice & preview
// PHP 8.1+
// ---------------------------------------------------------------------
// QUICK CONFIG
const DB_HOST = 'www.fissi.fi';
const DB_NAME = 'fissifi_eveo';
const DB_USER = 'fissifi_eveo_admin';
const DB_PASS = '23ufa3}A1CaG%Ica';
const DB_CHARSET = 'utf8mb4';

// Optional: max rows to preview in the result summary table
const SUMMARY_PREVIEW_ROWS = 50;
// Default preview rows shown in header preview
const DEFAULT_HEADER_PREVIEW_ROWS = 3;

// ---------------------------------------------------------------------
// Expected columns in CSV (case-insensitive). Extra columns are ignored.
$EXPECTED = ['channel', 'artist', 'title', 'date', 'time', 'type', 'duration', 'hour'];

// HTML helper (very small, no external CSS)
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// DB connect via PDO
function pdo(): PDO
{
    static $pdo;
    if ($pdo)
        return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET . ';options=--local-infile=0';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '" . DB_CHARSET . "' COLLATE 'utf8mb4_unicode_ci'"
    ]);
    return $pdo;
}

// Create table if not exists
function ensure_table(): void
{
    $sql = <<<SQL
    CREATE TABLE IF NOT EXISTS `oatv` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `channel`  VARCHAR(64)   NOT NULL,
      `artist`   VARCHAR(255)  NULL,
      `title`    VARCHAR(255)  NULL,
      `date`     DATE          NULL,
      `time`     TIME          NULL,
      `type`     VARCHAR(64)   NULL,
      `duration` TIME          NULL,
      `hour`     TINYINT UNSIGNED NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_date_time` (`date`,`time`),
      KEY `idx_channel_date` (`channel`,`date`),
      KEY `idx_hour` (`hour`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;
    pdo()->exec($sql);
}

// Normalize a CSV header/field name
function norm_col(string $s): string
{
    return strtolower(trim($s));
}

// Poista UTF-8 BOM alusta, jos sellainen on
function strip_bom(string $s): string
{
    return strncmp($s, "\xEF\xBB\xBF", 3) === 0 ? substr($s, 3) : $s;
}

// Alias-nimet → standardi-avaimet (laajenna tarpeen mukaan)
function alias_col(string $norm): string
{
    static $ALIASES = [
    'kanava' => 'channel',
    'esittäjä' => 'artist',
    'artistti' => 'artist',
    'kappale' => 'title',
    'nimi' => 'title',
    'päivä' => 'date',
    'paiva' => 'date',
    'päivämäärä' => 'date',
    'paivamaara' => 'date',
    'aika' => 'time',
    'tyyppi' => 'type',
    'kesto' => 'duration',
    'tunti' => 'hour',
    ];
    return $ALIASES[$norm] ?? $norm;
}

// Detect delimiter from a text line (tries ; , \t |). Returns string delimiter char.
function detect_delimiter_from_line(string $line): string
{
    $candidates = [
        ';' => substr_count($line, ';'),
        ',' => substr_count($line, ','),
        "\t" => substr_count($line, "\t"),
        '|' => substr_count($line, '|'),
    ];
    arsort($candidates);
    $best = array_key_first($candidates);
    return $best ?: ',';
}

function label_for_delim(string $d): string
{
    return match ($d) {
        ';' => 'puolipiste (;)',
        ',' => 'pilkku (,)',
        "\t" => 'sarkain (TAB)',
        '|' => 'pystyviiva (|)',
        default => 'tuntematon',
    };
}

// Parse date from common FI/EU/ISO formats -> YYYY-MM-DD or null
function parse_date(?string $s): ?string
{
    if ($s === null)
        return null;
    $t = trim($s);
    if ($t === '')
        return null;
    // Try ISO first
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $t))
        return $t;
    // Try dd.mm.yyyy
    if (preg_match('~^(\d{1,2})[\.](\d{1,2})[\.]?(\d{2,4})$~', $t, $m)) {
        $d = (int) $m[1];
        $M = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100)
            $y += 2000;
        return sprintf('%04d-%02d-%02d', $y, $M, $d);
    }
    // Try dd/mm/yyyy
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2,4})$~', $t, $m)) {
        $d = (int) $m[1];
        $M = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100)
            $y += 2000;
        return sprintf('%04d-%02d-%02d', $y, $M, $d);
    }
    // Fallback: strtotime
    $ts = strtotime($t);
    return $ts ? date('Y-m-d', $ts) : null;
}

// Parse time (HH:MM[:SS]) -> HH:MM:SS or null
function parse_time(?string $s): ?string
{
    if ($s === null)
        return null;
    $t = trim($s);
    if ($t === '')
        return null;
    if (preg_match('~^(\d{1,2}):(\d{2})(?::(\d{2}))?$~', $t, $m)) {
        $h = (int) $m[1];
        $i = (int) $m[2];
        $sec = isset($m[3]) ? (int) $m[3] : 0;
        return sprintf('%02d:%02d:%02d', $h, $i, $sec);
    }
    // Also accept e.g. "930" -> 09:30:00
    if (preg_match('~^(\d{1,2})(\d{2})$~', $t, $m)) {
        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], 0);
    }
    return null;
}

// Parse duration which could be mm:ss, h:mm:ss, HH:MM:SS, or seconds -> TIME
function parse_duration(?string $s): ?string
{
    if ($s === null)
        return null;
    $t = trim($s);
    if ($t === '')
        return null;
    if (ctype_digit($t)) { // seconds
        $sec = (int) $t;
        $h = intdiv($sec, 3600);
        $sec -= $h * 3600;
        $m = intdiv($sec, 60);
        $sec -= $m * 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
    }
    if (preg_match('~^(\d+):(\d{2})(?::(\d{2}))?$~', $t, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $c = isset($m[3]) ? (int) $m[3] : 0;
        // if two-part, treat as mm:ss; if three-part, h:mm:ss
        if (!isset($m[3])) {
            $h = intdiv($a, 60);
            $min = $a % 60;
            $sec = $b;
            return sprintf('%02d:%02d:%02d', $h, $min, $sec);
        }
        return sprintf('%02d:%02d:%02d', $a, $b, $c);
    }
    return null;
}

// Derive hour 0–23 from time string when missing
function derive_hour(?string $time, ?string $hour): ?int
{
    $hour = trim((string) ($hour ?? ''));
    if ($hour !== '' && ctype_digit($hour)) {
        $h = (int) $hour;
        return ($h >= 0 && $h <= 23) ? $h : null;
    }
    if ($time && preg_match('~^(\d{2}):~', $time, $m))
        return (int) $m[1];
    return null;
}

// Read CSV header & preview rows
function read_csv_header_and_preview(string $filepath, ?string $chosenDelim = null, int $previewRows = DEFAULT_HEADER_PREVIEW_ROWS): array
{
    $fh = fopen($filepath, 'r');
    if (!$fh)
        throw new RuntimeException('Failed to open uploaded file');

    // Peek first non-empty line to detect delimiter if needed
    $firstLine = '';
    while (!feof($fh) && $firstLine === '') {
        $firstLine = (string) fgets($fh);
        $firstLine = trim($firstLine, "\r\n");
    }
    if ($firstLine === '')
        throw new RuntimeException('CSV file is empty');
    $detected = detect_delimiter_from_line($firstLine);
    $delimiter = $chosenDelim ?: $detected;

    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter, '"', "\\");
    if ($header === false)
        throw new RuntimeException('Unable to read CSV header');

    $mapIdxToNorm = [];
    $normHeader = [];
    foreach ($header as $i => $name) {
        $nm = strip_bom((string) $name);
        $norm = alias_col(norm_col($nm));
        $mapIdxToNorm[$i] = $norm;
        $normHeader[] = $norm;
    }

    $rows = [];
    $count = 0;
    while ($count < $previewRows && ($row = fgetcsv($fh, 0, $delimiter, '"', "\\")) !== false) {
        $rows[] = $row;
        $count++;
    }
    fclose($fh);

    return [
        'delimiter' => $delimiter,
        'detected' => $detected,
        'header_raw' => $header,
        'header_norm' => $normHeader,
        'mapIdxToNorm' => $mapIdxToNorm,
        'preview_rows' => $rows,
    ];
}

// Read full CSV rows as associative arrays with known columns only
function read_csv_rows(string $filepath, ?string $chosenDelim = null): array
{
    $fh = fopen($filepath, 'r');
    if (!$fh)
        throw new RuntimeException('Failed to open uploaded file');

    // Detect delimiter if needed
    $firstLine = '';
    while (!feof($fh) && $firstLine === '') {
        $firstLine = (string) fgets($fh);
        $firstLine = trim($firstLine, "\r\n");
    }
    if ($firstLine === '')
        throw new RuntimeException('CSV file is empty');
    $detected = detect_delimiter_from_line($firstLine);
    $delimiter = $chosenDelim ?: $detected;

    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter, '"', "\\");
    if ($header === false)
        throw new RuntimeException('Unable to read CSV header');

    $map = [];
    foreach ($header as $i => $name) {
        $nm = strip_bom((string) $name);
        $col = alias_col(norm_col($nm));
        $map[$i] = $col;
    }

    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter, '"', "\\")) !== false) {
        $assoc = [];
        foreach ($row as $i => $val) {
            $col = $map[$i] ?? null;
            if ($col && in_array($col, $GLOBALS['EXPECTED'], true)) {
                $assoc[$col] = $val;
            }
        }
        if (!array_filter($assoc, fn($v) => trim((string) $v) !== ''))
            continue; // skip empty
        $rows[] = $assoc;
    }
    fclose($fh);

    return [$rows, $map, $delimiter];
}

// Import logic
function import_csv(string $filepath, bool $truncate, ?string $chosenDelim = null, string $forceChannel = ''): array
{
    ensure_table();
    $pdo = pdo();

    if ($truncate) {
        $pdo->exec('TRUNCATE TABLE `oatv`');
    }

    [$rows, $map] = read_csv_rows($filepath, $chosenDelim);

    $ins = $pdo->prepare('INSERT INTO `oatv` (`channel`,`artist`,`title`,`date`,`time`,`type`,`duration`,`hour`) VALUES (?,?,?,?,?,?,?,?)');

    $inserted = 0;
    $skipped = 0;
    $errors = [];
    $preview = [];

    $pdo->beginTransaction();
    try {
        foreach ($rows as $idx => $r) {
            // Normalize fields
            $channel = trim((string) ($r['channel'] ?? ''));
            if ($channel === '' && $forceChannel !== '') {
                $channel = $forceChannel;
            }
            $artist = trim((string) ($r['artist'] ?? ''));
            $title = trim((string) ($r['title'] ?? ''));
            $date = parse_date($r['date'] ?? null);
            $time = parse_time($r['time'] ?? null);
            $type = trim((string) ($r['type'] ?? ''));
            $duration = parse_duration($r['duration'] ?? null);
            $hour = derive_hour($time, $r['hour'] ?? null);

            // --- VALIDATION ---
            // 1) Channel on pakollinen (DB:ssä NOT NULL)
            if ($channel === '') {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 2) . ": 'channel' puuttuu (taulu vaatii sen).";
                continue;
            }
            // 2) Vähintään title TAI (artist + title)
            if ($title === '' && $artist === '') {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 2) . ": puuttuu avainkenttiä (title ja/tai artist).";
                continue;

            }
            try {
                // Channel ei saa olla NULL
                $ins->execute([$channel, $artist ?: null, $title ?: null, $date, $time, $type ?: null, $duration, $hour]);
                $inserted++;
                if ($inserted <= SUMMARY_PREVIEW_ROWS) {
                    $preview[] = compact('channel', 'artist', 'title', 'date', 'time', 'type', 'duration', 'hour');
                }
            } catch (Throwable $te) {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 2) . ": " . $te->getMessage();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { 
        $pdo->rollBack();
        throw $e;
    }

    return [
        'total' => count($rows),
        'inserted' => $inserted,
        'skipped' => $skipped,
        'errors' => $errors,
        'preview' => $preview,
        'delimiter' => $chosenDelim // voi olla null jos auto, UI voi näyttää "automaattinen"
    ];
}

// ---------------------------------------------------------------------
// Handle request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$err = null;
$previewInfo = null;
$res = null;

if ($method === 'POST') {
    $action = $_POST['action'] ?? 'import';
    $truncate = isset($_POST['truncate']) && $_POST['truncate'] === '1';
    $forceChannel = trim((string) ($_POST['force_channel'] ?? ''));
    $delimSel = $_POST['delimiter'] ?? 'auto';
    $chosenDelim = null;
    if ($delimSel === 'comma')
        $chosenDelim = ',';
    elseif ($delimSel === 'semicolon')
        $chosenDelim = ';';
    elseif ($delimSel === 'tab')
        $chosenDelim = "\t";
    elseif ($delimSel === 'pipe')
        $chosenDelim = '|';
    // else auto -> null

    $previewRows = max(1, (int) ($_POST['preview_rows'] ?? DEFAULT_HEADER_PREVIEW_ROWS));

    if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = 'Tiedoston lataus epäonnistui.';
    } else {
        $tmp = $_FILES['csv']['tmp_name'];
        $name = (string) ($_FILES['csv']['name'] ?? 'data.csv');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $err = 'Sallitut tiedostomuodot: CSV tai TXT.';
        } else {
            try {
                if ($action === 'preview') {
                    $previewInfo = read_csv_header_and_preview($tmp, $chosenDelim, $previewRows);
                } else { // import
                    $res = import_csv($tmp, $truncate, $chosenDelim ?? null, $forceChannel);
                }
            } catch (Throwable $e) {
                $err = ($action === 'preview' ? 'Esikatselu epäonnistui: ' : 'Tuonti epäonnistui: ') . $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------
// Render HTML (simple, vanilla CSS)
?>
<!DOCTYPE html>
<html lang="fi">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>OATV – CSV tuonti</title>
    <style>
        :root {
            color-scheme: light dark;
        }

        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            margin: 24px;
        }

        h1 {
            margin: 0 0 12px;
        }

        form {
            border: 1px solid #bbb;
            border-radius: 12px;
            padding: 16px;
            max-width: 860px;
        }

        .row {
            margin: 10px 0;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .row label {
            margin-right: 12px;
        }

        .hint {
            color: #666;
            font-size: 0.95em;
        }

        .btn {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid #888;
            border-radius: 10px;
            cursor: pointer;
            background: #f3f3f3;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .danger {
            background: #ffe9e9;
            border-color: #d66;
        }

        .summary {
            margin-top: 24px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #bbb;
            padding: 6px 8px;
            text-align: left;
        }

        th {
            background: #eee;
        }

        .ok {
            color: #0a6;
            font-weight: 600;
        }

        .warn {
            color: #b60;
        }

        .err {
            color: #b00;
        }

        details {
            margin-top: 10px;
        }

        code {
            background: #eee;
            padding: 2px 4px;
            border-radius: 6px;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 8px 0;
        }

        .chip {
            padding: 3px 8px;
            border-radius: 12px;
            border: 1px solid #bbb;
            font-size: 12px;
        }

        .chip.ok {
            border-color: #0a6;
            color: #0a6;
        }

        .chip.missing {
            border-color: #b00;
            color: #b00;
        }

        .section {
            max-width: 1000px;
        }
    </style>
</head>

<body>
    <h1>OATV – CSV tuonti</h1>
    <p class="hint">Vie CSV-tiedosto <code>th_eveo.oatv</code>-tauluun. Ensimmäisen rivin tulee olla otsikko (esim.
        <code>channel,artist,title,date,time,type,duration,hour</code>). Erotin voi olla pilkku, puolipiste, sarkain tai
        pystyviiva.
    </p>

    <form method="post" enctype="multipart/form-data" class="section">
        <div class="row">
            <label>Tiedosto (CSV): <input type="file" name="csv" accept=".csv,.txt" required></label>
        </div>
        <div class="row">
            <label>Pakota channel (jos puuttuu/tyhjä): <input type="text" name="force_channel"
                    placeholder="esim. OATV" /></label>
        </div>
        <div class="row">
            <label>Erottimen valinta:
                <select name="delimiter">
                    <option value="auto" selected>Automaattinen</option>
                    <option value="comma">Pilkku (,)</option>
                    <option value="semicolon">Puolipiste (;)</option>
                    <option value="tab">Sarkain (TAB)</option>
                    <option value="pipe">Pystyviiva (|)</option>
                </select>
            </label>
            <label>Näytä esikatselussa
                <input type="number" name="preview_rows" min="1" max="20"
                    value="<?= (int) DEFAULT_HEADER_PREVIEW_ROWS ?>" style="width:60px;">
                riviä
            </label>
        </div>
        <div class="row">
            <label><input type="checkbox" name="truncate" value="1"> Tyhjennä taulu ennen tuontia (TRUNCATE)</label>
        </div>
        <div class="row">
            <button class="btn" type="submit" name="action" value="preview">Esikatsele</button>
            <button class="btn" type="submit" name="action" value="import">Tuo CSV</button>
            <button class="btn danger" type="reset">Tyhjennä lomake</button>
        </div>
    </form>

    <?php if ($err): ?>
        <p class="err"><strong>Virhe:</strong> <?= h($err) ?></p>
    <?php endif; ?>

    <?php if ($previewInfo && !$err): ?>
        <?php
        $used = $previewInfo['delimiter'];
        $det = $previewInfo['detected'];
        $raw = $previewInfo['header_raw'];
        $norm = $previewInfo['header_norm'];
        $rows = $previewInfo['preview_rows'];

        $expected = $EXPECTED;
        $foundSet = array_fill_keys($norm, true);
        $missing = array_values(array_diff($expected, $norm));
        $present = array_values(array_intersect($expected, $norm));
        ?>
        <div class="summary section">
            <h2>Esikatselu</h2>
            <p>Käytetty erotin: <strong><?= h(label_for_delim($used)) ?></strong>
                <?= $used !== $det ? '(automaattinen havainto: ' . h(label_for_delim($det)) . ')' : '(automaattinen havainto)' ?>
            </p>

            <h3>Löydetty otsikkorivi</h3>
            <p><strong>Alkuperäiset nimet:</strong></p>
            <div class="chips">
                <?php foreach ($raw as $col): ?>
                    <span class="chip"><?= h((string) $col) ?></span>
                <?php endforeach; ?>
            </div>

            <p><strong>Normalisoidut (vertailu odotettuihin):</strong></p>
            <div class="chips">
                <?php foreach ($norm as $col): ?>
                    <?php $ok = in_array($col, $expected, true); ?>
                    <span class="chip <?= $ok ? 'ok' : 'missing' ?>"><?= h($col) ?></span>
                <?php endforeach; ?>
            </div>

            <p><strong>Odotetut sarakkeet:</strong></p>
            <div class="chips">
                <?php foreach ($present as $c): ?>
                    <span class="chip ok"><?= h($c) ?></span>
                <?php endforeach; ?>
                <?php foreach ($missing as $c): ?>
                    <span class="chip missing" title="Ei löytynyt otsikosta"><?= h($c) ?></span>
                <?php endforeach; ?>
            </div>

            <?php if ($rows): ?>
                <h3>Ensimmäiset <?= (int) count($rows) ?> tietoriviä (raakana CSV-kenttinä)</h3>
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($raw as $c): ?>
                                <th><?= h((string) $c) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <?php foreach ($r as $cell): ?>
                                    <td><?= h((string) $cell) ?></td><?php endforeach; ?>
                                <?php if (count($r) < count($raw)): // pad if short ?>
                                    <?php for ($i = count($r); $i < count($raw); $i++): ?>
                                        <td></td><?php endfor; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="hint">Vinkki: jos sarakkeet “liukuvat”, vaihda erotin tai tarkista lainausmerkit/erikoismerkit
                    lähdetiedostossa.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($res && !$err): ?>
        <div class="summary section">
            <p><span class="ok">Onnistui!</span> Rivejä yhteensä: <strong><?= (int) $res['total'] ?></strong>, lisätty:
                <strong><?= (int) $res['inserted'] ?></strong>, ohitettu/virhe:
                <strong><?= (int) $res['skipped'] ?></strong>.
                (Erotin: <em><?= h(label_for_delim($res['delimiter'] ?? ',')) ?></em>)
            </p>

            <?php if ($res['preview']): ?>
                <h3>Esikatselu (ensimmäiset <?= (int) min(count($res['preview']), SUMMARY_PREVIEW_ROWS) ?> riviä normalisoituna)
                </h3>
                <table>
                    <thead>
                        <tr>
                            <th>channel</th>
                            <th>artist</th>
                            <th>title</th>
                            <th>date</th>
                            <th>time</th>
                            <th>type</th>
                            <th>duration</th>
                            <th>hour</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($res['preview'] as $row): ?>
                            <tr>
                                <td><?= h($row['channel']) ?></td>
                                <td><?= h($row['artist']) ?></td>
                                <td><?= h($row['title']) ?></td>
                                <td><?= h($row['date']) ?></td>
                                <td><?= h($row['time']) ?></td>
                                <td><?= h($row['type']) ?></td>
                                <td><?= h($row['duration']) ?></td>
                                <td><?= h((string) $row['hour']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($res['errors']): ?>
                <details>
                    <summary class="warn">Näytä virherivit (<?= count($res['errors']) ?>)</summary>
                    <ul>
                        <?php foreach ($res['errors'] as $e): ?>
                            <li class="err"><?= h($e) ?></li><?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <hr>
    <details class="section">
        <summary>Tekniset tiedot ja vinkit</summary>
        <ul>
            <li>Taulu luodaan automaattisesti jos sitä ei ole. Tietotyypit: <code>DATE</code>, <code>TIME</code>, ja
                <code>hour</code> = 0–23.
            </li>
            <li><strong>date</strong> hyväksyy muodot: <code>YYYY-MM-DD</code>, <code>dd.mm.yyyy</code>,
                <code>dd/mm/yyyy</code>.
            </li>
            <li><strong>time</strong> hyväksyy muodot: <code>HH:MM</code>, <code>HH:MM:SS</code>, myös <code>930</code>
                → <code>09:30:00</code>.</li>
            <li><strong>duration</strong> hyväksyy sekunnit (<code>225</code>) tai ajat <code>mm:ss</code> /
                <code>h:mm:ss</code> → tallennetaan <code>TIME</code>-kenttään.
            </li>
            <li><strong>hour</strong> lasketaan automaattisesti <em>time</em>-kentästä jos sitä ei ole erikseen annettu.
            </li>
            <li>Ensimmäisen rivin tulee olla otsikko. Tuntemattomat ylimääräiset sarakkeet ohitetaan.</li>
            <li>Jos esikatselussa sarakkeet näyttävät “siirtyvän”, vaihda erotin tai varmista että kentät on
                lainausmerkeissä jos niissä on erottimia.</li>
        </ul>
    </details>
</body>

</html>
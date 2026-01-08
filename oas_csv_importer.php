<?php
// oas_csv_importer.php — CSV -> th_eveo.oas
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: text/html; charset=utf-8');

// --- DB-asetukset ---
$DB_HOST = 'www.fissi.fi';
$DB_NAME = 'fissifi_eveo';
$DB_USER = 'fissifi_eveo_admin';
$DB_PASS = '23ufa3}A1CaG%Ica';
$DSN = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

function pdo(): PDO
{
    global $DSN, $DB_USER, $DB_PASS;
    static $pdo = null;
    if ($pdo)
        return $pdo;
    $pdo = new PDO($DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    return $pdo;
}

// --- Apurit ---
function autodetect_delimiter(string $line): string
{
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    $t = substr_count($line, "\t");
    $max = max($c, $s, $t);
    if ($max === 0)
        return ',';
    if ($max === $s)
        return ';';
    if ($max === $t)
        return "\t";
    return ',';
}
function is_header_row(array $row): bool
{
    // Trim + lower
    $cells = array_map(static function ($v) {
        return is_string($v) ? strtolower(trim($v)) : $v;
    }, $row);

    // Jos rivillä esiintyy "account" ja "campaign" jossain sarakkeessa, pidä otsikkona.
    $hasAccount  = in_array('account',  $cells, true);
    $hasCampaign = in_array('campaign', $cells, true);

    // Lisäksi tyypillisiä nimiä muille kentille, jotta varmuus kasvaa
    $names = ['contract nr','contract_nr','date played','date_played','hours','block','code','commercial','length','edition'];
    $nameHit = 0;
    foreach ($names as $n) {
        if (in_array($n, $cells, true)) $nameHit++;
    }

    return ($hasAccount && $hasCampaign) || $nameHit >= 3;
}
function parse_date(?string $s): ?string
{
    if (!$s)
        return null;
    $s = trim($s);
    $candidates = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'Y.m.d', 'd-m-Y', 'Y/m/d'];
    foreach ($candidates as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s)
            return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}
function parse_length_to_seconds(?string $s): ?int
{
    if ($s === null)
        return null;
    $s = trim($s);
    if ($s === '')
        return null;
    if (ctype_digit($s))
        return (int) $s;
    if (preg_match('~^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})$~', $s, $m))
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    if (preg_match('~^(\d{1,2}):(\d{2})$~', $s, $m))
        return (int) $m[1] * 60 + (int) $m[2];
    if (preg_match('~^(?:(\d+)m)?(?:(\d+)s(?:ec)?)?$~i', $s, $m)) {
        $mVal = (int) ($m[1] ?? 0);
        $sVal = (int) ($m[2] ?? 0);
        if ($mVal + $sVal > 0)
            return $mVal * 60 + $sVal;
    }
    return null;
}
function split_campaign_contract(string $field): array
{
    $f = trim($field);
    if (preg_match('~^(.*?)[\s\-\#]*(\d{4,10})$~u', $f, $m))
        return [trim($m[1]), trim($m[2])];
    if (str_contains($f, ' / '))
        return array_map('trim', explode(' / ', $f, 2));
    if (str_contains($f, ' - '))
        return array_map('trim', explode(' - ', $f, 2));
    return [$f, null];
}
function normalize_row(array $row, int $rowNum, array &$errors): ?array
{
    $row = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row);
    $n = count($row);
    if ($n < 8) {
        $errors[] = "Rivi {$rowNum}: liian vähän sarakkeita ({$n}).";
        return null;
    }

    // Apu: onko "tunti" – sallitaan 0–23 (tai 0–99 varmuuden vuoksi)
    $isHour = static function ($v): bool {
        if ($v === '' || $v === null) return false;
        if (!preg_match('~^\d{1,2}$~', (string)$v)) return false;
        $iv = (int)$v;
        return $iv >= 0 && $iv <= 99; // useimmiten 0–23, mutta ei lukita liian tiukasti
    };

    // --- CASE B: "Contract puuttuu" -muoto (teidän CSV) ---
    // Tyypilliset arvot:
    // [0]Account, [1]Campaign, [2]Date, [3]Hour, [4]Block, [5]Code, [6]Commercial, [7]Length, [8]Edition?, [9]? (tyhjä)
    $dateAt2 = parse_date($row[2] ?? null);
    if ($dateAt2 !== null && $isHour($row[3] ?? null)) {
        // 9 tai 10 saraketta → edition voi puuttua
        $edition = $row[8] ?? null;
        if ($n >= 9) {
            return [
                $row[0],           // account
                $row[1],           // campaign
                null,              // contract_nr (puuttuu)
                $row[2],           // date
                $row[3],           // hours
                $row[4],           // block
                $row[5],           // code
                $row[6],           // commercial
                $row[7],           // length_raw
                $edition           // edition
            ];
        } else {
            // 8 saraketta -> edition puuttuu
            return [
                $row[0], $row[1], null, $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], null
            ];
        }
    }

    // --- CASE A: "Normaali" muoto (contract_nr kolmannessa) ---
    // [0]Account, [1]Campaign, [2]ContractNr, [3]Date, [4]Hour, [5]Block, [6]Code, [7]Commercial, [8]Length, [9]Edition?
    $dateAt3 = parse_date($row[3] ?? null);
    if ($dateAt3 !== null && $isHour($row[4] ?? null)) {
        $edition = $row[9] ?? null; // voi puuttua
        // jos 9 saraketta → edition puuttuu
        return [
            $row[0],          // account
            $row[1],          // campaign
            $row[2] ?? null,  // contract_nr
            $row[3],          // date
            $row[4],          // hours
            $row[5],          // block
            $row[6],          // code
            $row[7],          // commercial
            $row[8] ?? null,  // length_raw
            $edition
        ];
    }

    // Jos päädytään tänne, emme tunnistaneet varmaa muotoa.
    $errors[] = "Rivi {$rowNum}: sarakejärjestystä ei voitu tulkita luotettavasti.";
    return null;
}

// --- Lomake (GET) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ?>
    <!doctype html>
    <html lang="fi">

    <head>
        <meta charset="utf-8">
        <title>OAS – CSV-tuonti</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            :root {
                --bg: #0f172a;
                --panel: #111827;
                --muted: #9ca3af;
                --text: #e5e7eb;
                --accent: #22d3ee;
                --bd: #1f2937;
            }

            body {
                margin: 0;
                font-family: system-ui, -apple-system, Segoe UI, Roboto;
                background: var(--bg);
                color: var(--text);
            }

            .wrap {
                max-width: 960px;
                margin: 0 auto;
                padding: 24px;
            }

            .card {
                background: var(--panel);
                border: 1px solid var(--bd);
                border-radius: 16px;
                padding: 24px;
            }

            h1 {
                font-size: 1.5rem;
                margin: 0 0 12px;
            }

            input[type=file],
            select {
                background: #0b1220;
                color: var(--text);
                border: 1px solid var(--bd);
                border-radius: 10px;
                padding: 10px;
                width: 100%;
            }

            .btn {
                background: linear-gradient(180deg, #06b6d4, #0891b2);
                color: #001018;
                border: none;
                padding: 12px 16px;
                border-radius: 12px;
                font-weight: 600;
                cursor: pointer;
            }
        </style>
    </head>

    <body>
        <div class="wrap">
            <div class="card">
                <h1>OAS – CSV-tuonti</h1>
                <form method="post" enctype="multipart/form-data">
                    <label>CSV-tiedosto</label>
                    <input type="file" name="csv" accept=".csv,text/csv" required>
                    <label>Erottin</label>
                    <select name="delimiter">
                        <option value="auto" selected>Automaattinen</option>
                        <option value=",">Pilkku (,)</option>
                        <option value=";">Puolipiste (;)</option>
                        <option value="\t">Sarkain (\t)</option>
                    </select>
                    <p><label><input type="checkbox" name="dryrun" value="1" checked> Kuiva ajo (ei talletusta, näyttää
                            esikatselun)</label></p>
                    <button class="btn" type="submit">Lue CSV</button>
                </form>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// --- Tuonti ---
$errors = [];
$inserted = 0;
$skipped_headers = 0;
$previewRows = [];

$delimReq = $_POST['delimiter'] ?? 'auto';
$dryrun = isset($_POST['dryrun']);

if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo "<p><strong>Virhe:</strong> CSV-tiedostoa ei saatu ladattua.</p>";
    exit;
}
$path = $_FILES['csv']['tmp_name'];
$fh = fopen($path, 'r');
if (!$fh) {
    echo "<p><strong>Virhe:</strong> tiedostoa ei voitu avata.</p>";
    exit;
}

// Delimiter tunnistus
$firstLine = '';
$pos = ftell($fh);
while (!feof($fh)) {
    $probe = fgets($fh);
    if ($probe !== false && trim($probe) !== '') {
        $firstLine = $probe;
        break;
    }
}
fseek($fh, $pos);
$delimiter = $delimReq === 'auto' ? autodetect_delimiter($firstLine) : ($delimReq === '\\t' ? "\t" : $delimReq);

$pdo = pdo();
$pdo->beginTransaction();

$sql = "INSERT INTO oas
        (account,campaign,contract_nr,date_played,hours,block,code,commercial,length_sec,edition,raw_length)
        VALUES (:account,:campaign,:contract_nr,:date_played,:hours,:block,:code,:commercial,:length_sec,:edition,:raw_length)";
$stmt = $pdo->prepare($sql);

$rowNum = 0;
while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $rowNum++;
    $nonEmpty = array_filter($row, fn($v) => trim((string) $v) !== '');
    if (!$nonEmpty)
        continue;
    if (is_header_row($row)) {
        $skipped_headers++;
        continue;
    }

    $norm = normalize_row($row, $rowNum, $errors);
    if (!$norm)
        continue;

    [$account, $campaign, $contractNr, $date, $hours, $block, $code, $commercial, $lengthRaw, $edition] = $norm;
    $dateSql = parse_date($date);
    $lenSec = parse_length_to_seconds($lengthRaw);
    $edition = $edition === '' ? null : $edition;

    if ((!$contractNr || $contractNr === '') && $campaign) {
        [$campaign2, $contract2] = split_campaign_contract($campaign);
        if ($contract2 && !$contractNr) {
            $campaign = $campaign2;
            $contractNr = $contract2;
        }
    }

    if ($dryrun && count($previewRows) < 50) {
        $previewRows[] = [
            'account' => $account,
            'campaign' => $campaign,
            'contract_nr' => $contractNr,
            'date_played' => $dateSql,
            'hours' => $hours,
            'block' => $block,
            'code' => $code,
            'commercial' => $commercial,
            'length_sec' => $lenSec,
            'edition' => $edition,
            'raw_length' => $lengthRaw
        ];
    }

    try {
        if (!$dryrun) {
            $stmt->execute([
                ':account' => $account ?: null,
                ':campaign' => $campaign ?: null,
                ':contract_nr' => $contractNr ?: null,
                ':date_played' => $dateSql,
                ':hours' => $hours ?: null,
                ':block' => $block ?: null,
                ':code' => $code ?: null,
                ':commercial' => $commercial ?: null,
                ':length_sec' => $lenSec,
                ':edition' => $edition,
                ':raw_length' => $lengthRaw ?: null
            ]);
        }
        $inserted++;
    } catch (Throwable $e) {
        $errors[] = "Rivi {$rowNum}: " . $e->getMessage();
    }
}
fclose($fh);
if ($dryrun || $errors)
    $pdo->rollBack();
else
    $pdo->commit();

// --- Raportti ---
?>
<!doctype html>
<html lang="fi">

<head>
    <meta charset="utf-8">
    <title>OAS – Tuontiraportti</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --muted: #9ca3af;
            --text: #e5e7eb;
            --ok: #22c55e;
            --err: #ef4444;
            --bd: #1f2937;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto;
            background: var(--bg);
            color: var(--text);
        }

        .wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--bd);
            border-radius: 16px;
            padding: 24px;
        }

        h1 {
            font-size: 1.5rem;
            margin: 0 0 12px;
        }

        .ok {
            color: var(--ok);
        }

        .err {
            color: var(--err);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1em;
            font-size: 0.9rem;
        }

        th,
        td {
            border: 1px solid var(--bd);
            padding: 6px 8px;
            text-align: left;
        }

        th {
            background: #1e293b;
        }

        code {
            color: #7dd3fc;
        }

        .btn {
            background: linear-gradient(180deg, #06b6d4, #0891b2);
            color: #001018;
            border: none;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="card">
            <h1>OAS – Tuontiraportti</h1>
            <p>Erotin: <code><?= htmlspecialchars($delimiter === "\t" ? "\\t" : $delimiter) ?></code></p>
            <p>Otsikkorivejä ohitettu: <?= $skipped_headers ?> |
                <?= $dryrun ? 'Käsitelty (kuiva ajo):' : 'Talletettu:' ?> <span
                    class="<?= $dryrun ? '' : 'ok' ?>"><?= $inserted ?></span></p>

            <?php if ($errors): ?>
                <p class="err"><strong>Virheitä:</strong> <?= count($errors) ?></p>
                <ol>
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($dryrun && $previewRows): ?>
                <h2>Esikatselu (ensimmäiset <?= count($previewRows) ?> riviä)</h2>
                <div style="overflow:auto;max-height:500px;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>account</th>
                                <th>campaign</th>
                                <th>contract_nr</th>
                                <th>date_played</th>
                                <th>hours</th>
                                <th>block</th>
                                <th>code</th>
                                <th>commercial</th>
                                <th>length_sec</th>
                                <th>edition</th>
                                <th>raw_length</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewRows as $i => $r): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($r['account'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['campaign'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['contract_nr'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['date_played'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['hours'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['block'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['code'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['commercial'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['length_sec'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['edition'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['raw_length'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($dryrun): ?>
                <p><em>Ei esikatseltavia rivejä.</em></p>
            <?php endif; ?>

            <p><a class="btn" href="oas_csv_importer.php">← Takaisin tuontiin</a></p>
        </div>
    </div>
</body>

</html>
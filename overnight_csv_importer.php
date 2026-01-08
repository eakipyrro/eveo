<?php
// import_viewership.php – CSV -> th_eveo.ev_viewership
// Puolipiste-erotin, suomenkieliset otsikot, desimaalipilkku, ja <empty> käsittely

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=utf-8');

///--- DB-asetukset ---
$DB_HOST = 'www.fissi.fi';
$DB_NAME = 'fissifi_eveo';
$DB_USER = 'fissifi_eveo_admin';
$DB_PASS = '23ufa3}A1CaG%Ica';
$DSN = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";


function db(): PDO {
    static $pdo = null;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    if ($pdo === null) {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function normalize($s): string {
    $s = trim($s ?? '');
    $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    $s = preg_replace('/[^A-Za-z0-9 ]+/', '', $s);
    return strtolower($s);
}

function mapHeader(array $header): array {
    $norm = array_map('normalize', $header);
    $find = function(array $candidates) use ($norm): ?int {
        foreach ($candidates as $cand) {
            foreach ($norm as $i => $h) {
                if (strpos($h, $cand) !== false) return $i;
            }
        }
        return null;
    };
    return [
        'date'   => $find(['paivamaara','date']),
        'program'=> $find(['ohjelma','program']),
        'start'  => $find(['alku','start']),
        'end'    => $find(['paattymisaika','paattymis','end']),
        'over60' => $find(['yli 60s','over 60','over60']),
        'avg'    => $find(['keskikatsojamaara','avg viewers','average']),
        'share'  => $find(['katsojaosuus','share']),
    ];
}

function parseFinnDate(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (strpos($s, ',') !== false) {
        $parts = explode(',', $s, 2);
        $s = trim($parts[1]);
    }
    $dt = DateTime::createFromFormat('d F Y', $s);
    if (!$dt) {
        $fi = ['tammikuu','helmikuu','maaliskuu','huhtikuu','toukokuu','kesäkuu','heinäkuu','elokuu','syyskuu','lokakuu','marraskuu','joulukuu'];
        $en = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        $s2 = str_ireplace($fi, $en, $s);
        $dt = DateTime::createFromFormat('d F Y', $s2);
    }
    return $dt ? $dt->format('Y-m-d') : null;
}

function parseUsDatetime(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    $dt = DateTime::createFromFormat('m.d.Y h:i:s A', $s);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function toDecimal(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    $s = str_replace([' ', "\xc2\xa0"], '', $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return number_format((float)$s, 2, '.', '');
}

function toInt(string $s): ?int {
    $s = trim($s);
    if ($s === '') return null;
    $s = preg_replace('/\D+/', '', $s);
    return ($s === '') ? null : (int)$s;
}

$errors = [];
$preview = [];
$didInsert = 0;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
?>
<!doctype html>
<html lang="fi">
<head>
  <meta charset="utf-8">
  <title>EV Viewership – CSV tuonti</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 2rem; }
    .card { border:1px solid #ccc; border-radius:10px; padding:1rem; margin-bottom:1rem; }
    th,td{border:1px solid #ddd;padding:4px 6px;font-size:14px;}
    th{background:#f3f4f6;}
    .ok{color:#0a7f2e;}
    .err{color:#b91c1c;}
    .warn{color:#b45309;}
    .small{font-size:12px;}
  </style>
</head>
<body>
<h1>EV Viewership – CSV-tuonti</h1>

<form method="post" enctype="multipart/form-data" class="card">
  <p><input type="file" name="csv" accept=".csv,text/csv" required></p>
  <p><label><input type="checkbox" name="dry" value="1" checked> Kuivajuoksu (näytä 50 riviä, ei tallennusta)</label></p>
  <p><button type="submit">Käsittele</button></p>
</form>

<?php
if ($isPost && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
    $dry = isset($_POST['dry']);
    $lines = file($_FILES['csv']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $header = str_getcsv(array_shift($lines), ';');
    $map = mapHeader($header);

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO overnightreport
          (report_date, program, start_time, end_time, over60_on_channel, avg_viewers, share_pct)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $lineNo = 1;
    foreach ($lines as $line) {
        $cols = str_getcsv($line, ';');
        $cols = array_pad($cols, count($header), '');

        $reportDate = parseFinnDate($cols[$map['date']] ?? '') ?? '<empty>';
        $program    = trim($cols[$map['program']] ?? '') ?: '<empty>';
        $start      = parseUsDatetime($cols[$map['start']] ?? '') ?? '<empty>';
        $end        = parseUsDatetime($cols[$map['end']] ?? '') ?? '<empty>';
        $over60     = toInt($cols[$map['over60']] ?? '') ?? 0;
        $avg        = toInt($cols[$map['avg']] ?? '') ?? 0;
        $share      = toDecimal($cols[$map['share']] ?? '') ?? '0.00';

        if ($dry && count($preview) < 50) {
            $preview[] = [
                'report_date' => $reportDate,
                'program' => $program,
                'start_time' => $start,
                'end_time' => $end,
                'over60' => $over60,
                'avg' => $avg,
                'share' => $share
            ];
        }

        if (!$dry) {
            try {
                $stmt->execute([$reportDate,$program,$start,$end,$over60,$avg,$share]);
                $didInsert++;
            } catch (Throwable $e) {
                $errors[] = "Rivi {$lineNo}: " . $e->getMessage();
            }
        }
        $lineNo++;
    }

    if ($dry) {
        echo '<div class="card"><h3>Kuivajuoksu – esikatselu</h3><table><tr>
              <th>report_date</th><th>program</th><th>start_time</th><th>end_time</th>
              <th>over60_on_channel</th><th>avg_viewers</th><th>share_pct</th></tr>';
        foreach ($preview as $r) {
            echo '<tr>';
            foreach ($r as $v) echo '<td>'.htmlspecialchars($v).'</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p class="small">Tyhjät kentät korvattu merkkijonolla &lt;empty&gt;</p>';
        echo '</div>';
    } else {
        echo '<div class="card">';
        echo '<p class="ok">Lisätty rivejä: '.$didInsert.'</p>';
        if ($errors) {
            echo '<p class="warn">Virheitä:</p><ul>';
            foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>';
            echo '</ul>';
        }
        echo '</div>';
    }
}
?>
</body>
</html>

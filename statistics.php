<?php
// statistics.php – Koontiraportti overnightreport-taulusta
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=utf-8');

// --- DB-asetukset ---
$DB_HOST = 'www.fissi.fi';
$DB_NAME = 'fissifi_eveo';
$DB_USER = 'fissifi_eveo_admin';
$DB_PASS = '23ufa3}A1CaG%Ica';
$DB_CHARSET = 'utf8mb4';

// XSS-suojattu tulostus
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Numeromuotoilut
function fmt_int($n){ return $n===null ? '—' : number_format((float)$n, 0, ',', ' '); }
function fmt_pct($n){ return $n===null ? '—' : number_format((float)$n, 2, ',', ' '); }

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}",
        $DB_USER,
        $DB_PASS,
        [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
    );

    // --- Alasvetovalikon ohjelmat (normalisoitu arvo) ---
    // Käytetään samaa normalisointia kuin suodatuksessa ja ryhmittelyssä.
    $sqlProg = "
        SELECT prog_norm
        FROM (
          SELECT REPLACE(TRIM(program), CHAR(160), ' ') AS prog_norm
          FROM overnightreport
          WHERE program IS NOT NULL AND program <> ''
        ) t
        WHERE prog_norm <> ''
        GROUP BY prog_norm
        ORDER BY prog_norm
    ";
    $programs = $pdo->query($sqlProg)->fetchAll(PDO::FETCH_COLUMN);

    // Valittu ohjelma (jo valmiiksi normalisoitu, koska se tulee valikon value:sta)
    $selectedProgram = isset($_GET['program']) ? (string)$_GET['program'] : '';

    if ($selectedProgram !== '') {
        // --- VAIN VALITTU OHJELMA ---
        $sql = "
          SELECT
            REPLACE(TRIM(program), CHAR(160), ' ')        AS program,
            COALESCE(SUM(over60_on_channel), 0)           AS sum_over60s,
            MIN(NULLIF(avg_viewers,''))                   AS min_viewers,
            AVG(NULLIF(avg_viewers,''))                   AS avg_viewers,
            MAX(NULLIF(avg_viewers,''))                   AS max_viewers,
            MIN(NULLIF(share_pct,''))                     AS min_share,
            AVG(NULLIF(share_pct,''))                     AS avg_share,
            MAX(NULLIF(share_pct,''))                     AS max_share
          FROM overnightreport
          WHERE REPLACE(TRIM(program), CHAR(160), ' ') = :p
          GROUP BY REPLACE(TRIM(program), CHAR(160), ' ')
          ORDER BY program
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':p' => $selectedProgram]);
    } else {
        // --- KAIKKI OHJELMAT (ryhmittely normalisoidulla nimellä) ---
        $sql = "
          SELECT
            REPLACE(TRIM(program), CHAR(160), ' ')        AS program,
            COALESCE(SUM(over60_on_channel), 0)           AS sum_over60s,
            MIN(NULLIF(avg_viewers,''))                   AS min_viewers,
            AVG(NULLIF(avg_viewers,''))                   AS avg_viewers,
            MAX(NULLIF(avg_viewers,''))                   AS max_viewers,
            MIN(NULLIF(share_pct,''))                     AS min_share,
            AVG(NULLIF(share_pct,''))                     AS avg_share,
            MAX(NULLIF(share_pct,''))                     AS max_share
          FROM overnightreport
          WHERE program IS NOT NULL AND program <> ''
          GROUP BY REPLACE(TRIM(program), CHAR(160), ' ')
          ORDER BY program
        ";
        $stmt = $pdo->query($sql);
    }

    $rows = $stmt->fetchAll();

} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Virhe</h1><p>".h($e->getMessage())."</p>";
    exit;
}
?>
<!doctype html>
<html lang="fi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statistics – Overnightreport</title>
<style>
  :root { --bg:#0f172a; --panel:#111827; --text:#e5e7eb; --muted:#9ca3af; --accent:#60a5fa; --border:#1f2937; }
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial,"Noto Sans";}
  header{padding:16px 24px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10;}
  h1{margin:0;font-size:20px;}
  .container{max-width:1200px;margin:0 auto;padding:16px 24px;}
  form.filter{display:flex;gap:12px;align-items:center;margin:12px 0 20px;}
  select,button{background:#0b1220;color:var(--text);border:1px solid var(--border);padding:8px 10px;border-radius:8px;}
  button{cursor:pointer;}
  table{width:100%;border-collapse:collapse;overflow:hidden;border:1px solid var(--border);border-radius:12px;}
  thead th{background:#0b1220;text-align:left;font-weight:600;font-size:14px;padding:10px 12px;border-bottom:1px solid var(--border);position:sticky;top:56px;z-index:5;}
  tbody td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:14px;}
  tbody tr:hover{background:rgba(255,255,255,0.03);}
  .muted{color:var(--muted);}
  .right{text-align:right;white-space:nowrap;}
  .toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px;}
  .pill{background:rgba(96,165,250,.15);border:1px solid rgba(96,165,250,.35);color:#bfdbfe;border-radius:999px;padding:4px 10px;font-size:12px;}
  .debug{margin:8px 0;padding:8px 10px;border:1px dashed var(--border);color:#fde68a;background:rgba(250,204,21,.08);border-radius:8px;}
</style>
</head>
<body>
  <header><h1>Statistics – Overnightreport</h1></header>
  <div class="container">

    <?php if ($debug): ?>
      <div class="debug">
        Debug: valittu ohjelma (normalisoitu) = <strong><?= h($selectedProgram ?: '(ei valintaa)') ?></strong>,
        rivejä = <strong><?= count($rows) ?></strong>
      </div>
    <?php endif; ?>

    <div class="toolbar">
      <form method="get" class="filter">
        <label for="program" class="muted">Ohjelma</label>
        <select name="program" id="program">
          <option value="">(Kaikki ohjelmat)</option>
          <?php foreach ($programs as $p): ?>
            <option value="<?= h($p) ?>" <?= $p === $selectedProgram ? 'selected' : '' ?>><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Näytä</button>
        <?php if ($selectedProgram !== ''): ?>
          <a class="pill" href="?">Tyhjennä suodatin</a>
        <?php endif; ?>
        <?php if ($debug): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
      </form>
      <div class="muted"><?= count($rows) ?> riviä</div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Ohjelma</th>
          <th class="right">Yli 60s kanavalla (∑)</th>
          <th class="right">Min katsojamäärä</th>
          <th class="right">Keskikatsojamäärä</th>
          <th class="right">Max katsojamäärä</th>
          <th class="right">Min katsojaosuus (%)</th>
          <th class="right">Katsojaosuus (%)</th>
          <th class="right">Max katsojaosuus (%)</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">Ei dataa valituilla ehdoilla.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['program']) ?></td>
          <td class="right"><?= fmt_int($r['sum_over60s']) ?></td>
          <td class="right"><?= fmt_int($r['min_viewers']) ?></td>
          <td class="right"><?= fmt_int(round($r['avg_viewers'] ?? 0)) ?></td>
          <td class="right"><?= fmt_int($r['max_viewers']) ?></td>
          <td class="right"><?= fmt_pct($r['min_share']) ?></td>
          <td class="right"><?= fmt_pct($r['avg_share']) ?></td>
          <td class="right"><?= fmt_pct($r['max_share']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <p class="muted" style="margin-top:10px;">
      * “Yli 60s kanavalla” on tässä summattuna kaikista riveistä kullekin ohjelmalle. Vaihdettavissa AVG/MAX:ksi tarpeen mukaan.
    </p>
  </div>
</body>
</html>

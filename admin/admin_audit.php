<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/audit.php';

require_login();
require_permission('view_audit');

$pdo = db();


// --- Suodattimet ---
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$ev = trim((string) ($_GET['event'] ?? ''));
$stat = $_GET['status'] ?? ''; // success | fail
$actor = (int) ($_GET['actor'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$pageNo = max(1, (int) ($_GET['page'] ?? 1));
$limit = 200;
$off = ($pageNo - 1) * $limit;

// --- WHERE ---
$where = [];
$params = [];
if ($from !== '') {
    $where[] = "occurred_at >= :from";
    $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = "occurred_at <= :to";
    $params[':to'] = $to . ' 23:59:59';
}
if ($ev !== '') {
    $where[] = "event_type = :ev";
    $params[':ev'] = $ev;
}
if ($stat !== '' && in_array($stat, ['success', 'fail'], true)) {
    $where[] = "status = :st";
    $params[':st'] = $stat;
}
if ($actor > 0) {
    $where[] = "user_id = :a";
    $params[':a'] = $actor;
}

// Yleis-haku: reason, request_uri, IP (teksti), UA ja JSON
if ($q !== '') {
    // JSON: kokeile JSON_SEARCH tarkkaa osumaa, ja lisäksi LIKE castattuna
    $where[] = "(
      reason LIKE :qq
      OR request_uri LIKE :qq
      OR user_agent LIKE :qq
      OR COALESCE(INET6_NTOA(ip_bin), ip_address) LIKE :qq
      OR JSON_SEARCH(details_json, 'all', :needle, NULL, '$') IS NOT NULL
      OR CAST(details_json AS CHAR) LIKE :qq
    )";
    $params[':qq'] = "%$q%";
    $params[':needle'] = $q; // tarkka osuma johonkin JSON-arvoon
}

$sqlBase = "FROM audit_events";
if ($where)
    $sqlBase .= " WHERE " . implode(" AND ", $where);

// --- Count ---
$stc = $pdo->prepare("SELECT COUNT(*) " . $sqlBase);
$stc->execute($params);
$total = (int) $stc->fetchColumn();

// --- Rows ---
$select = "
  SELECT
    id,
    occurred_at,
    user_id,
    role,
    session_id,
    correlation_id,
    event_type,
    status,
    reason,
    COALESCE(INET6_NTOA(ip_bin), ip_address) AS ip_text,
    user_agent,
    http_method,
    request_uri,
    details_json
  $sqlBase
  ORDER BY id DESC
  LIMIT $limit OFFSET $off
";
$st = $pdo->prepare($select);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Dropdown-data ---
$evs = [];
foreach ($pdo->query("SELECT DISTINCT event_type FROM audit_events ORDER BY event_type") as $r) {
    $evs[] = $r['event_type'];
}
$actors = [];
foreach ($pdo->query("SELECT DISTINCT user_id FROM audit_events WHERE user_id IS NOT NULL ORDER BY user_id") as $r) {
    $actors[] = (int) $r['user_id'];
}

// --- CSV export nykyisestä näkymästä ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_export.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'id',
        'ts_local',
        'event',
        'status',
        'user_id',
        'role',
        'ip',
        'method',
        'uri',
        'reason',
        'correlation_id',
        'details_json'
    ]);
    $tz = new DateTimeZone('Europe/Helsinki');
    foreach ($rows as $r) {
        $dt = (new DateTime($r['occurred_at'] . ' UTC'))->setTimezone($tz)->format('Y-m-d H:i:s');
        $details = is_null($r['details_json']) ? '' :
            (is_string($r['details_json']) ? $r['details_json'] : json_encode($r['details_json']));
        fputcsv($out, [
            $r['id'],
            $dt,
            $r['event_type'],
            $r['status'],
            $r['user_id'],
            $r['role'],
            $r['ip_text'],
            $r['http_method'],
            $r['request_uri'],
            $r['reason'],
            $r['correlation_id'],
            $details
        ]);
    }
    exit;
}

// --- Meta/UI ---
$PAGE_TITLE = 'Audit-loki';
$REQUIRE_LOGIN = true;
$REQUIRE_PERMISSION = 'view_audit';
$BACK_HREF = 'admin_index.php';
require_once __DIR__ . '/../include/header.php';
?>
<style>
    /* ---------- Yläpalkki / napit ---------- */
    .filterbar {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        margin: 12px 0;
    }

    .filterbar input,
    .filterbar select {
        padding: 8px 10px;
        border: 1px solid var(--input-border);
        border-radius: 8px;
        background: var(--input-bg);
        color: var(--input-text);
    }

    button.btn {
        padding: 8px 12px;
        border: 1px solid var(--border);
        background: var(--surface-2);
        color: var(--text);
        border-radius: 8px;
        cursor: pointer;
    }

    button.primary {
        background: var(--accent);
        border-color: var(--accent);
        color: #fff;
    }

    .pager {
        margin: 10px 0;
        display: flex;
        gap: 10px;
    }

    /* ---------- Taulukon peruslayout ---------- */
    .table {
        width: 100%;
        max-width: 100%;
        border: 1px solid var(--border);
        border-radius: 10px;
        overflow: auto;
        /* vaakaskrolli käyttöön */
    }

    .table table {
        width: 100%;
        min-width: 1700px;
        /* kasvata tarvittaessa (1600–2200px) */
        border-collapse: collapse;
    }

    .table th,
    .table td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--border);
        vertical-align: top;
        white-space: normal;
        /* oletus: ei väkisin katkaisua */
        word-break: normal;
        overflow-wrap: normal;
    }

    .table thead th {
        position: sticky;
        top: 0;
        background: var(--panel);
        color: var(--label);
        z-index: 1;
        white-space: nowrap;
        /* otsikot yhdelle riville */
        word-break: keep-all;
    }

    /* ---------- Util-luokat soluille ---------- */
    .nowrap {
        /* yksiriviset kentät */
        white-space: nowrap !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .wrap {
        /* sallitusti rivittyvä sisältö */
        white-space: normal !important;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .uri {
        /* URL voi katketa mistä vain */
        white-space: normal;
        word-break: break-all;
        overflow-wrap: anywhere;
    }

    .mono {
        font-family: ui-monospace, Menlo, Consolas, monospace;
    }

    .small {
        font-size: 12px;
        opacity: .85;
    }

    /* Aika kahdelle riville */
    .timecell {
        white-space: normal;
    }

    .timecell div:first-child {
        font-weight: 600;
    }

    .timecell div:last-child {
        opacity: .9;
    }

    /* ---------- Badge ---------- */
    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        border: 1px solid var(--border);
    }

    .badge.ok {
        background: #133d1a;
    }

    .badge.err {
        background: #3d1313;
    }

    /* ---------- Expander (Details) ---------- */
    tr.expander {
        display: none;
    }

    tr.expander.show {
        display: table-row;
    }

    tr.expander>td {
        background: var(--surface-1);
        border-top: 1px solid var(--border);
        padding: 12px 12px 14px;
    }

    .expander-grid {
        display: grid;
        grid-template-columns: 1fr 320px;
        /* JSON | UA */
        gap: 12px;
    }

    .kv {
        font-family: ui-monospace, Menlo, Consolas, monospace;
        font-size: 12px;
        white-space: pre;
        /* pretty-print sellaisenaan */
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 8px 10px;
        max-height: 300px;
        overflow: auto;
    }

    .icon-toggle {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 2px 4px;
        line-height: 1;
    }

    .icon-toggle svg {
        width: 16px;
        height: 16px;
        vertical-align: middle;
        transition: transform .15s ease;
        opacity: .85;
    }

    .icon-toggle[aria-expanded="true"] svg {
        transform: rotate(90deg);
    }

    /* ---------- Pienet kolumnisäädöt (valinnaiset) ---------- */
    /* HTTP-metodi yleensä lyhyt */
    .http .method {
        font-weight: 600;
    }

    /* jos käytät colgroupia HTML:ssä, nämä voi jättää pois */
</style>

<h1>Audit-loki</h1>

<form method="get" action="" class="filterbar">
    <input type="date" name="from" value="<?= h($from) ?>">
    <input type="date" name="to" value="<?= h($to) ?>">
    <select name="event">
        <option value="">Kaikki eventit</option>
        <?php foreach ($evs as $e): ?>
            <option value="<?= h($e) ?>" <?= $ev === $e ? 'selected' : '' ?>><?= h($e) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Kaikki tulokset</option>
        <option value="success" <?= $stat === 'success' ? 'selected' : '' ?>>success</option>
        <option value="fail" <?= $stat === 'fail' ? 'selected' : '' ?>>fail</option>
    </select>
    <select name="actor">
        <option value="0">Kaikki käyttäjät</option>
        <?php foreach ($actors as $a): ?>
            <option value="<?= $a ?>" <?= $actor === $a ? 'selected' : '' ?>>user #<?= $a ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Hae reason/URI/IP/UA/JSON">
    <button class="btn">Suodata</button>
    <a class="btn" href="<?= h(basename(__FILE__)) ?>">Tyhjennä</a>
    <button class="btn primary" name="export" value="csv">Vie CSV (näkymä)</button>
</form>

<?php
$pagesTotal = max(1, (int) ceil($total / $limit));
?>
<div class="pager">
    <div>Yhteensä: <?= number_format($total, 0, ',', ' ') ?> riviä</div>
    <div style="margin-left:auto">
        Sivu <?= $pageNo ?> / <?= $pagesTotal ?>
        <?php if ($pageNo > 1): ?><a class="btn"
                href="?<?= http_build_query(array_merge($_GET, ['page' => $pageNo - 1])) ?>">&laquo;
                Edellinen</a><?php endif; ?>
        <?php if ($off + $limit < $total): ?><a class="btn"
                href="?<?= http_build_query(array_merge($_GET, ['page' => $pageNo + 1])) ?>">Seuraava
                &raquo;</a><?php endif; ?>
    </div>
</div>

<div class="table">
    <table>
        <colgroup>
            <col style="width:72px"> <!-- ID -->
            <col style="width:120px"> <!-- Aika -->
            <col style="width:180px"> <!-- Event -->
            <col style="width:92px"> <!-- Status -->
            <col style="width:160px"> <!-- Actor/Rooli -->
            <col style="width:260px"> <!-- IP/Sessio -->
            <col style="width:90px"> <!-- HTTP -->
            <col style="width:520px"> <!-- Sivu/Syy (saa venyä) -->
            <col style="width:190px"> <!-- Correlation -->
            <col style="width:60px"> <!-- Details-ikoni -->
        </colgroup>

        <thead>
            <tr>
                <th>ID</th>
                <th class="wrap">Aika (FI)</th>
                <th class="wrap">Event</th>
                <th>Status</th>
                <th>Actor / Rooli</th>
                <th class="wrap">IP / Sessio</th>
                <th>HTTP</th>
                <th class="wrap">Sivu / Syy</th>
                <th>Correlation</th>
                <th>Details</th>
            </tr>
        </thead>

        <tbody>
            <?php
            $tz = new DateTimeZone('Europe/Helsinki');
            foreach ($rows as $r):
                $dtObj = (new DateTime(($r['occurred_at'] ?? '') . ' UTC'))->setTimezone($tz);
                $dateFi = $dtObj->format('Y-m-d');
                $timeFi = $dtObj->format('H:i:s');
                $ok = ($r['status'] === 'success');
                // Pretty JSON teksti
                $pretty = '—';
                if (!is_null($r['details_json'])) {
                    $json = is_string($r['details_json']) ? $r['details_json'] : json_encode($r['details_json']);
                    $arr = json_decode((string) $json, true);
                    $pretty = $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $json;
                }

                // Pieni preview infoksi (merkki- ja kenttämäärä)
                $jsonLen = is_string($pretty) ? mb_strlen($pretty) : 0;
                ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td class="wrap timecell">
                        <div><?= h($dateFi) ?></div>
                        <div><?= h($timeFi) ?></div>
                    </td>

                    <td class="nowrap"><?= h($r['event_type']) ?></td>

                    <td><span class="badge <?= $ok ? 'ok' : 'err' ?>"><?= h($r['status'] ?? '—') ?></span></td>
                    <td class="nowrap">
                        <?= h((string) ($r['user_id'] ?? '—')) ?> <span class="small" style="opacity:.8">/
                            <?= h($r['role'] ?? '') ?></span>
                    </td>

                    <td class="nowrap mono"
                        title="IP: <?= h($r['ip_text'] ?? '') ?>&#10;Sessio: <?= h($r['session_id'] ?? '') ?>">
                        <?= h($r['ip_text'] ?? '') ?> • <?= h($r['session_id'] ?? '') ?>
                    </td>

                    <td>
                        <div><?= h($r['http_method'] ?? '') ?></div>
                        <div class="small" title="<?= h($r['request_uri'] ?? '') ?>"><?= h($r['request_uri'] ?? '') ?></div>
                    </td>
                    <td class="wrap">
                        <strong><?= h(parse_url($r['request_uri'] ?? '', PHP_URL_PATH) ?? '—') ?></strong><br>
                        <div class="small uri"><?= h($r['request_uri'] ?? '') ?></div>
                        <?= h($r['reason'] ?? '') ?>
                    </td>

                    <td class="small">
                        <?php if (!empty($r['correlation_id'])): ?>
                            <?= h($r['correlation_id']) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <button type="button" class="icon-toggle" aria-expanded="false"
                            aria-controls="exp-<?= (int) $r['id'] ?>" onclick="toggleExp(<?= (int) $r['id'] ?>, this)"
                            title="Näytä/piilota tarkemmat tiedot">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </td>


                </tr>

                <!-- Expander-rivi: koko leveys -->
                <tr class="expander" id="exp-<?= (int) $r['id'] ?>">
                    <td colspan="10">
                        <div class="expander-grid">
                            <div>
                                <div class="small" style="margin-bottom:6px">Details JSON</div>
                                <div class="kv"><?= h($pretty) ?></div>
                            </div>
                            <div>
                                <div class="small" style="margin-bottom:6px">User-Agent</div>
                                <div class="kv" title="<?= h($r['user_agent'] ?? '—') ?>">
                                    <?= h($r['user_agent'] ?? '—') ?>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>


            <?php endforeach; ?>
        </tbody>

    </table>
</div>

<div class="pager" style="margin-bottom:24px">
    <div style="margin-left:auto">
        Sivu <?= $pageNo ?> / <?= $pagesTotal ?>
        <?php if ($pageNo > 1): ?><a class="btn"
                href="?<?= http_build_query(array_merge($_GET, ['page' => $pageNo - 1])) ?>">&laquo;
                Edellinen</a><?php endif; ?>
        <?php if ($off + $limit < $total): ?><a class="btn"
                href="?<?= http_build_query(array_merge($_GET, ['page' => $pageNo + 1])) ?>">Seuraava
                &raquo;</a><?php endif; ?>
    </div>
</div>
<script>
    function toggleExp(id, btn) {
        const row = document.getElementById('exp-' + id);
        if (!row) return;
        const open = row.classList.toggle('show');
        if (btn) btn.setAttribute('aria-expanded', open.toString());
    }
</script>


<?php require_once __DIR__ . '/../include/footer.php'; ?>
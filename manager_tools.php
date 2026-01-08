<?php
// DEV: pakota OPcache invalidoitumaan tärkeille tiedostoille (poista kun valmista)
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    @opcache_invalidate(__DIR__ . '/import_oas.php', true);
    @opcache_invalidate(__DIR__ . '/include/importers/oas_lib.php', true);
}

// manager_tools.php – Managerin työkalut: yhtenäinen CSV-import UI oas/oatv/yönyli
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/include/auth.php';   // do_login(), current_user(), require_login(), can()
require_login();
$u = current_user();

// Salli vain manager/admin (muokkaa roolit tarvittaessa)
if (!can('view_manager_tools')) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden';
    exit;
}

// --- Sivun meta headerille ---
$pageTitle = 'Managerin työkalut';
$pageStylesheets = $pageStylesheets ?? [];
$pageStylesheets[] = 'res/css/reports_light.css?v=' . time(); // sama teema kuin raporteissa

// CSRF token tälle sivulle
if (empty($_SESSION['csrf_import'])) {
    $_SESSION['csrf_import'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_import'];

// Yläkulman pill
$topbarRightHtml = sprintf(
    '<span class="pill">Kirjautunut: %s (%s)</span> <a class="link" href="logout.php">Kirjaudu ulos</a>',
    htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8'),
    htmlspecialchars($u['role'] ?? '', ENT_QUOTES, 'UTF-8')
);

$PAGE_TITLE = "EVEO – Manager";
include __DIR__ . '/include/header.php';
?>
<style>
    :root {
        --bg: #f7f8fb;
        --card: #fff;
        --border: #e5e7ef;
        --text: #111827;
        --muted: #6b7280;
        --accent: #2563eb;
    }

    .wrap {
        max-width: 1100px;
        margin: 24px auto;
        padding: 0 20px;
    }

    .muted {
        color: var(--muted);
    }

    .tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin: 10px 0 18px;
    }

    .tab-btn {
        padding: 9px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #fff;
        color: var(--text);
        text-decoration: none;
        font-weight: 600;
        cursor: pointer
    }

    .tab-btn.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent)
    }

    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        margin-bottom: 16px;
    }

    .card h3 {
        margin: 0 0 8px;
        color: var(--text);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin: 10px 0;
    }

    .form-row.full {
        grid-template-columns: 1fr;
    }

    .field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    label {
        font-weight: 600;
        color: var(--text);
    }

    .help {
        font-size: 12px;
        color: var(--muted);
    }

    input[type="file"],
    input[type="text"],
    select,
    textarea {
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px;
        background: #fff;
        color: var(--text);
    }

    textarea {
        min-height: 80px;
        resize: vertical;
    }

    .btn {
        display: inline-block;
        padding: 10px 14px;
        border-radius: 10px;
        background: var(--accent);
        color: #fff;
        text-decoration: none;
        font-weight: 700;
        border: 0;
        cursor: pointer;
    }

    .btn.secondary {
        background: #fff;
        color: var(--text);
        border: 1px solid var(--border);
    }

    .inline {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .pill.small {
        font-size: 11px;
        padding: 4px 8px;
    }

    .drop {
        border: 1px dashed var(--border);
        border-radius: 12px;
        padding: 16px;
        text-align: center;
        color: var(--muted);
    }

    .drop.dragover {
        background: #f0f4ff;
        border-color: var(--accent);
        color: var(--text);
    }

    .divider {
        height: 1px;
        background: var(--border);
        margin: 14px 0;
    }

    .notice {
        font-size: 13px;
        color: var(--muted);
    }

    @media (max-width:720px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }

    /* Dark teema */
    body.dark .tab-btn {
        background: var(--surface-2, #0d142a);
        color: var(--label, #d2defa);
        border-color: var(--border, #2a3161);
    }

    body.dark .tab-btn.active {
        background: var(--accent, #4c6fff);
        color: #fff;
        border-color: transparent;
    }

    body.dark .card {
        background: var(--surface-2, #0d142a);
        border-color: var(--border, #2a3161);
    }

    body.dark input[type="file"],
    body.dark input[type="text"],
    body.dark select,
    body.dark textarea {
        background: var(--surface-1, #0a1024);
        color: var(--label, #d2defa);
        border-color: var(--border, #2a3161);
    }

    body.dark .drop {
        border-color: var(--border, #2a3161);
        color: var(--label, #d2defa);
    }

    body.dark .drop.dragover {
        background: #141b39;
    }

    body.dark .divider {
        background: var(--border, #2a3161);
    }

    /* Esikatselun chipit + taulukko + statukset */
    .chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin: 8px 0
    }

    .chip {
        padding: 3px 8px;
        border-radius: 12px;
        border: 1px solid var(--border);
        font-size: 12px;
        background: #fff;
        color: var(--text)
    }

    .warn {
        color: #b66
    }

    .err {
        color: #b00
    }

    table {
        border-collapse: collapse;
        width: 100%
    }

    th,
    td {
        border: 1px solid var(--border);
        padding: 6px 8px;
        text-align: left
    }

    thead th {
        background: #f2f4fb
    }

    body.dark .chip {
        background: var(--surface-1, #0a1024);
        border-color: var(--border, #2a3161);
        color: var(--label, #d2defa)
    }

    body.dark thead th {
        background: #141b39
    }
</style>
<?php if (function_exists('can') && can('view_manager_tools')): ?>
    <div class="muted" style="font-size:12px;margin:6px 0;">
        Manager tools build: <?= date('Y-m-d H:i:s') ?> |
        file: <?= htmlspecialchars(__FILE__) ?>
    </div>
<?php endif; ?>

<div class="wrap">
    <h2>Managerin työkalut</h2>
    <p class="muted">Yhtenäiset CSV-importit OAS, OATV ja Yönyliraportti. Valitse alta välilehti ja lähetä CSV.</p>

    <div class="tabs" role="tablist">
        <button class="tab-btn active" data-tab="oas" role="tab" aria-selected="true">OAS import</button>
        <button class="tab-btn" data-tab="oatv" role="tab" aria-selected="false">OATV import</button>
        <button class="tab-btn" data-tab="overnight" role="tab" aria-selected="false">Yönyliraportti import</button>
    </div>

    <!-- OAS (AJAX: preview/import) -->
    <section id="tab-oas" class="card" role="tabpanel" aria-labelledby="OAS">
        <h3>OAS – CSV tuonti</h3>
        <p class="notice">Kenttiä tyypillisesti: <strong>Account, Campaign, Date, Hours, Block, Code, Commercial,
                Length, Edition</strong>.</p>

        <form id="oas-form" enctype="multipart/form-data" onsubmit="return false;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <div class="form-row">
                <div class="field">
                    <label>CSV-tiedosto</label>
                    <div class="drop" data-drop="oas">Pudota tiedosto tähän tai <u>valitse</u> alta</div>
                    <input type="file" name="csv" id="file-oas" accept=".csv,.txt" required>
                    <div class="help">Hyväksytään .csv / .txt (UTF-8 suositeltu).</div>
                </div>
                <div class="field">
                    <label>Erotin</label>
                    <div class="inline">
                        <label><input type="radio" name="delimiter" value="auto" checked> Auto</label>
                        <label><input type="radio" name="delimiter" value="comma"> ,</label>
                        <label><input type="radio" name="delimiter" value="semicolon"> ;</label>
                        <label><input type="radio" name="delimiter" value="tab"> Tab</label>
                        <label><input type="radio" name="delimiter" value="pipe"> |</label>
                    </div>
                    <div class="divider"></div>
                    <label>Esikatselurivit</label>
                    <input type="number" name="preview_rows" min="1" max="20" value="3" style="max-width:120px">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Päivämuoto CSV:ssä</label>
                    <select name="date_format">
                        <option value="Y-m-d">YYYY-MM-DD (esim. 2025-11-11)</option>
                        <option value="d.m.Y" selected>DD.MM.YYYY (esim. 11.11.2025)</option>
                        <option value="m/d/Y">MM/DD/YYYY (esim. 11/11/2025)</option>
                        <option value="m.d.Y">MM.DD.YYYY (esim. 12.30.2024)</option>
                    </select>
                </div>
                <div class="field">
                    <label>Merkistö</label>
                    <select name="encoding">
                        <option value="UTF-8" selected>UTF-8</option>
                        <option value="ISO-8859-1">ISO-8859-1</option>
                        <option value="Windows-1252">Windows-1252</option>
                    </select>
                    <div class="divider"></div>
                    <label><input type="checkbox" name="has_header" value="1" checked> Ensimmäinen rivi on
                        otsikot</label>
                </div>
            </div>

            <div class="inline" style="margin-top:8px">
                <button class="btn" type="button" id="oas-preview-btn">Esikatsele</button>
                <button class="btn" type="button" id="oas-import-btn">Tuo CSV</button>
                <span id="oas-status" class="pill small" style="display:none"></span>
            </div>
        </form>

        <div id="oas-output" style="margin-top:14px"></div>
    </section>

    <!-- OATV (AJAX: preview/import) -->
    <section id="tab-oatv" class="card" role="tabpanel" aria-labelledby="OATV" hidden>
        <h3>OATV – CSV tuonti</h3>
        <p class="notice">Kentät: <strong>channel, artist, title, date, time, type, duration, hour</strong>. Esikatselu
            tunnistaa erottimen ja otsikot.</p>

        <form id="oatv-form" enctype="multipart/form-data" onsubmit="return false;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <div class="form-row">
                <div class="field">
                    <label>CSV-tiedosto</label>
                    <div class="drop" data-drop="oatv">Pudota tiedosto tähän tai <u>valitse</u> alta</div>
                    <input type="file" name="csv" id="file-oatv" accept=".csv,.txt" required>
                    <div class="help">Hyväksytään .csv / .txt (UTF-8 suositeltu).</div>
                </div>
                <div class="field">
                    <label>Erotin</label>
                    <div class="inline">
                        <label><input type="radio" name="delimiter" value="auto" checked> Auto</label>
                        <label><input type="radio" name="delimiter" value="comma"> ,</label>
                        <label><input type="radio" name="delimiter" value="semicolon"> ;</label>
                        <label><input type="radio" name="delimiter" value="tab"> Tab</label>
                        <label><input type="radio" name="delimiter" value="pipe"> |</label>
                    </div>
                    <div class="divider"></div>
                    <label>Esikatselurivit</label>
                    <input type="number" name="preview_rows" min="1" max="20" value="3" style="max-width:120px">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Pakota channel (jos puuttuu)</label>
                    <input type="text" name="force_channel" placeholder="esim. OATV">
                </div>
                <div class="field">
                    <label><input type="checkbox" name="truncate" value="1"> Tyhjennä taulu ennen tuontia
                        (TRUNCATE)</label>
                    <span class="help">Varoitus: poistaa aiemmat rivit.</span>
                </div>
            </div>

            <div class="inline" style="margin-top:8px">
                <button class="btn" type="button" id="oatv-preview-btn">Esikatsele</button>
                <button class="btn" type="button" id="oatv-import-btn">Tuo CSV</button>
                <span id="oatv-status" class="pill small" style="display:none"></span>
            </div>
        </form>

        <div id="oatv-output" style="margin-top:14px"></div>
    </section>

    <!-- Yönyliraportti -->
    <section id="tab-overnight" class="card" role="tabpanel" aria-labelledby="Overnight" hidden>
        <h3>Yönyliraportti – CSV tuonti</h3>
        <p class="notice">Esim. <strong>report_date, program, start_time, end_time</strong> + kanavakohtaiset metrikit.
        </p>
        <form id="overnight-form" enctype="multipart/form-data" onsubmit="return false;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <div class="form-row">
                <div class="field">
                    <label>CSV-tiedosto</label>
                    <div class="drop" data-drop="overnight">Pudota tiedosto tähän tai <u>valitse</u> alta</div>
                    <input type="file" name="csv" id="file-overnight" accept=".csv,.txt" required>
                    <div class="help">Hyväksytään .csv / .txt (UTF-8 suositeltu).</div>
                </div>
                <div class="field">
                    <label>Erotin</label>
                    <div class="inline">
                        <label><input type="radio" name="delimiter" value="auto" checked> Auto</label>
                        <label><input type="radio" name="delimiter" value="comma"> ,</label>
                        <label><input type="radio" name="delimiter" value="semicolon"> ;</label>
                        <label><input type="radio" name="delimiter" value="tab"> Tab</label>
                        <label><input type="radio" name="delimiter" value="pipe"> |</label>
                    </div>
                    <div class="divider"></div>
                    <label>Merkistö</label>
                    <select name="encoding">
                        <option value="UTF-8" selected>UTF-8</option>
                        <option value="ISO-8859-1">ISO-8859-1</option>
                        <option value="Windows-1252">Windows-1252</option>
                    </select>
                    <div class="divider"></div>
                    <label>Esikatselurivit</label>
                    <input type="number" name="preview_rows" min="1" max="20" value="3" style="max-width:120px">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>Päivämuoto CSV:ssä</label>
                    <select name="date_format">
                        <option value="Y-m-d" selected>YYYY-MM-DD</option>
                        <option value="d.m.Y">DD.MM.YYYY</option>
                        <option value="m/d/Y">MM/DD/YYYY</option>
                    </select>
                </div>
                <div class="field">
                    <label><input type="checkbox" name="has_header" value="1" checked> Ensimmäinen rivi on
                        otsikot</label>
                    <div class="divider"></div>
                    <label>Pakota channel (valinnainen)</label>
                    <input type="text" name="force_channel" placeholder="esim. MTV3">
                    <div class="divider"></div>
                    <label><input type="checkbox" name="truncate" value="1"> Tyhjennä taulu ennen tuontia
                        (TRUNCATE)</label>
                </div>
            </div>

            <div class="inline" style="margin-top:8px">
                <button class="btn" type="button" id="overnight-preview-btn">Esikatsele</button>
                <button class="btn" type="button" id="overnight-import-btn">Tuo CSV</button>
                <span id="overnight-status" class="pill small" style="display:none"></span>
            </div>
        </form>
        <div id="overnight-output" style="margin-top:14px"></div>
    </section>

    <p class="muted" style="margin-top:12px">
        Vinkki: jos sinulla on jo toimivat importterit, voit pitää parsinnan niissä ja käyttää tätä sivua vain
        yhtenäisenä käyttöliittymänä.
    </p>
</div>

<script>
    // Välilehdet
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            document.querySelectorAll('[role="tabpanel"]').forEach(p => p.hidden = true);
            document.getElementById('tab-' + tab).hidden = false;
        });
    });

    // Drag&drop kaikille drop-alueille
    function wireDrop(areaName, inputId) {
        const area = document.querySelector('.drop[data-drop="' + areaName + '"]');
        const input = document.getElementById('file-' + areaName);
        if (!area || !input) return;

        ['dragenter', 'dragover'].forEach(ev => {
            area.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); area.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(ev => {
            area.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); area.classList.remove('dragover'); });
        });
        area.addEventListener('drop', e => {
            const files = e.dataTransfer.files;
            if (files && files.length) { input.files = files; }
        });
        area.addEventListener('click', () => input.click());
    }
    wireDrop('oas', 'file-oas');
    wireDrop('oatv', 'file-oatv');
    wireDrop('overnight', 'file-overnight');

    const el = (tag, attrs = {}, children = []) => {
        const n = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'class') n.className = v; else if (k === 'html') n.innerHTML = v; else n.setAttribute(k, v);
        });
        children.forEach(c => n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
        return n;
    };

    function tableFrom(header, rows) {
        const t = el('table', {});
        const thead = el('thead'); const trh = el('tr');
        header.forEach(h => trh.appendChild(el('th', { html: String(h) })));
        thead.appendChild(trh); t.appendChild(thead);
        const tbody = el('tbody');
        rows.forEach(r => {
            const tr = el('tr');
            for (let i = 0; i < header.length; i++) {
                tr.appendChild(el('td', { html: r[i] !== undefined ? String(r[i]) : '' }));
            }
            tbody.appendChild(tr);
        });
        t.appendChild(tbody);
        return t;
    }

    async function oatvSend(action) {
        const form = document.getElementById('oatv-form');
        const out = document.getElementById('oatv-output');
        const status = document.getElementById('oatv-status');
        out.innerHTML = '';
        status.style.display = 'inline-block';
        status.textContent = (action === 'preview' ? 'Ladataan esikatselua…' : 'Tuodaan tietoja…');

        const fd = new FormData(form);
        fd.append('action', action);

        // (valinnainen) jos haluat lähettää delimiterin samalla logiikalla kuin OAS:
        const delimVal = form.querySelector('input[name="delimiter"]:checked')?.value || 'auto';
        fd.set('delimiter', delimVal); // serveri voi sivuuttaa tämän ja autodetectaa

        try {
            const res = await fetch('import_oatv.php', { method: 'POST', body: fd });

            // yritä JSON, muuten näytä raakateksti (kuten OAS)
            let json;
            try {
                json = await res.json();
            } catch (parseErr) {
                const txt = await res.text().catch(() => '');
                throw new Error('Palvelin ei palauttanut JSONia. Vastaus: ' + (txt ? txt.slice(0, 800) : '[tyhjä]'));
            }

            if (!json.ok) throw new Error(json.error || 'Tuntematon virhe');

            status.textContent = (action === 'preview' ? 'Esikatselu OK' : 'Tuonti OK');

            const el = (tag, attrs = {}, children = []) => {
                const n = document.createElement(tag);
                Object.entries(attrs).forEach(([k, v]) => {
                    if (k === 'class') n.className = v;
                    else if (k === 'html') n.innerHTML = v;
                    else n.setAttribute(k, v);
                });
                children.forEach(c => n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
                return n;
            };
            function tableFrom(header, rows) {
                const t = el('table', {}), thead = el('thead'), trh = el('tr');
                header.forEach(h => trh.appendChild(el('th', { html: String(h) })));
                thead.appendChild(trh); t.appendChild(thead);
                const tbody = el('tbody');
                rows.forEach(r => {
                    const tr = el('tr');
                    for (let i = 0; i < header.length; i++) {
                        tr.appendChild(el('td', { html: r[i] !== undefined ? String(r[i]) : '' }));
                    }
                    tbody.appendChild(tr);
                });
                t.appendChild(tbody);
                return t;
            }

            if (json.mode === 'preview') {
                const wrap = el('div', { class: 'card' });

                const detected = json.detected_label || '–';
                const p1 = el('p', { class: 'muted' }, [
                    `Käytetty erotin: `, el('strong', { html: json.delimiter_label }),
                    ' ', document.createTextNode('(automaattinen havainto: ' + detected + ')')
                ]);
                wrap.appendChild(p1);

                if (Array.isArray(json.header_raw) && json.header_raw.length) {
                    wrap.appendChild(el('h4', { html: 'Löydetty otsikkorivi' }));
                    const chips = el('div', { class: 'chips' });
                    json.header_raw.forEach(c =>
                        chips.appendChild(el('span', { class: 'chip', html: String(c) }))
                    );
                    wrap.appendChild(chips);
                }

                if (Array.isArray(json.header_norm) && json.header_norm.length) {
                    wrap.appendChild(el('p', { html: 'Normalisoidut nimet:' }));
                    const chips2 = el('div', { class: 'chips' });
                    json.header_norm.forEach(c =>
                        chips2.appendChild(el('span', { class: 'chip', html: String(c) }))
                    );
                    wrap.appendChild(chips2);
                }

                if (Array.isArray(json.rows_norm) && json.rows_norm.length) {
                    wrap.appendChild(el('h4', { html: `Ensimmäiset ${json.rows_norm.length} riviä (normalisoituna)` }));
                    const header = ['channel', 'artist', 'title', 'date', 'time', 'type', 'duration', 'hour'];
                    const rows = json.rows_norm.map(r => header.map(h => r[h] ?? ''));
                    wrap.appendChild(tableFrom(header, rows));
                }

                if (json.note) wrap.appendChild(el('p', { class: 'muted', html: json.note }));
                out.appendChild(wrap);
            } else if (json.mode === 'import') {
                const r = json.result;

                // Kortti 1: raakaesikatselu (ensimmäiset rivit suoraan CSV:stä)
                if (Array.isArray(r.preview_raw) && r.preview_raw.length) {
                    const wrapRaw = el('div', { class: 'card' });
                    const headerRaw = r.preview_raw[0].map((_, i) => 'Col ' + (i + 1));
                    wrapRaw.appendChild(el('h4', { html: `Ensimmäiset ${r.preview_raw.length} riviä (raakana)` }));
                    wrapRaw.appendChild(tableFrom(headerRaw, r.preview_raw));
                    out.appendChild(wrapRaw);
                }

                // Kortti 2: yhteenveto + normalisoitu data + virherivit
                const wrap = el('div', { class: 'card' });
                const p = el('p', {
                    html:
                        `<strong>Yhteensä:</strong> ${r.total} &nbsp; ` +
                        `<strong>Lisätty:</strong> ${r.inserted} &nbsp; ` +
                        `<strong>Ohitettu/virhe:</strong> ${r.skipped} &nbsp; ` +
                        `(Erotin: <em>${json.delimiter_label || ''}</em>)`
                });
                wrap.appendChild(p);

                if (r.preview && r.preview.length) {
                    wrap.appendChild(el('h4', { html: `Esikatselu (${r.preview.length} ensimmäistä normalisoituna)` }));
                    const header = ['account', 'campaign', 'cont...', 'code', 'commercial', 'length_sec', 'edition', 'raw_length'];
                    const rows = r.preview.map(row => header.map(h => row[h] ?? ''));
                    wrap.appendChild(tableFrom(header, rows));
                }

                if (r.errors && r.errors.length) {
                    wrap.appendChild(el('h4', { html: `Ohitetut / virheelliset rivit (${r.errors.length})` }));
                    const ul = el('ul');
                    r.errors.forEach(e => ul.appendChild(el('li', { class: 'err', html: String(e) })));
                    wrap.appendChild(ul);
                }

                out.appendChild(wrap);
            }
        } catch (err) {
            console.error(err);
            status.textContent = 'Virhe';
            out.innerHTML = `<p class="err"><strong>Virhe:</strong> ${String(err.message || err)}</p>`;
        } finally {
            setTimeout(() => { status.style.display = 'none'; }, 800);
        }
    }

    document.getElementById('oatv-preview-btn')?.addEventListener('click', () => oatvSend('preview'));
    document.getElementById('oatv-import-btn')?.addEventListener('click', () => oatvSend('import'));

    async function oasSend(action) {
        const form = document.getElementById('oas-form');
        const out = document.getElementById('oas-output');
        const status = document.getElementById('oas-status');
        out.innerHTML = '';
        status.style.display = 'inline-block';
        status.textContent = (action === 'preview' ? 'Ladataan esikatselua…' : 'Tuodaan tietoja…');

        const fd = new FormData(form);
        fd.append('action', action);

        try {
            const res = await fetch('import_oas.php?v=' + Date.now(), {
                method: 'POST',
                body: fd,
                cache: 'no-store'
            });


            // koita lukea JSON ensin
            let json;
            try {
                json = await res.json();
            } catch (parseErr) {
                // ei ollut JSONia -> hae raakateksti ja näytä
                const txt = await res.text().catch(() => '');
                throw new Error('Palvelin ei palauttanut JSONia. Vastaus: ' + (txt ? txt.slice(0, 500) : '[tyhjä]'));
            }

            if (!json.ok) throw new Error(json.error || 'Tuntematon virhe');

            status.textContent = (action === 'preview' ? 'Esikatselu OK' : 'Tuonti OK');

            if (json.mode === 'preview') {
                const wrap = el('div', { class: 'card' });

                const p1 = el('p', { class: 'muted' }, [
                    `Käytetty erotin: `, el('strong', { html: json.delimiter_label }),
                    ' ', document.createTextNode('(automaattinen havainto: ' + (json.detected_label || '–') + ')')
                ]);
                wrap.appendChild(p1);

                if (json.header_raw && json.header_raw.length) {
                    wrap.appendChild(el('h4', { html: 'Löydetty otsikkorivi' }));
                    const chips = el('div', { class: 'chips' });
                    json.header_raw.forEach(c => chips.appendChild(el('span', { class: 'chip', html: String(c) })));
                    wrap.appendChild(chips);
                }

                if (json.header_norm && json.header_norm.length) {
                    wrap.appendChild(el('p', { html: 'Normalisoidut nimet (heuristiikka):' }));
                    const chips2 = el('div', { class: 'chips' });
                    json.header_norm.forEach(c => chips2.appendChild(el('span', { class: 'chip', html: String(c) })));
                    wrap.appendChild(chips2);
                }

                if (json.rows && json.rows.length) {
                    // jos header_raw on, käytä sitä taulukon otsikkona – muuten generoi #1..#N
                    const header = (json.header_raw && json.header_raw.length)
                        ? json.header_raw
                        : json.rows[0].map((_, i) => 'Col ' + (i + 1));
                    wrap.appendChild(el('h4', { html: `Ensimmäiset ${json.rows.length} riviä (raakana)` }));
                    wrap.appendChild(tableFrom(header, json.rows));
                }

                if (json.note) wrap.appendChild(el('p', { class: 'muted', html: json.note }));
                out.appendChild(wrap);
            } else if (json.mode === 'import') {
                const r = json.result;
                const wrap = el('div', { class: 'card' });
                const p = el('p', {
                    html:
                        `<strong>Yhteensä:</strong> ${r.total} &nbsp; ` +
                        `<strong>Lisätty:</strong> ${r.inserted} &nbsp; ` +
                        `<strong>Ohitettu/virhe:</strong> ${r.skipped} &nbsp; ` +
                        `(Erotin: <em>${json.delimiter_label || ''}</em>)`
                });
                wrap.appendChild(p);

                if (r.preview && r.preview.length) {
                    wrap.appendChild(el('h4', { html: `Esikatselu (${r.preview.length} ensimmäistä normalisoituna)` }));
                    const header = ['account', 'campaign', 'contract_nr', 'date_played', 'hours', 'block', 'code', 'commercial', 'length_sec', 'edition', 'raw_length'];
                    const rows = r.preview.map(row => header.map(h => row[h] ?? ''));
                    wrap.appendChild(tableFrom(header, rows));
                }

                if (r.errors && r.errors.length) {
                    const details = el('details', {}, [
                        el('summary', { html: `Näytä virherivit (${r.errors.length})`, class: 'warn' })
                    ]);
                    const ul = el('ul');
                    r.errors.forEach(e => ul.appendChild(el('li', { class: 'err', html: String(e) })));
                    details.appendChild(ul);
                    wrap.appendChild(details);
                }
                out.appendChild(wrap);
            }
        } catch (err) {
            status.textContent = 'Virhe';
            out.innerHTML = `<p class="err"><strong>Virhe:</strong> ${String(err.message || err)}</p>`;
        } finally {
            setTimeout(() => { status.style.display = 'none'; }, 800);
        }
    }

    document.getElementById('oas-preview-btn')?.addEventListener('click', () => oasSend('preview'));
    document.getElementById('oas-import-btn')?.addEventListener('click', () => oasSend('import'));

</script>
<script>
    async function overnightSend(action) {
        const form = document.getElementById('overnight-form');
        const out = document.getElementById('overnight-output');
        const status = document.getElementById('overnight-status');
        out.innerHTML = '';
        status.style.display = 'inline-block';
        status.textContent = (action === 'preview' ? 'Ladataan esikatselua…' : 'Tuodaan tietoja…');

        const fd = new FormData(form);
        fd.append('action', action);

        // map radiot samalla tavalla kuin muissa
        const delimVal = form.querySelector('input[name="delimiter"]:checked')?.value || 'auto';
        // Lähetetään palvelimelle todellinen erotinmerkki
        const map = { comma: ',', semicolon: ';', tab: '\t', pipe: '|' };
        if (delimVal !== 'auto') {
            fd.set('delimiter', map[delimVal] ?? delimVal);
        } else {
            fd.set('delimiter', 'auto');
        }

        try {
            const res = await fetch('import_overnight.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.ok) throw new Error(json.error || 'Tuntematon virhe');
            status.textContent = (action === 'preview' ? 'Esikatselu OK' : 'Tuonti OK');

            const el = (tag, attrs = {}, children = []) => {
                const n = document.createElement(tag);
                Object.entries(attrs).forEach(([k, v]) => {
                    if (k === 'class') n.className = v;
                    else if (k === 'html') n.innerHTML = v;
                    else n.setAttribute(k, v);
                });
                children.forEach(c => n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
                return n;
            };
            function tableFrom(header, rows) {
                const t = el('table', {});
                const thead = el('thead'); const trh = el('tr');
                header.forEach(h => trh.appendChild(el('th', { html: String(h) })));
                thead.appendChild(trh); t.appendChild(thead);
                const tbody = el('tbody');
                rows.forEach(r => {
                    const tr = el('tr');
                    for (let i = 0; i < header.length; i++) {
                        tr.appendChild(el('td', { html: r[i] !== undefined ? String(r[i]) : '' }));
                    }
                    tbody.appendChild(tr);
                });
                t.appendChild(tbody);
                return t;
            }

            if (json.mode === 'preview') {
                const wrap = el('div', { class: 'card' });

                const p1 = el('p', { class: 'muted' }, [
                    `Käytetty erotin: `, el('strong', { html: json.delimiter_label }),
                    ' ', document.createTextNode('(automaattinen havainto: ' + (json.detected_label || '–') + ')')
                ]);
                wrap.appendChild(p1);

                if (Array.isArray(json.header_raw) && json.header_raw.length) {
                    wrap.appendChild(el('h4', { html: 'Löydetty otsikkorivi' }));
                    const chips = el('div', { class: 'chips' });
                    json.header_raw.forEach(c => chips.appendChild(el('span', { class: 'chip', html: String(c) })));
                    wrap.appendChild(chips);
                }
                if (Array.isArray(json.header_norm) && json.header_norm.length) {
                    wrap.appendChild(el('p', { html: 'Normalisoidut nimet:' }));
                    const chips2 = el('div', { class: 'chips' });
                    json.header_norm.forEach(c => chips2.appendChild(el('span', { class: 'chip', html: String(c) })));
                    wrap.appendChild(chips2);
                }

                // Normalisoidut rivit taulukkona jos saatavilla
                if (Array.isArray(json.rows_norm) && json.rows_norm.length) {
                    wrap.appendChild(el('h4', { html: `Ensimmäiset ${json.rows_norm.length} riviä (normalisoituna)` }));
                    const hdr = ['report_date', 'program', 'start_time', 'end_time', 'channel'];
                    const rows = json.rows_norm.map(r => hdr.map(h => r[h] ?? ''));
                    wrap.appendChild(tableFrom(hdr, rows));
                }
                // Fallback: jos normalisoituja rivejä ei ole, näytä raakadata
                if ((!Array.isArray(json.rows_norm) || !json.rows_norm.length) &&
                    Array.isArray(json.rows) && json.rows.length) {
                    wrap.appendChild(el('h4', { html: `Ensimmäiset ${json.rows.length} riviä (raakana)` }));
                    const header = (Array.isArray(json.header_raw) && json.header_raw.length)
                        ? json.header_raw
                        : json.rows[0].map((_, i) => 'Col ' + (i + 1));
                    wrap.appendChild(tableFrom(header, json.rows));
                }

                out.appendChild(wrap);
            } else if (json.mode === 'import') {
                const r = json.result;
                const wrap = el('div', { class: 'card' });
                wrap.appendChild(el('p', {
                    html:
                        `<strong>Yhteensä:</strong> ${r.total} &nbsp; ` +
                        `<strong>Lisätty/Päivitetty:</strong> ${r.inserted} &nbsp; ` +
                        `<strong>Ohitettu/virhe:</strong> ${r.skipped} &nbsp; ` +
                        `(Erotin: <em>${json.delimiter_label || ''}</em>)`
                }));
                if (r.preview && r.preview.length) {
                    wrap.appendChild(el('h4', { html: `Esikatselu (${r.preview.length} ensimmäistä normalisoituna)` }));
                    const hdr = Object.keys(r.preview[0]);
                    const rows = r.preview.map(row => hdr.map(h => row[h] ?? ''));
                    wrap.appendChild(tableFrom(hdr, rows));
                }
                if (r.errors && r.errors.length) {
                    const details = el('details', {}, [
                        el('summary', { html: `Näytä virherivit (${r.errors.length})`, class: 'warn' })
                    ]);
                    const ul = el('ul');
                    r.errors.forEach(e => ul.appendChild(el('li', { class: 'err', html: String(e) })));
                    details.appendChild(ul);
                    wrap.appendChild(details);
                }
                out.appendChild(wrap);
            }
        } catch (err) {
            status.textContent = 'Virhe';
            out.innerHTML = `<p class="err"><strong>Virhe:</strong> ${String(err.message || err)}</p>`;
        } finally {
            setTimeout(() => { status.style.display = 'none'; }, 800);
        }
    }

    document.getElementById('overnight-preview-btn')?.addEventListener('click', () => overnightSend('preview'));
    document.getElementById('overnight-import-btn')?.addEventListener('click', () => overnightSend('import'));
</script>
<script>
    console.log("manager_tools.php build:", "<?= date('c') ?>", "file:", "<?= addslashes(__FILE__) ?>");
</script>

<?php include __DIR__ . '/include/footer.php'; ?>
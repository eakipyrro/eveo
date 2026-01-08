<?php
// index.php – Etusivu (vaalea teema), käyttää header.php + footer.php
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/include/auth.php';   // do_login(), current_user(), require_login()
require_login();                               // ohjaa login.php:lle jos ei kirjautunut
$u = current_user();


// --- Sivun meta headerille ---
$pageTitle = 'Etusivu';
// Voit käyttää samaa css:ää kuin reports.php:ssä, mutta lisään tähän myös varmistukseksi pienen sivukohtaisen tyylin:
$pageStylesheets = $pageStylesheets ?? [];
$pageStylesheets[] = 'res/css/reports_light.css?v=' . time(); // sama vaalea teema kuin reports.php (jos käytössä)

// Yläoikean kulman käyttäjäpill + uloskirjautuminen (header.php voi tulostaa tämän jos muuttuja on asetettu)
$topbarRightHtml = sprintf(
    '<span class="pill">Kirjautunut: %s (%s)</span> <a class="link" href="logout.php">Kirjaudu ulos</a>',
    htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8'),
    htmlspecialchars($u['role'] ?? '', ENT_QUOTES, 'UTF-8')
);
$PAGE_TITLE = "EVEO";
include __DIR__ . '/include/header.php';
?>

<!-- Sivukohtainen kevyt tyyli (jos reports_light.css ei kata kaikkea) -->
<style>
    :root {
        --bg: #f7f8fb;
        --card: #ffffff;
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

    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
    }

    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }

    .card h3 {
        margin: 0 0 8px;
        color: var(--text);
    }

    .card p {
        margin: 0 0 12px;
        color: var(--muted);
    }

    .btn {
        display: inline-block;
        padding: 10px 12px;
        border-radius: 10px;
        background: var(--accent);
        color: #fff;
        text-decoration: none;
        font-weight: 600;
    }

    /* Light oletus (pysyy samana) */
    .pill {
        display: inline-block;
        border: 1px solid var(--border, #e5e7ef);
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        color: var(--muted, #6b7280);
        background: #fff;
    }

    /* Dark teema – käytä samoja muuttujia kuin reports.php:ssä */
    body.dark .pill {
        background: var(--surface-2, #0d142a);
        color: var(--label, #d2defa);
        border-color: var(--border, #2a3161);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .04);
    }

    /* Linkin kontrasti darkissa */
    body.dark .link {
        color: var(--accent, #4c6fff);
    }


    .link {
        color: var(--accent);
        text-decoration: none;
        margin-left: 12px;
        font-weight: 600;
    }

    h2 {
        color: var(--text);
        margin: 8px 0;
    }
</style>

<div class="wrap">
    <h2>Tervetuloa!</h2>
    <p class="muted" style="margin-top:6px">Tämä on roolipohjainen etusivu. Näkyvät kortit ja toiminnot riippuvat
        roolistasi.</p>

    <div class="grid">
        <!-- Profiili kaikille 
        <?php if (can('view_profile')): ?>
            <section class="card">
                <h3>Oma profiili</h3>
                <p>Muokkaa sähköpostia ja salasanaa.</p>
                <a class="btn" href="profile.php">Avaa profiili</a>
            </section>
        <?php endif; ?> -->

        <!-- Eveo-työkalut -->
        <?php if (can('view_eveo_tools')): ?>
            <section class="card">
                <h3>Eveo käyttäjä</h3>
                <p>Eveo käyttäjän työkalut.</p>
                <a class="btn" href="only_eveo.php">Avaa</a>
            </section>
        <?php endif; ?>

        <!-- Manager-työkalut -->
        <?php if (can('view_manager_tools')): ?>
            <section class="card">
                <h3>Managerin työkalut</h3>
                <p>Hyväksynnät ja päivän pyynnöt.</p>
                <a class="btn" href="manager_tools.php">Avaa</a>
            </section>
        <?php endif; ?>

        <!-- Raportit (eveo) -->
        <?php if (can('view_reports')): ?>
            <section class="card">
                <h3>Raportit</h3>
                <p>TRP Raportointi.</p>
                <a class="btn" href="reports.php">Avaa raportit</a>
            </section>
        <?php endif; ?>

        <!-- Admin-työkalut -->
        <?php if (can('view_admin_tools')): ?>
            <section class="card">
                <h3>Ylläpito</h3>
                <p>Käyttäjähallinta, roolit, lokit.</p>
                <a class="btn" href="admin/admin_index.php">Avaa</a>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/include/footer.php'; ?>
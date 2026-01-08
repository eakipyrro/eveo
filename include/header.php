<?php
// /include/header.php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/auth.php';

// --- Automaattinen sovelluksen URL-juuri ---
// Muodostaa URL-polun appin juuureen (kansioon, jossa index.php on)
if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $docRootFs = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        // app root = include/.. (eli kansio, jossa index.php sijaitsee)
        $appRootFs = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        if (!$docRootFs || !$appRootFs)
            return ''; // varmistus
        // Laske URL-polku dokumenttijuuren suhteena
        $rel = substr($appRootFs, strlen($docRootFs));
        $rel = $rel === false ? '' : $rel;
        $rel = '/' . ltrim($rel, '/');        // esim. "/alikansio"
        return rtrim($rel, '/');               // ilman loppuslashia
    }
}
$APP_BASE = app_base_path();               // esim. "" (juuressa) tai "/alikansio"

// Sivukohtaiset asetukset (voit asettaa nämä ennen includea)
$pageTitle = $PAGE_TITLE ?? 'Sovellus';
$requireLogin = $REQUIRE_LOGIN ?? true;             // bool
$requirePerm = $REQUIRE_PERMISSION ?? null;             // esim. 'view_reports' tai ['manager','admin']
$backHref = $BACK_HREF ?? null;             // esim. 'index.php'
$navLeftHtml = $NAV_LEFT ?? '';               // valinnaista lisänavigaatiota

// Suojaa sivu tarvittaessa
if ($requireLogin) {
    require_login();
}
if (!empty($requirePerm)) {
    if (is_array($requirePerm)) {
        require_role($requirePerm); // roolilista
    } else {
        if (!function_exists('require_permission')) {
            function require_permission(string $perm): void
            {
                if (session_status() === PHP_SESSION_NONE)
                    session_start();
                $role = $_SESSION['role'] ?? 'user';
                $map = [
                    'user' => ['view_profile'],
                    'eveo' => ['view_profile', 'view_eveo_tools'],
                    'manager' => ['view_profile', 'view_eveo_tools', 'view_manager_tools', 'view_reports'],
                    'admin' => ['view_profile', 'view_eveo_tools', 'view_manager_tools', 'view_admin_tools', 'view_reports'],
                ];
                if (!in_array($perm, $map[$role] ?? [], true)) {
                    http_response_code(403);
                    exit('Forbidden');
                }
            }
        }
        require_permission($requirePerm);
    }
}

$u = current_user();
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <style>
        :root {
            /* Vaalea teema (oletus) */
            --bg: #f7f8fb;
            --card: #ffffff;
            --border: #e5e7ef;
            --text: #111827;
            --muted: #6b7280;
            --accent: #2563eb;
            --accent-2: #1f4fd6;
            /* uusi */
            --topbar: #ffffff;
            --subbar: #f7fbfe;
            /* Badge & ghost-napit (vaalea) */
            --chip-bg: #ffffff;
            --chip-text: #0b3c59;
            --chip-border: #d0d7e2;

            --ghost-bg: #ffffff;
            --ghost-text: #0b3c59;
            --ghost-border: #d0d7e2;
            --ghost-hover: #f3f6fd;

            --on-accent: #ffffff;
            /* tekstiväri accent-taustalla */
        }

        body.dark {
            /* Tumma teema */
            --bg: #0b1020;
            --card: #11193a;
            --border: #2a3161;
            --text: #e7ecff;
            --muted: #aeb7e6;
            --accent: #4c6fff;
            --accent-2: #3857dd;
            /* siirrä tänne muuttujaksi */
            --topbar: #0f1730;
            --subbar: #0d142a;

            /* Badge & ghost-napit (tumma) */
            --chip-bg: #11193a;
            --chip-text: #e7ecff;
            --chip-border: #3a4580;

            --ghost-bg: #11193a;
            --ghost-text: #e7ecff;
            --ghost-border: #3a4580;
            --ghost-hover: #16224a;
        }

        /* Yleiset elementit */
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
        }

        .btn {
            background: var(--accent);
            color: #fff;
        }

        * {
            box-sizing: border-box
        }

        /* body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: #fff;
            color: #000
        } */

        .container {
            width: min(980px, 100%);
            margin: 0 auto;
            padding: 0 12px
        }

        /* Topbar */
        .topbar {
            border-bottom: 1px solid var(--border);
            background: var(--topbar);
        }

        .topbar .inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0
        }

        .brand a {
            color: var(--text);
        }

        .theme-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--ghost-border);
            background: var(--ghost-bg);
            color: var(--ghost-text);
            text-decoration: none;
            line-height: 1;
            font-size: 16px;
            transition: background .15s ease, border-color .15s ease;
        }

        .theme-toggle:hover {
            background: var(--ghost-hover);
            border-color: var(--accent);
        }

        /* Logo-asetukset */
        .brand-link {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            height: 40px;
            /* headerin korkeus, ei pakollinen */
        }

        .brand-logo {
            height: 26px;
            /* TÄMÄ määrittää logon koon (voit säätää esim. 22–30px) */
            width: auto;
            display: block;
            max-width: 160px;
            /* ei koskaan veny liian leveäksi */
            object-fit: contain;
        }

        /* Pienennetään mobiilissa vielä hieman */
        @media (max-width: 600px) {
            .brand-logo {
                height: 22px;
                max-width: 130px;
            }
        }

        .nav-left {
            margin-left: 16px
        }

        .user {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        /* käyttäjäbadge */
        .pill {
            display: inline-block;
            padding: 5px 10px;
            border: 1px solid var(--chip-border);
            border-radius: 999px;
            font-size: 12px;
            line-height: 1;
            background: var(--chip-bg);
            color: var(--chip-text);
        }

        /* ghost-tyyliset linkkinapit */
        .logout,
        .back {
            color: var(--ghost-text);
            text-decoration: none;
            border: 1px solid var(--ghost-border);
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--ghost-bg);
            transition: background .15s ease, border-color .15s ease, color .15s ease, transform .02s ease;
        }

        .logout:hover,
        .back:hover {
            background: var(--ghost-hover);
            border-color: var(--accent);
            color: var(--text);
        }

        .logout:active,
        .back:active {
            transform: translateY(1px);
        }

        .logout.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--on-accent);
        }

        .logout.primary:hover {
            background: #3857dd;
            /* var(--accent-2) */
            border-color: #3857dd;
        }

        /* Subbar otsikolle + takaisin-linkille */
        .subbar {
            border-top: 1px solid var(--border);
            background: var(--subbar);
        }

        .subbar .inner {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0
        }

        h1 {
            font-size: 22px;
            margin: 0
        }

        /* Sivusisällön kehys */
        main.page {
            padding: 18px 0
        }
    </style>
</head>

<body class="<?= htmlspecialchars($_SESSION['theme'] ?? 'light', ENT_QUOTES, 'UTF-8') ?>">
    <header class="topbar">
        <div class="container inner">
            <div style="display:flex; align-items:center; gap:12px;">
                <!-- Brändilinkki: aina sovelluksen juureen -->
                <div class="brand">
                    <a href="<?= $e($APP_BASE) ?>/index.php" class="brand-link" title="Etusivu">
                        <img src="<?= $e($APP_BASE) ?>/res/images/eveo-logo.png" alt="Eveo logo" class="brand-logo">
                    </a>
                </div>

                <nav class="nav-left"><?= $navLeftHtml ?></nav>
            </div>
            <div class="user">
                <a href="<?= $e($APP_BASE) ?>/include/toggle_theme.php" class="theme-toggle">🌗</a>
                <?php if ($u): ?>
                    <span class="pill"><?= $e($u['email']) ?> (<?= $e($u['role']) ?>)</span>
                    <!-- Logout: aina sovelluksen juureen -->
                    <a class="logout primary" href="<?= $e($APP_BASE) ?>/logout.php">Kirjaudu ulos</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="subbar">
            <div class="container inner">
                <?php if ($backHref): ?>
                    <a class="back" href="<?= $e($backHref) ?>">← Takaisin</a>
                <?php endif; ?>
                <h1><?= $e($pageTitle) ?></h1>
            </div>
        </div>
    </header>

    <main class="page">
        <div class="container">
            <!-- *** SIVUSISÄLTÖ ALKAA *** -->
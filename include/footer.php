<?php
// /include/footer.php
if (session_status() === PHP_SESSION_NONE) session_start();

// Selvitetään sovelluksen juuri samalla tavalla kuin headerissa
if (!function_exists('app_base_path')) {
  function app_base_path(): string {
    $docRootFs = rtrim(str_replace('\\','/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRootFs = str_replace('\\','/', realpath(__DIR__ . '/..'));
    if (!$docRootFs || !$appRootFs) return '';
    $rel = substr($appRootFs, strlen($docRootFs));
    $rel = $rel === false ? '' : $rel;
    $rel = '/' . ltrim($rel, '/');
    return rtrim($rel, '/');
  }
}
$APP_BASE = app_base_path();
?>
    </div> <!-- .container (avattu headerissa) -->
  </main>

  <footer class="site-footer">
    <div class="container">
      <div class="footer-content">
        <span>&copy; <?= date('Y') ?> AISS – Kaikki oikeudet pidätetään</span>
        <span class="version">Versio 1.0</span>
        <nav>
          <a href="<?= $APP_BASE ?>/index.php">Etusivu</a>
          <a href="<?= $APP_BASE ?>/logout.php">Kirjaudu ulos</a>
        </nav>
      </div>
    </div>
  </footer>

  <style>
    .site-footer {
      border-top: 1px solid #e5e7eb;
      background: var(--bg);
      color: #555;
      font-size: 13px;
      padding: 16px 0;
      margin-top: 40px;
    }
    .footer-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
    }
    .footer-content nav a {
      color: #2563eb;
      text-decoration: none;
      margin-left: 12px;
      font-weight: 500;
    }
    .footer-content nav a:hover {
      text-decoration: underline;
    }
    .version {
      color: #999;
      font-size: 12px;
    }
  </style>
</body>
</html>

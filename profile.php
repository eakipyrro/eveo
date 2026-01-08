<?php
// /public/profile.php – pelkkä placeholder, lisää oikea toteutus myöhemmin
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/include/auth.php';
require_login();
$u = current_user();
?>
<!doctype html>
<html lang="fi"><head><meta charset="utf-8"><title>Profiili</title></head>
<body>
<h1>Profiili</h1>
<p>Sähköposti: <strong><?php echo htmlspecialchars($u['email']); ?></strong></p>
<p>Rooli: <strong><?php echo htmlspecialchars($u['role']); ?></strong></p>
<p><a href="index.php">← Takaisin</a></p>
</body>
</html>
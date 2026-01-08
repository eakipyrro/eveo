<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE)
  session_start();

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/audit.php';

require_login();
require_permission('manage_users');

$pdo = db();
$role = $_SESSION['role'] ?? 'guest';
$selfId = (int) ($_SESSION['user_id'] ?? 0);

// NEW: yhden pyynnön korrelaatio-id audit-eventteihin
$correlationId = bin2hex(random_bytes(16));

if (empty($_SESSION['csrf_users'])) {
  $_SESSION['csrf_users'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_users'];



$err = null;
$info = null;

// NEW: pieni apu – vain admin voi kohdistaa toimiin manager/admin rooleihin
function can_touch_role(string $actorRole, string $targetRole): bool
{
  if ($actorRole === 'admin')
    return true;
  return !in_array($targetRole, ['manager', 'admin'], true);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = $_POST['action'] ?? '';
  $csrf = $_POST['csrf'] ?? '';
  if (!hash_equals($CSRF, $csrf)) {
    $err = 'CSRF check failed';
    audit_log('user_admin_error', [
      'page' => 'admin/users.php',
      'reason' => 'csrf_failed',
      'status' => 'fail',
      'correlation_id' => $correlationId
    ]);
  } else {
    try {
      if ($action === 'create') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $urole = (string) ($_POST['role'] ?? 'user');
        $pass = (string) ($_POST['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
          throw new RuntimeException('Virheellinen email');
        if ($pass === '')
          throw new RuntimeException('Salasana puuttuu');
        if (!in_array($urole, ['user', 'eveo', 'manager', 'admin'], true))
          throw new RuntimeException('Virheellinen rooli');
        // ei-admin ei voi luoda manager/admin
        if ($role !== 'admin' && in_array($urole, ['manager', 'admin'], true)) {
          throw new RuntimeException('Ei oikeutta antaa roolia');
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO users(email,name,role,password_hash,is_active) VALUES(?,?,?,?,1)");
        $st->execute([$email, $name, $urole, $hash]);

        audit_log('user_create', [
          'page' => 'admin/users.php',
          'target_email' => $email,
          'role' => $urole,
          'status' => 'success',
          'correlation_id' => $correlationId
        ]);
        $info = 'Käyttäjä luotu';
      } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $urole = (string) ($_POST['role'] ?? 'user');

        if ($id <= 0)
          throw new RuntimeException('Virheellinen id');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
          throw new RuntimeException('Virheellinen email');
        if (!in_array($urole, ['user', 'eveo', 'manager', 'admin'], true))
          throw new RuntimeException('Virheellinen rooli');

        // Selvitä kohteen nykyinen rooli
        $stC = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $stC->execute([$id]);
        $targetRole = (string) $stC->fetchColumn();
        if ($targetRole === '')
          throw new RuntimeException('Käyttäjää ei löydy');

        // estot: ei-admin ei saa koskea manager/admin, eikä myöskään korottaa ketään manager/adminiksi
        if (!can_touch_role($role, $targetRole))
          throw new RuntimeException('Ei oikeutta kohteeseen');
        if ($role !== 'admin' && in_array($urole, ['manager', 'admin'], true))
          throw new RuntimeException('Ei oikeutta antaa roolia');

        $st = $pdo->prepare("UPDATE users SET email=?, name=?, role=? WHERE id=?");
        $st->execute([$email, $name, $urole, $id]);

        audit_log('user_update', [
          'page' => 'admin/users.php',
          'target_user_id' => $id,
          'new_role' => $urole,
          'status' => 'success',
          'correlation_id' => $correlationId
        ]);
        $info = 'Päivitetty';
      } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0)
          throw new RuntimeException('Virheellinen id');
        if ($id === $selfId)
          throw new RuntimeException('Et voi muuttaa omaa aktiivisuuttasi');

        // kohteen roolisuojat
        $stC = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $stC->execute([$id]);
        $targetRole = (string) $stC->fetchColumn();
        if ($targetRole === '')
          throw new RuntimeException('Käyttäjää ei löydy');
        if (!can_touch_role($role, $targetRole))
          throw new RuntimeException('Ei oikeutta kohteeseen');

        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?")->execute([$id]);

        audit_log('user_toggle_active', [
          'page' => 'admin/users.php',
          'target_user_id' => $id,
          'status' => 'success',
          'correlation_id' => $correlationId
        ]);
        $info = 'Aktiivisuus vaihdettu';
      } elseif ($action === 'resetpw') {
        $id = (int) ($_POST['id'] ?? 0);
        $new = (string) ($_POST['new_password'] ?? '');
        if ($id <= 0 || $new === '')
          throw new RuntimeException('Virheellinen pyyntö');

        // kohteen roolisuojat
        $stC = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $stC->execute([$id]);
        $targetRole = (string) $stC->fetchColumn();
        if ($targetRole === '')
          throw new RuntimeException('Käyttäjää ei löydy');
        if (!can_touch_role($role, $targetRole))
          throw new RuntimeException('Ei oikeutta kohteeseen');

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $id]);

        audit_log('user_reset_password', [
          'page' => 'admin/users.php',
          'target_user_id' => $id,
          'status' => 'success',
          'correlation_id' => $correlationId
        ]);
        $info = 'Salasana vaihdettu';
      } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0)
          throw new RuntimeException('Virheellinen id');
        if ($id === $selfId)
          throw new RuntimeException('Et voi poistaa itseäsi');

        // kohteen roolisuojat
        $stC = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $stC->execute([$id]);
        $targetRole = (string) $stC->fetchColumn();
        if ($targetRole === '')
          throw new RuntimeException('Käyttäjää ei löydy');
        if (!can_touch_role($role, $targetRole))
          throw new RuntimeException('Ei oikeutta kohteeseen');

        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);

        audit_log('user_delete', [
          'page' => 'admin/users.php',
          'target_user_id' => $id,
          'status' => 'success',
          'correlation_id' => $correlationId
        ]);
        $info = 'Poistettu';
      }
    } catch (Throwable $e) {
      // NEW: sievät virheilmoitukset duplikaatti-emailille
      $msg = $e->getMessage();
      if ($e instanceof PDOException) {
        $sqlState = $e->getCode(); // '23000' duplikaatti tms.
        if ($sqlState === '23000') {
          $msg = 'Sähköposti on jo käytössä';
        }
      }
      $err = $msg;
      audit_log('user_admin_error', [
        'page' => 'admin/users.php',
        'reason' => $msg,
        'status' => 'fail',
        'correlation_id' => $correlationId
      ]);
    }
  }
}

// Suodatus
$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = $_GET['role'] ?? '';

$onlyAdminSeesPrivileged = ($role === 'admin');

$where = [];
$params = [];
if ($q !== '') {
  $where[] = "(email LIKE :q OR name LIKE :q)";
  $params[':q'] = "%$q%";
}
if ($roleFilter !== '' && in_array($roleFilter, ['user', 'eveo', 'manager', 'admin'], true)) {
  $where[] = "role = :rf";
  $params[':rf'] = $roleFilter;
}
// ei-adminilta piiloon manager/admin
if (!$onlyAdminSeesPrivileged) {
  $where[] = "role NOT IN ('manager','admin')";
}

// NEW: haetaan myös last_login_ip ja last_login_ua jos olemassa
$sql = "SELECT id,email,name,role,is_active,last_login_at";
try {
  // kokeillaan lisätä ip/ua – jos sarakkeita ei ole, jätetään pois
  $pdo->query("SELECT last_login_ip, last_login_ua FROM users LIMIT 0");
  $sql .= ", last_login_ip, last_login_ua";
} catch (Throwable $ignore) { /* ei haittaa */
}

$sql .= " FROM users";
if ($where)
  $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY role DESC, email ASC LIMIT 500";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Sivun meta
$PAGE_TITLE = 'Käyttäjähallinta';
$REQUIRE_LOGIN = true;
$REQUIRE_PERMISSION = 'manage_users';
$BACK_HREF = 'admin_index.php';
require_once __DIR__ . '/../include/header.php';
?>
<style>
  .table {
    width: 100%;
    max-width: 1200px;
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow-x: auto;
    /* 🔹 Mahdollistaa vaakaskrollin */
    overflow-y: hidden;
    /* ei pystyscrollia tähän diviin */
  }

  /* HUOM: min-width luo tarpeen vaakaskrollille pienillä näytöillä */
  .table table {
    width: 100%;
    min-width: 900px;
    /* esim. 900–1100px, säädä tarpeen mukaan */
    border-collapse: collapse;
  }

  .table th,
  .table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle
  }

  .table thead th {
    background: var(--panel);
    color: var(--label);
    position: sticky;
    top: 0
  }

  .controls {
    display: flex;
    gap: 8px;
    align-items: center;
    margin: 12px 0;
    flex-wrap: wrap
  }

  input[type="text"],
  select {
    padding: 8px 10px;
    border: 1px solid var(--input-border);
    border-radius: 8px;
    background: var(--input-bg);
    color: var(--input-text)
  }

  button.btn {
    padding: 8px 12px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    border-radius: 8px;
    cursor: pointer
  }

  button.primary {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff
  }

  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 500;
    border: 1px solid var(--border);
    text-transform: uppercase;
    letter-spacing: .03em;
  }

  .badge.on {
    background: rgba(46, 125, 50, 0.12);
    /* vaalea vihreä tausta */
    color: #1b5e20;
    /* tumma vihreä teksti */
    border-color: rgba(46, 125, 50, 0.4);
  }

  .badge.off {
    background: rgba(198, 40, 40, 0.08);
    /* vaalea punainen tausta */
    color: #b00020;
    /* tumma punainen teksti */
    border-color: rgba(198, 40, 40, 0.4);
  }


  form.inline {
    display: inline
  }

  .cell-small {
    font-size: 12px;
    opacity: .9
  }

  .ua {
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
    vertical-align: bottom
  }
</style>

<h1>Käyttäjähallinta</h1>
<?php if ($err): ?>
  <div class="badge off" style="display:block;margin:8px 0;"><?= h($err) ?></div><?php endif; ?>
<?php if ($info): ?>
  <div class="badge on" style="display:block;margin:8px 0;"><?= h($info) ?></div><?php endif; ?>

<div class="controls">
  <form method="get" action="" class="inline">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Hae nimellä tai emaililla">
    <select name="role">
      <option value="">Kaikki roolit</option>
      <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>user</option>
      <option value="eveo" <?= $roleFilter === 'eveo' ? 'selected' : '' ?>>eveo</option>
      <?php if ($onlyAdminSeesPrivileged): ?>
        <option value="manager" <?= $roleFilter === 'manager' ? 'selected' : '' ?>>manager</option>
        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>admin</option>
      <?php endif; ?>
    </select>
    <button class="btn">Suodata</button>
    <a class="btn" href="<?= h(basename(__FILE__)) ?>">Tyhjennä</a>
  </form>
</div>

<!-- Luonti -->
<div class="controls">
  <form method="post" action="" class="inline" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
    <input type="hidden" name="action" value="create">
    <input type="email" name="email" placeholder="email" required>
    <input type="text" name="name" placeholder="nimi">
    <select name="role" required>
      <option value="user">user</option>
      <option value="eveo">eveo</option>
      <?php if ($onlyAdminSeesPrivileged): ?>
        <option value="manager">manager</option>
        <option value="admin">admin</option>
      <?php endif; ?>
    </select>
    <input type="text" name="password" placeholder="alkusalasana" required>
    <button class="btn primary">Luo käyttäjä</button>
  </form>
</div>

<div class="table">
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Nimi</th>
        <th>Rooli</th>
        <th>Tila</th>
        <th>Viimeisin login</th>
        <th>Toiminnot</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int) $r['id'] ?></td>
          <td><?= h($r['email']) ?></td>
          <td><?= h($r['name']) ?></td>
          <td><?= h($r['role']) ?></td>
          <td>
            <?= ((int) $r['is_active'] === 1) ? '<span class="badge on">active</span>' : '<span class="badge off">inactive</span>' ?>
          </td>
          <td class="cell-small">
            <?php
            $lla = $r['last_login_at'] ?? null;
            $ip = $r['last_login_ip'] ?? null;
            $ua = $r['last_login_ua'] ?? null;
            ?>
            <div><?= $lla ? h($lla) : '—' ?></div>
            <?php if (!empty($ip) || !empty($ua)): ?>
              <div>
                <?php if (!empty($ip)): ?><span>IP: <?= h((string) $ip) ?></span><?php endif; ?>
                <?php if (!empty($ua)): ?>
                  <span class="ua" title="<?= h((string) $ua) ?>"> · UA: <?= h((string) $ua) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <!-- Päivitys -->
            <form method="post" action="" class="inline">
              <input type="hidden" name="csrf" value="<?= $CSRF ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="text" name="email" value="<?= h($r['email']) ?>" style="width:180px">
              <input type="text" name="name" value="<?= h($r['name']) ?>" style="width:130px">
              <select name="role">
                <option value="user" <?= $r['role'] === 'user' ? 'selected' : '' ?>>user</option>
                <option value="eveo" <?= $r['role'] === 'eveo' ? 'selected' : '' ?>>eveo</option>
                <?php if ($onlyAdminSeesPrivileged): ?>
                  <option value="manager" <?= $r['role'] === 'manager' ? 'selected' : '' ?>>manager</option>
                  <option value="admin" <?= $r['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                <?php endif; ?>
              </select>
              <button class="btn">Tallenna</button>
            </form>

            <!-- Aktivointi -->
            <form method="post" action="" class="inline" onsubmit="return confirm('Vaihda aktiivisuus?');">
              <input type="hidden" name="csrf" value="<?= $CSRF ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn"><?= (int) $r['is_active'] === 1 ? 'Deaktivoi' : 'Aktivoi' ?></button>
            </form>

            <!-- Reset pw -->
            <form method="post" action="" class="inline" onsubmit="return confirm('Vaihda salasana?');"
              autocomplete="off">
              <input type="hidden" name="csrf" value="<?= $CSRF ?>">
              <input type="hidden" name="action" value="resetpw">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="text" name="new_password" placeholder="uusi salasana" style="width:130px" required>
              <button class="btn">Reset</button>
            </form>

            <!-- Poisto -->
            <?php if ($role === 'admin'): ?>
              <form method="post" action="" class="inline" onsubmit="return confirm('Poistetaanko käyttäjä?');">
                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn">Poista</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../include/footer.php'; ?>
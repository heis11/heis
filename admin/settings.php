<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'password') {
        $st = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([(int)$admin['id']]);
        $hash = (string)$st->fetchColumn();
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $hash)) $errors[] = 'Your current password is wrong.';
        elseif ($p = password_problem($new)) $errors[] = $p;
        elseif ($new !== (string)($_POST['confirm'] ?? '')) $errors[] = 'The two new passwords do not match.';
        else {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$admin['id']]);
            session_regenerate_id(true);
            flash('ok', 'Password changed.');
            redirect('settings.php');
        }
    } elseif ($do === 'add_admin') {
        $u = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $u)) $errors[] = 'Username: 3 to 40 letters, numbers, dot, dash or underscore.';
        elseif ($p = password_problem($pw)) $errors[] = $p;
        else {
            $st = db()->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
            $st->execute([$u]);
            if ((int)$st->fetchColumn() > 0) $errors[] = 'That username is already taken.';
            else {
                db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$u, password_hash($pw, PASSWORD_DEFAULT)]);
                flash('ok', 'Admin "' . $u . '" created.');
                redirect('settings.php');
            }
        }
    }
}

$admins = db()->query('SELECT username, created_at, last_login_at FROM admins ORDER BY id')->fetchAll();
$env = [
    ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=')],
    ['pdo_mysql', extension_loaded('pdo_mysql') ? 'enabled' : 'missing', extension_loaded('pdo_mysql')],
    ['curl', function_exists('curl_init') ? 'enabled' : 'missing (needed for TMDB and Internet Archive)', function_exists('curl_init')],
    ['fileinfo', class_exists('finfo') ? 'enabled' : 'missing (needed for video uploads)', class_exists('finfo')],
    ['Upload limit (upload_max_filesize)', (string)ini_get('upload_max_filesize'), true],
    ['Post limit (post_max_size)', (string)ini_get('post_max_size'), true],
    ['TMDB key', TMDB_API_KEY !== '' ? 'set in config.php' : 'not set', TMDB_API_KEY !== ''],
    ['Default region', DEFAULT_REGION, true],
];

$pageTitle = 'Settings'; $active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<h1>Settings</h1>
<?php foreach ($errors as $e): ?><div class="flash error" role="alert"><?= h($e) ?></div><?php endforeach; ?>

<div class="cols">
  <section class="panel">
    <h2>Change my password</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="password">
      <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password <small>At least 10 characters.</small> <input type="password" name="new" required autocomplete="new-password"></label>
      <label>Repeat new password <input type="password" name="confirm" required autocomplete="new-password"></label>
      <button class="btn red" type="submit">Change password</button>
    </form>
  </section>
  <section class="panel">
    <h2>Add another admin</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="add_admin">
      <label>Username <input name="username" required maxlength="40" autocomplete="off"></label>
      <label>Password <small>At least 10 characters.</small> <input type="password" name="password" required autocomplete="new-password"></label>
      <button class="btn line" type="submit">Create admin</button>
    </form>
  </section>
</div>

<h2>Admins</h2>
<div class="tablewrap"><table>
  <tr><th>Username</th><th>Created</th><th>Last sign-in</th></tr>
<?php foreach ($admins as $a): ?>
  <tr><td><?= h($a['username']) ?></td><td><?= h($a['created_at']) ?></td><td><?= h($a['last_login_at'] ?? 'Never') ?></td></tr>
<?php endforeach; ?>
</table></div>

<h2>Environment</h2>
<div class="tablewrap"><table>
<?php foreach ($env as [$label, $value, $ok]): ?>
  <tr><td><?= h($label) ?></td><td><?= h($value) ?></td><td><span class="badge <?= $ok ? 'published' : 'draft' ?>"><?= $ok ? 'OK' : 'Fix' ?></span></td></tr>
<?php endforeach; ?>
</table></div>
<p class="hint">TMDB key, region and database settings live in <b>config.php</b>. They are not editable here, so the dashboard can never leak or overwrite them.</p>
<?php require __DIR__ . '/includes/footer.php'; ?>

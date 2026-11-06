<?php
require_once __DIR__ . '/includes/auth.php';
admin_session_start();
admin_headers();

if (!empty($_SESSION['admin_id'])) redirect('index.php');

$setup = admin_count() === 0;   // no admin yet: this page creates the first one
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($setup) {
        $confirm = (string)($_POST['confirm'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) $error = 'Username: 3 to 40 letters, numbers, dot, dash or underscore.';
        elseif ($p = password_problem($password)) $error = $p;
        elseif ($password !== $confirm) $error = 'The two passwords do not match.';
        else {
            // Insert only if the table is still empty, so two requests cannot both create a first admin.
            $st = db()->prepare('INSERT INTO admins (username, password_hash) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM admins)');
            $st->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            if ($st->rowCount() === 1) {
                admin_sign_in((int)db()->lastInsertId());
                flash('ok', 'Admin account created. Welcome to HEISVERSE.');
                redirect('index.php');
            }
            $setup = false;
            $error = 'An admin already exists. Sign in instead.';
        }
    } elseif (login_blocked()) {
        $error = 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();
        // Always run password_verify so response time does not reveal whether the username exists.
        $hash = $row['password_hash'] ?? '$2y$10$.vGA1O9wmRjrwAVXD98HNOgsNpDczlqm3Jq7KnEd1rVAGv3Fykk1a';
        if ($row && password_verify($password, $hash)) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            login_clear();
            admin_sign_in((int)$row['id']);
            redirect('index.php');
        }
        login_failed();
        $error = 'Wrong username or password.';
    }
}

if (!$error && !empty($_GET['expired'])) $error = 'You were signed out after 30 minutes of inactivity. Sign in again.';

$pageTitle = $setup ? 'Create admin' : 'Sign in';
$bare = true;
require __DIR__ . '/includes/header.php';
?>
<section class="card-box">
  <h1 class="brand">HEISVERSE</h1>
  <h2><?= $setup ? 'Create the first admin account' : 'Admin sign in' ?></h2>
<?php if ($setup): ?>
  <p class="hint">No admin exists yet. Choose a username and a strong password. This page only offers account creation while the admin list is empty.</p>
<?php endif; ?>
<?php if ($error): ?>
  <div class="flash error" role="alert"><?= h($error) ?></div>
<?php endif; ?>
  <form method="post" class="form" autocomplete="on">
    <?= csrf_field() ?>
    <label>Username <input name="username" required maxlength="40" autocomplete="username" value="<?= h($username) ?>" autofocus></label>
    <label>Password <input type="password" name="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"></label>
<?php if ($setup): ?>
    <label>Repeat password <input type="password" name="confirm" required autocomplete="new-password"></label>
<?php endif; ?>
    <button class="btn red" type="submit"><?= $setup ? 'Create admin and sign in' : 'Sign in' ?></button>
  </form>
  <p class="hint"><a href="../">Back to the public site</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

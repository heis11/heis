<?php
/* Admin authentication. Every admin page calls require_admin() first, so no page
   (and no POST action) can run without a valid, recent, server-side session. */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/csrf.php';

const ADMIN_IDLE_SECONDS = 1800;   // sign out after 30 minutes without activity
const LOGIN_MAX_FAILS = 8;         // failed sign-ins allowed per IP ...
const LOGIN_WINDOW_MINUTES = 15;   // ... within this many minutes

set_exception_handler(function (Throwable $e) {
    error_log('HEISVERSE admin error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if ($e instanceof PDOException) $msg = 'The database reported an error. Make sure database.sql was imported in phpMyAdmin (see README).';
    elseif ($e instanceof RuntimeException) $msg = $e->getMessage();
    else $msg = 'Something went wrong. Details are in the PHP error log (C:\xampp\apache\logs\error.log).';
    echo '<!doctype html><meta charset="utf-8"><title>HEISVERSE admin error</title><body style="font:16px system-ui;background:#050505;color:#f3f0f8;padding:40px"><h1>Admin error</h1><p>'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a style="color:#8b4dff" href="login.php">Back to sign in</a></p>';
});

function admin_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://image.tmdb.org https://archive.org https://*.archive.org; "
        . "style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
}

function admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')), '/');
    ini_set('session.use_strict_mode', '1');
    session_name('HVADMIN');
    session_set_cookie_params(['lifetime' => 0, 'path' => $path === '' ? '/' : $path, 'httponly' => true,
        'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

function admin_forget(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function admin_count(): int { return (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn(); }

/* Returns the signed-in admin ['id','username'] or redirects to the sign-in page. */
function require_admin(): array {
    admin_session_start();
    admin_headers();
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id < 1) redirect('login.php');
    if (time() - (int)($_SESSION['last'] ?? 0) > ADMIN_IDLE_SECONDS) { admin_forget(); redirect('login.php?expired=1'); }
    $st = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) { admin_forget(); redirect('login.php'); }
    $_SESSION['last'] = time();
    return $a;
}

function admin_sign_in(int $id): void {
    session_regenerate_id(true);
    $_SESSION = ['admin_id' => $id, 'last' => time()];
    csrf_token();
    db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
}

/* ---------- slow down password guessing ---------- */
function login_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45); }
function login_blocked(): bool {
    db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)');
    $st->execute([login_ip()]);
    return (int)$st->fetchColumn() >= LOGIN_MAX_FAILS;
}
function login_failed(): void { db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([login_ip()]); }
function login_clear(): void { db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([login_ip()]); }

function password_problem(string $pw): ?string {
    if (strlen($pw) < 10) return 'Use a password of at least 10 characters.';
    if (strlen($pw) > 200) return 'That password is too long.';
    return null;
}

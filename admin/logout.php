<?php
require_once __DIR__ . '/includes/auth.php';
admin_session_start();
admin_headers();
// Sign-out is POST-only with a CSRF token, so another site cannot log you out by loading an image.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    admin_forget();
    redirect('login.php');
}
redirect(empty($_SESSION['admin_id']) ? 'login.php' : 'index.php');

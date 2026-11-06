<?php
// CSRF protection: every admin form carries a secret token tied to the session.
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Call at the top of every POST handler. Stops the request when the token is missing or wrong.
function csrf_check(): void {
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('This form expired or is invalid. Go back, refresh the page, and try again.');
    }
}

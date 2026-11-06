<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

$m = ['rights_status' => 'unreviewed'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$d, $errors] = movie_collect_input($_POST, $_FILES, null);
    if (!$errors) {
        $id = movie_insert($d, (int)$admin['id']);
        flash('ok', 'Draft created. Now add a legal playback source, then publish.');
        redirect('movie-edit.php?id=' . $id);
    }
    $m = array_merge($m, $_POST, ['genres' => $d['genres'], 'trailer_key' => (string)($_POST['trailer'] ?? '')]);
}

$isEdit = false;
$pageTitle = 'Add movie'; $active = 'add';
require __DIR__ . '/includes/header.php';
?>
<h1>Add movie</h1>
<p class="hint">Looking for metadata? <a href="search-online.php">Search TMDB or the Internet Archive</a> and import a draft instead of typing it.</p>
<?php foreach ($errors as $e): ?><div class="flash error" role="alert"><?= h($e) ?></div><?php endforeach; ?>
<?php require __DIR__ . '/includes/movie-form.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

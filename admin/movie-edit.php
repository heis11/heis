<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$m = $id > 0 ? movie_find($id) : null;
if (!$m) { flash('error', 'Movie not found.'); redirect('movies.php'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'publish') {
        $errs = movie_publish($id);
        if ($errs) { flash('error', 'This movie cannot be published yet:'); foreach ($errs as $e) flash('error', '• ' . $e); }
        else flash('ok', 'Published. It now appears in the Catalog on the public site.');
        redirect('movie-edit.php?id=' . $id);
    }
    if ($action === 'unpublish') {
        movie_unpublish($id);
        flash('ok', 'Taken off the public site. The movie is kept as a draft.');
        redirect('movie-edit.php?id=' . $id);
    }
    [$d, $errors] = movie_collect_input($_POST, $_FILES, $m);
    if (!$errors) {
        movie_update($id, $d, (int)$admin['id']);
        flash('ok', 'Changes saved.');
        if (movie_revalidate($id)) flash('error', 'This movie no longer meets the publishing rules (rights or sources changed), so it was moved back to drafts.');
        redirect('movie-edit.php?id=' . $id);
    }
    $m = array_merge($m, $_POST, ['genres' => $d['genres'], 'trailer_key' => (string)($_POST['trailer'] ?? '')]);
}

$sources = movie_sources_of($id);
$activeSources = count(array_filter($sources, fn($s) => (int)$s['is_active'] === 1));
$blockers = $m['status'] === 'published' ? [] : can_publish($id);

$isEdit = true;
$pageTitle = 'Edit ' . $m['title']; $active = 'movies';
require __DIR__ . '/includes/header.php';
?>
<div class="split">
  <h1>Edit movie</h1>
  <span class="badge <?= h($m['status']) ?>"><?= h($m['status']) ?></span>
</div>
<?php foreach ($errors as $e): ?><div class="flash error" role="alert"><?= h($e) ?></div><?php endforeach; ?>

<section class="panel">
  <h2>Publishing</h2>
<?php if ($m['status'] === 'published'): ?>
  <p>This movie is live in the public Catalog. <?= $activeSources ?> active source<?= $activeSources === 1 ? '' : 's' ?>.</p>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn line" name="action" value="unpublish">Unpublish</button></form>
<?php else: ?>
<?php if ($blockers): ?>
  <p>Before this can be published:</p>
  <ul class="todo"><?php foreach ($blockers as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul>
<?php else: ?>
  <p>Rights are cleared and a playback source exists. This movie is ready to publish.</p>
<?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn red"<?= $blockers ? ' disabled' : '' ?> name="action" value="publish">Publish to public site</button></form>
<?php endif; ?>
  <a class="btn line" href="sources.php?movie_id=<?= $id ?>">Manage sources (<?= count($sources) ?>)</a>
<?php if (!empty($m['tmdb_id'])): ?>
  <a class="btn line" href="https://www.themoviedb.org/movie/<?= (int)$m['tmdb_id'] ?>" target="_blank" rel="noopener noreferrer">TMDB page</a>
<?php endif; ?>
</section>

<?php require __DIR__ . '/includes/movie-form.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

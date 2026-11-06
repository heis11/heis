<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

$c = db()->query("SELECT COUNT(*) AS total,
    SUM(status = 'published') AS published,
    SUM(status = 'draft') AS drafts,
    SUM(rights_status = 'unreviewed') AS unreviewed
    FROM movies")->fetch();
$sources = (int)db()->query('SELECT COUNT(*) FROM movie_sources WHERE is_active = 1')->fetchColumn();
$recent = db()->query('SELECT id, title, year, status, rights_status, updated_at FROM movies ORDER BY updated_at DESC, id DESC LIMIT 8')->fetchAll();

$checks = [
    ['Database connection', true, 'Connected to "' . DB_NAME . '".'],
    ['TMDB API key', TMDB_API_KEY !== '', TMDB_API_KEY !== '' ? 'Key found in config.php.' : 'Not set. Add it in config.php to search TMDB.'],
    ['PHP curl extension', function_exists('curl_init'), function_exists('curl_init') ? 'Enabled.' : 'Enable extension=curl in php.ini.'],
    ['Poster uploads folder', is_writable(dirname(__DIR__) . '/assets/uploads/posters'), 'assets/uploads/posters must be writable.'],
];

$pageTitle = 'Dashboard'; $active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<h1>Dashboard</h1>
<div class="stats">
  <div><b><?= (int)$c['total'] ?></b><span>Movies</span></div>
  <div><b><?= (int)$c['published'] ?></b><span>Published</span></div>
  <div><b><?= (int)$c['drafts'] ?></b><span>Drafts</span></div>
  <div><b><?= (int)$c['unreviewed'] ?></b><span>Rights not reviewed</span></div>
  <div><b><?= $sources ?></b><span>Active sources</span></div>
</div>

<div class="actions">
  <a class="btn red" href="search-online.php">Search online catalogs</a>
  <a class="btn line" href="movie-add.php">Add a movie by hand</a>
  <a class="btn line" href="../#catalog" target="_blank" rel="noopener">See public catalog</a>
</div>

<h2>Recently updated</h2>
<?php if ($recent): ?>
<div class="tablewrap"><table>
  <tr><th>Title</th><th>Status</th><th>Rights</th><th>Updated</th><th></th></tr>
<?php foreach ($recent as $r): ?>
  <tr>
    <td><?= h($r['title']) ?><?= $r['year'] ? ' <small>(' . h($r['year']) . ')</small>' : '' ?></td>
    <td><span class="badge <?= h($r['status']) ?>"><?= h($r['status']) ?></span></td>
    <td><span class="badge r-<?= h($r['rights_status']) ?>"><?= h(rights_options()[$r['rights_status']] ?? '') ?></span></td>
    <td><?= h($r['updated_at']) ?></td>
    <td><a href="movie-edit.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
  </tr>
<?php endforeach; ?>
</table></div>
<?php else: ?>
<div class="empty">No movies yet. Use <a href="search-online.php">Search online</a> to import metadata from TMDB or the Internet Archive, or <a href="movie-add.php">add one by hand</a>.</div>
<?php endif; ?>

<h2>System check</h2>
<div class="tablewrap"><table>
<?php foreach ($checks as [$label, $ok, $note]): ?>
  <tr><td><?= h($label) ?></td><td><span class="badge <?= $ok ? 'published' : 'draft' ?>"><?= $ok ? 'OK' : 'Needs attention' ?></span></td><td><?= h($note) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

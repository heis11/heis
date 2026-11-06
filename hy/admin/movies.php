<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $do = (string)($_POST['do'] ?? '');
    $m = $id > 0 ? movie_find($id) : null;
    if (!$m) flash('error', 'Movie not found.');
    elseif ($do === 'publish') {
        $errs = movie_publish($id);
        if ($errs) { flash('error', '"' . $m['title'] . '" cannot be published yet:'); foreach ($errs as $e) flash('error', '• ' . $e); }
        else flash('ok', '"' . $m['title'] . '" is now published and visible on the public site.');
    } elseif ($do === 'unpublish') {
        movie_unpublish($id);
        flash('ok', '"' . $m['title'] . '" was taken off the public site and kept as a draft.');
    } elseif ($do === 'delete') {
        movie_delete($id);
        flash('ok', '"' . $m['title'] . '" was deleted.');
    }
    redirect('movies.php' . (!empty($_POST['back']) ? '?' . http_build_query(['q' => (string)($_POST['bq'] ?? ''), 'status' => (string)($_POST['bs'] ?? ''), 'page' => (int)($_POST['bp'] ?? 1)]) : ''));
}

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = 'm.title LIKE ?'; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
if ($status === 'published' || $status === 'draft') { $where[] = 'm.status = ?'; $args[] = $status; }
elseif ($status === 'unreviewed') { $where[] = "m.rights_status = 'unreviewed'"; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM movies m WHERE $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$off = ($page - 1) * $per;
$st = db()->prepare("SELECT m.*, (SELECT COUNT(*) FROM movie_sources s WHERE s.movie_id = m.id AND s.is_active = 1) AS src
    FROM movies m WHERE $w ORDER BY m.updated_at DESC, m.id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$rows = $st->fetchAll();

$pageTitle = 'Movies'; $active = 'movies';
require __DIR__ . '/includes/header.php';
?>
<div class="split"><h1>Movies</h1><a class="btn red" href="movie-add.php">Add movie</a></div>

<form method="get" class="filters">
  <input type="search" name="q" placeholder="Search by title" value="<?= h($q) ?>" aria-label="Search by title">
  <select name="status" aria-label="Filter">
    <option value="">All movies</option>
    <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>Published</option>
    <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>>Drafts</option>
    <option value="unreviewed"<?= $status === 'unreviewed' ? ' selected' : '' ?>>Rights not reviewed</option>
  </select>
  <button class="btn line sm" type="submit">Filter</button>
</form>

<?php if ($rows): ?>
<div class="tablewrap"><table>
  <tr><th></th><th>Title</th><th>Status</th><th>Rights</th><th>Sources</th><th>Actions</th></tr>
<?php foreach ($rows as $r): $img = movie_poster_url($r, 'w154', '../'); ?>
  <tr>
    <td class="pc"><?php if ($img): ?><img class="mini" src="<?= h($img) ?>" alt="" loading="lazy"><?php endif; ?></td>
    <td><a href="movie-edit.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a><?= $r['year'] ? ' <small>(' . h($r['year']) . ')</small>' : '' ?></td>
    <td><span class="badge <?= h($r['status']) ?>"><?= h($r['status']) ?></span></td>
    <td><span class="badge r-<?= h($r['rights_status']) ?>"><?= h(rights_options()[$r['rights_status']] ?? '') ?></span></td>
    <td><a href="sources.php?movie_id=<?= (int)$r['id'] ?>"><?= (int)$r['src'] ?> active</a></td>
    <td class="acts">
      <a class="btn line sm" href="movie-edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
      <form method="post" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="back" value="1"><input type="hidden" name="bq" value="<?= h($q) ?>"><input type="hidden" name="bs" value="<?= h($status) ?>"><input type="hidden" name="bp" value="<?= $page ?>">
        <button class="btn <?= $r['status'] === 'published' ? 'line' : 'red' ?> sm" name="do" value="<?= $r['status'] === 'published' ? 'unpublish' : 'publish' ?>"><?= $r['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button>
        <button class="btn line sm danger" name="do" value="delete" data-confirm="Delete &quot;<?= h($r['title']) ?>&quot; and its sources? This cannot be undone.">Delete</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</table></div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?><a class="btn line sm" href="?<?= h(http_build_query(['q' => $q, 'status' => $status, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?> (<?= $total ?> movies)</span>
<?php if ($page < $pages): ?><a class="btn line sm" href="?<?= h(http_build_query(['q' => $q, 'status' => $status, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php else: ?>
<div class="empty"><?= ($q !== '' || $status !== '') ? 'No movies match this filter.' : 'No movies yet. Add one or import from <a href="search-online.php">Search online</a>.' ?></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

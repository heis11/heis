<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

$movieId = (int)($_GET['movie_id'] ?? $_POST['movie_id'] ?? 0);
$movie = $movieId > 0 ? movie_find($movieId) : null;
if ($movieId > 0 && !$movie) { flash('error', 'Movie not found.'); redirect('sources.php'); }

$errors = [];
$form = ['source_type' => 'internet_archive', 'ref' => '', 'label' => '', 'license_name' => '', 'license_url' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $movie) {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $sid = (int)($_POST['source_id'] ?? 0);
    if ($do === 'add') {
        $form = array_merge($form, array_intersect_key($_POST, $form));
        $type = (string)$form['source_type'];
        if (!isset(source_types()[$type])) $errors[] = 'Choose a source type.';
        else {
            $ref = null; $uploaded = null;
            $hasFile = !empty($_FILES['video']) && ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($type === 'direct_file' && $hasFile) {
                try { $ref = $uploaded = save_video_upload($_FILES['video']); } catch (RuntimeException $x) { $errors[] = $x->getMessage(); }
            } else {
                [$ref, $err] = normalize_source($type, (string)$form['ref']);
                if ($err) $errors[] = $err;
            }
            $licUrl = trim((string)$form['license_url']);
            if ($licUrl !== '' && !preg_match('~^https?://~i', $licUrl)) $errors[] = 'License link must start with http:// or https://.';
            if (!$errors) {
                $dup = db()->prepare('SELECT COUNT(*) FROM movie_sources WHERE movie_id = ? AND source_type = ? AND ref = ?');
                $dup->execute([$movieId, $type, $ref]);
                if ((int)$dup->fetchColumn() > 0) $errors[] = 'This source is already attached to the movie.';
            }
            if ($errors && $uploaded) delete_upload($uploaded);
            if (!$errors) {
                source_insert($movieId, $type, $ref, trim((string)$form['label']), trim((string)$form['license_name']), $licUrl);
                flash('ok', 'Source added. Remember to confirm the rights review before publishing.');
                redirect('sources.php?movie_id=' . $movieId);
            }
        }
    } elseif ($sid > 0) {
        $st = db()->prepare('SELECT * FROM movie_sources WHERE id = ? AND movie_id = ?');
        $st->execute([$sid, $movieId]);
        $src = $st->fetch();
        if (!$src) flash('error', 'Source not found.');
        elseif ($do === 'toggle') {
            db()->prepare('UPDATE movie_sources SET is_active = 1 - is_active WHERE id = ?')->execute([$sid]);
            flash('ok', 'Source updated.');
        } elseif ($do === 'delete') {
            if ($src['source_type'] === 'direct_file') delete_upload($src['ref']);
            db()->prepare('DELETE FROM movie_sources WHERE id = ?')->execute([$sid]);
            flash('ok', 'Source removed.');
        }
        if ($src && movie_revalidate($movieId)) flash('error', 'This movie has no active source left, so it was taken off the public site and moved to drafts.');
        redirect('sources.php?movie_id=' . $movieId);
    }
}

$pageTitle = 'Sources'; $active = 'sources';
require __DIR__ . '/includes/header.php';
$types = source_types();

if (!$movie):
    $rows = db()->query('SELECT s.*, m.title FROM movie_sources s JOIN movies m ON m.id = s.movie_id ORDER BY s.id DESC LIMIT 200')->fetchAll();
?>
<h1>Sources</h1>
<p class="hint">A source is a place where a full movie may legally be watched. Pick a movie to manage its sources.</p>
<?php if ($rows): ?>
<div class="tablewrap"><table>
  <tr><th>Movie</th><th>Type</th><th>Reference</th><th>License</th><th>State</th><th></th></tr>
<?php foreach ($rows as $s): ?>
  <tr>
    <td><?= h($s['title']) ?></td>
    <td><?= h($types[$s['source_type']] ?? $s['source_type']) ?></td>
    <td class="wrap"><?= h($s['ref']) ?></td>
    <td><?= h($s['license_name'] ?: 'Not stated') ?></td>
    <td><span class="badge <?= $s['is_active'] ? 'published' : 'draft' ?>"><?= $s['is_active'] ? 'active' : 'off' ?></span></td>
    <td><a href="sources.php?movie_id=<?= (int)$s['movie_id'] ?>">Manage</a></td>
  </tr>
<?php endforeach; ?>
</table></div>
<?php else: ?>
<div class="empty">No sources yet. Open a movie from <a href="movies.php">Movies</a> and choose Manage sources, or import from <a href="search-online.php">Search online</a>.</div>
<?php endif; ?>
<?php else:
    $sources = movie_sources_of($movieId);
?>
<div class="split"><h1>Sources: <?= h($movie['title']) ?></h1><a class="btn line" href="movie-edit.php?id=<?= $movieId ?>">Back to movie</a></div>
<p class="hint">Add only places where you have confirmed the film may be shown: the Internet Archive item page lists its rights, an official channel
  uploaded it, the rights holder offers it free, or you hold the rights to a file. Paid or subscription services and pirate sites do not belong here.</p>
<?php foreach ($errors as $e): ?><div class="flash error" role="alert"><?= h($e) ?></div><?php endforeach; ?>

<?php if ($sources): ?>
<div class="tablewrap"><table>
  <tr><th>Type</th><th>Reference</th><th>License</th><th>State</th><th>Actions</th></tr>
<?php foreach ($sources as $s): ?>
  <tr>
    <td><?= h($types[$s['source_type']] ?? $s['source_type']) ?><?= $s['label'] !== '' ? '<br><small>' . h($s['label']) . '</small>' : '' ?></td>
    <td class="wrap"><?= h($s['ref']) ?></td>
    <td><?= h($s['license_name'] ?: 'Not stated') ?><?php if ($s['license_url'] !== ''): ?><br><a href="<?= h($s['license_url']) ?>" target="_blank" rel="noopener noreferrer">License link</a><?php endif; ?></td>
    <td><span class="badge <?= $s['is_active'] ? 'published' : 'draft' ?>"><?= $s['is_active'] ? 'active' : 'off' ?></span></td>
    <td class="acts"><form method="post" class="inline">
      <?= csrf_field() ?><input type="hidden" name="movie_id" value="<?= $movieId ?>"><input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>">
      <button class="btn line sm" name="do" value="toggle"><?= $s['is_active'] ? 'Turn off' : 'Turn on' ?></button>
      <button class="btn line sm danger" name="do" value="delete" data-confirm="Remove this source?">Remove</button>
    </form></td>
  </tr>
<?php endforeach; ?>
</table></div>
<?php else: ?>
<div class="empty">No sources yet for this movie.</div>
<?php endif; ?>

<h2>Add a source</h2>
<form method="post" enctype="multipart/form-data" class="form" novalidate>
  <?= csrf_field() ?><input type="hidden" name="movie_id" value="<?= $movieId ?>"><input type="hidden" name="do" value="add">
  <label>Type
    <select name="source_type" id="srcType">
<?php foreach ($types as $k => $label): ?>
      <option value="<?= h($k) ?>"<?= $form['source_type'] === $k ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
    </select></label>
  <label>Reference
    <small id="srcHint">Internet Archive identifier or archive.org/details link.</small>
    <input name="ref" id="srcRef" maxlength="500" value="<?= h($form['ref']) ?>"></label>
  <label id="srcFileBox" hidden>Or upload the video file <small>MP4, WebM or OGV. Large files need a higher upload limit in php.ini (see README).</small>
    <input type="file" name="video" accept="video/mp4,video/webm,video/ogg"></label>
  <label>Label <small>Optional, shown on the watch button, for example "Full film (Internet Archive)".</small>
    <input name="label" maxlength="120" value="<?= h($form['label']) ?>"></label>
  <div class="row2">
    <label>License name <input name="license_name" maxlength="160" placeholder="Public Domain Mark 1.0" value="<?= h($form['license_name']) ?>"></label>
    <label>License link <input name="license_url" maxlength="500" placeholder="https://..." value="<?= h($form['license_url']) ?>"></label>
  </div>
  <div class="actions"><button class="btn red" type="submit">Add source</button></div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
/* The movie form, shared by movie-add.php and movie-edit.php.
   Expects: $m (current values), $isEdit (bool). Posts back to the page that included it. */
$val = fn(string $k) => h($m[$k] ?? '');
$genresShown = h(str_replace(',', ', ', (string)($m['genres'] ?? '')));
$poster = $isEdit ? movie_poster_url($m, 'w342', '../') : null;
$reviewedBy = '';
if ($isEdit && !empty($m['rights_reviewed_at'])) {
    $st = db()->prepare('SELECT username FROM admins WHERE id = ?');
    $st->execute([(int)($m['rights_reviewed_by'] ?? 0)]);
    $reviewedBy = 'Last reviewed ' . $m['rights_reviewed_at'] . ' by ' . ($st->fetchColumn() ?: 'a removed admin') . '.';
}
?>
<form method="post" enctype="multipart/form-data" class="form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <fieldset>
    <legend>Movie details</legend>
    <label>Title <input name="title" required maxlength="255" value="<?= $val('title') ?>"></label>
    <div class="row2">
      <label>Year <input name="year" inputmode="numeric" maxlength="4" value="<?= $val('year') ?>"></label>
      <label>Runtime (minutes) <input name="runtime" inputmode="numeric" maxlength="4" value="<?= $val('runtime') ?>"></label>
      <label>Rating (0 to 10) <input name="rating" inputmode="decimal" maxlength="4" value="<?= ((float)($m['rating'] ?? 0)) > 0 ? $val('rating') : '' ?>"></label>
    </div>
    <label>Tagline <input name="tagline" maxlength="255" value="<?= $val('tagline') ?>"></label>
    <label>Genres <small>separate with commas, for example Drama, Comedy</small>
      <input name="genres" maxlength="255" value="<?= $genresShown ?>"></label>
    <label>Overview <textarea name="overview" rows="5" maxlength="5000"><?= $val('overview') ?></textarea></label>
    <label>Trailer <small>YouTube link or video id (trailers only, not the full movie)</small>
      <input name="trailer" maxlength="200" value="<?= h($m['trailer_key'] ?? '') ?>"></label>
  </fieldset>

  <fieldset>
    <legend>Poster</legend>
<?php if ($poster): ?>
    <img class="thumb" src="<?= h($poster) ?>" alt="Current poster">
<?php endif; ?>
    <label>Upload a poster <small>JPG, PNG or WebP, up to 3 MB. Replaces the current one.</small>
      <input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label>
<?php if ($isEdit && !empty($m['poster_file'])): ?>
    <label class="chk"><input type="checkbox" name="remove_poster" value="1"> Remove the uploaded poster</label>
<?php endif; ?>
<?php if ($isEdit && !empty($m['poster_path']) && empty($m['poster_file'])): ?>
    <p class="hint">This poster comes from TMDB. Uploading your own replaces it.</p>
<?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>Rights review</legend>
    <p class="hint">A movie can be published only when you have confirmed that you are allowed to show it, and written down why.
      TMDB data and trailers are metadata, not permission to show the full film.</p>
    <label>Rights status
      <select name="rights_status">
<?php foreach (rights_options() as $k => $label): ?>
        <option value="<?= h($k) ?>"<?= ($m['rights_status'] ?? 'unreviewed') === $k ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
      </select></label>
    <label>Rights notes <small>The license, contract, or public-domain reasoning. Required to publish.</small>
      <textarea name="rights_notes" rows="4" maxlength="3000"><?= $val('rights_notes') ?></textarea></label>
    <label>Proof link <small>Optional https:// link to the license, the Internet Archive rights statement, or a permission record.</small>
      <input name="rights_proof_url" maxlength="500" value="<?= $val('rights_proof_url') ?>"></label>
<?php if ($reviewedBy): ?>
    <p class="hint"><?= h($reviewedBy) ?></p>
<?php endif; ?>
  </fieldset>

  <div class="actions">
    <button class="btn red" type="submit"><?= $isEdit ? 'Save changes' : 'Create draft' ?></button>
    <a class="btn line" href="movies.php">Cancel</a>
  </div>
</form>

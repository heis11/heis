<?php
require_once __DIR__ . '/includes/auth.php';
$admin = require_admin();

/* Internet Archive fields can be a string or a list of strings. */
function ia_text($v): string { return trim(is_array($v) ? (string)reset($v) : (string)$v); }
function ia_license_name(string $url): string {
    if ($url === '') return '';
    if (stripos($url, 'publicdomain') !== false) return 'Public domain (as declared on the Internet Archive)';
    if (stripos($url, 'creativecommons.org') !== false) return 'Creative Commons (as declared on the Internet Archive)';
    return 'License link declared on the Internet Archive';
}
function safe_img_path($p): ?string { return is_string($p) && preg_match('~^/[A-Za-z0-9._-]{1,200}$~', $p) ? $p : null; }

/* ---------- import (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $back = 'search-online.php?' . http_build_query(['src' => (string)($_POST['src'] ?? 'tmdb'), 'q' => (string)($_POST['q'] ?? '')]);
    try {
        if ($do === 'import_tmdb') {
            $tid = (int)($_POST['tmdb_id'] ?? 0);
            if ($tid < 1) throw new RuntimeException('Invalid TMDB id.');
            $st = db()->prepare('SELECT id FROM movies WHERE tmdb_id = ?');
            $st->execute([$tid]);
            if ($ex = $st->fetchColumn()) { flash('ok', 'This movie was already imported. Opening it.'); redirect('movie-edit.php?id=' . (int)$ex); }

            $d = tmdb_request('/movie/' . $tid, ['append_to_response' => 'videos']);
            $trailer = null;
            foreach ($d['videos']['results'] ?? [] as $v) {
                if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer' && preg_match('/^[\w-]{11}$/', $v['key'] ?? '')) {
                    if ($trailer === null || !empty($v['official'])) $trailer = $v['key'];
                }
            }
            $genres = array_map(fn($g) => str_replace(',', ' ', (string)($g['name'] ?? '')), $d['genres'] ?? []);
            $data = [
                'tmdb_id' => $tid,
                'title' => mb_substr(trim((string)($d['title'] ?? 'Untitled')), 0, 255) ?: 'Untitled',
                'year' => preg_match('/^(\d{4})/', (string)($d['release_date'] ?? ''), $ym) ? (int)$ym[1] : null,
                'tagline' => mb_substr((string)($d['tagline'] ?? ''), 0, 255),
                'overview' => mb_substr((string)($d['overview'] ?? ''), 0, 5000),
                'runtime' => ((int)($d['runtime'] ?? 0)) > 0 ? min(1000, (int)$d['runtime']) : null,
                'genres' => mb_substr(implode(',', array_filter($genres)), 0, 255),
                'rating' => min(10, round((float)($d['vote_average'] ?? 0), 1)),
                'poster_path' => safe_img_path($d['poster_path'] ?? null),
                'backdrop_path' => safe_img_path($d['backdrop_path'] ?? null),
                'poster_file' => null, 'trailer_key' => $trailer,
                'rights_status' => 'unreviewed', 'rights_notes' => '', 'rights_proof_url' => null,
            ];
            $id = movie_insert($data, (int)$admin['id']);
            flash('ok', 'Imported metadata as a draft. TMDB does not provide the full film: add a legal source and review the rights before publishing.');
            redirect('movie-edit.php?id=' . $id);
        }

        if ($do === 'import_ia') {
            [$ident, $err] = normalize_source('internet_archive', (string)($_POST['identifier'] ?? ''));
            if ($err) throw new RuntimeException($err);
            $meta = ia_metadata($ident);
            $licUrl = ia_text($meta['licenseurl'] ?? '');
            $mid = (int)($_POST['movie_id'] ?? 0);

            if ($mid > 0) {
                if (!movie_find($mid)) throw new RuntimeException('The movie you chose no longer exists.');
            } else {
                $date = ia_text($meta['year'] ?? ($meta['date'] ?? ''));
                $desc = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode(ia_text($meta['description'] ?? ''), ENT_QUOTES, 'UTF-8'))));
                $mid = movie_insert([
                    'tmdb_id' => null, 'title' => mb_substr(ia_text($meta['title'] ?? $ident), 0, 255) ?: $ident,
                    'year' => preg_match('/(\d{4})/', $date, $ym) && (int)$ym[1] >= 1888 && (int)$ym[1] <= (int)date('Y') + 2 ? (int)$ym[1] : null,
                    'tagline' => '', 'overview' => mb_substr($desc, 0, 5000), 'runtime' => null, 'genres' => '', 'rating' => 0.0,
                    'poster_path' => null, 'backdrop_path' => null, 'poster_file' => null, 'trailer_key' => null,
                    'rights_status' => 'unreviewed', 'rights_notes' => '', 'rights_proof_url' => 'https://archive.org/details/' . rawurlencode($ident),
                ], (int)$admin['id']);
            }
            $dup = db()->prepare("SELECT COUNT(*) FROM movie_sources WHERE movie_id = ? AND source_type = 'internet_archive' AND ref = ?");
            $dup->execute([$mid, $ident]);
            if ((int)$dup->fetchColumn() === 0) {
                source_insert($mid, 'internet_archive', $ident, 'Full film (Internet Archive)', ia_license_name($licUrl), $licUrl);
            }
            flash('ok', 'Internet Archive source attached. The license shown is what the uploader declared: open the item page and confirm it in the rights review before publishing.');
            redirect('movie-edit.php?id=' . $mid);
        }
    } catch (PDOException $e) {
        error_log('HEISVERSE import error: ' . $e->getMessage());
        flash('error', 'The database could not save this import. Make sure database.sql was imported.');
        redirect($back);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect($back);
    }
    redirect($back);
}

/* ---------- search (GET) ---------- */
$src = ($_GET['src'] ?? 'tmdb') === 'archive' ? 'archive' : 'tmdb';
$q = trim((string)($_GET['q'] ?? ''));
$results = []; $searchError = '';
if ($q !== '') {
    try {
        if (mb_strlen($q) > 100) throw new RuntimeException('Type 1 to 100 characters to search.');
        if ($src === 'tmdb') $results = array_slice(tmdb_request('/search/movie', ['query' => $q, 'include_adult' => 'false'])['results'] ?? [], 0, 20);
        else $results = ia_search($q)['docs'];
    } catch (RuntimeException $e) { $searchError = $e->getMessage(); }
}
$mine = [];
if ($src === 'archive' && $results) $mine = db()->query('SELECT id, title, year FROM movies ORDER BY id DESC LIMIT 100')->fetchAll();

$pageTitle = 'Search online'; $active = 'search';
require __DIR__ . '/includes/header.php';
?>
<h1>Search online</h1>
<div class="tabs">
  <a href="?<?= h(http_build_query(['src' => 'tmdb', 'q' => $q])) ?>"<?= $src === 'tmdb' ? ' class="on"' : '' ?>>TMDB (metadata)</a>
  <a href="?<?= h(http_build_query(['src' => 'archive', 'q' => $q])) ?>"<?= $src === 'archive' ? ' class="on"' : '' ?>>Internet Archive (free films)</a>
</div>
<p class="hint"><?= $src === 'tmdb'
    ? 'TMDB provides titles, posters, descriptions and trailers only. It does not provide full movies, so imports start as drafts without a playback source.'
    : 'Feature films from the Internet Archive. Each item declares its own license, shown below. That declaration is a starting point: open the item page and confirm it before you publish.' ?></p>
<form method="get" class="filters">
  <input type="hidden" name="src" value="<?= h($src) ?>">
  <input type="search" name="q" placeholder="Search by title" value="<?= h($q) ?>" maxlength="100" aria-label="Search by title" autofocus>
  <button class="btn red sm" type="submit">Search</button>
</form>

<?php if ($searchError): ?><div class="flash error" role="alert"><?= h($searchError) ?></div>
<?php elseif ($q !== '' && !$results): ?><div class="empty">No results for "<?= h($q) ?>". Try a shorter title.</div>
<?php elseif ($results): ?>
<div class="results">
<?php if ($src === 'tmdb'): foreach ($results as $r):
    $pp = safe_img_path($r['poster_path'] ?? null); ?>
  <article class="res">
    <?php if ($pp): ?><img class="mini" src="<?= h(TMDB_IMG . 'w154' . $pp) ?>" alt="" loading="lazy"><?php else: ?><div class="mini none"></div><?php endif; ?>
    <div class="body">
      <h3><?= h($r['title'] ?? 'Untitled') ?> <small><?= h(substr((string)($r['release_date'] ?? ''), 0, 4)) ?></small></h3>
      <p><?= h(mb_substr((string)($r['overview'] ?? ''), 0, 240)) ?></p>
      <form method="post" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="do" value="import_tmdb"><input type="hidden" name="src" value="tmdb"><input type="hidden" name="q" value="<?= h($q) ?>">
        <input type="hidden" name="tmdb_id" value="<?= (int)($r['id'] ?? 0) ?>">
        <button class="btn red sm" type="submit">Import as draft</button>
        <a class="btn line sm" href="https://www.themoviedb.org/movie/<?= (int)($r['id'] ?? 0) ?>" target="_blank" rel="noopener noreferrer">TMDB page</a>
      </form>
    </div>
  </article>
<?php endforeach; else: foreach ($results as $r):
    $ident = ia_text($r['identifier'] ?? ''); $lic = ia_text($r['licenseurl'] ?? '');
    if ($ident === '') continue; ?>
  <article class="res">
    <img class="mini" src="https://archive.org/services/img/<?= h(rawurlencode($ident)) ?>" alt="" loading="lazy">
    <div class="body">
      <h3><?= h(ia_text($r['title'] ?? $ident)) ?> <small><?= h(substr(ia_text($r['year'] ?? ''), 0, 4)) ?></small></h3>
      <p><?php if ($lic !== ''): ?><b>Declared license:</b> <a href="<?= h($lic) ?>" target="_blank" rel="noopener noreferrer"><?= h($lic) ?></a>
        <?php else: ?><b>No license declared.</b> Read the item page carefully before using it.<?php endif; ?></p>
      <form method="post" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="do" value="import_ia"><input type="hidden" name="src" value="archive"><input type="hidden" name="q" value="<?= h($q) ?>">
        <input type="hidden" name="identifier" value="<?= h($ident) ?>">
        <select name="movie_id" aria-label="Where to add this source">
          <option value="0">Create a new draft</option>
<?php foreach ($mine as $mv): ?>
          <option value="<?= (int)$mv['id'] ?>">Attach to: <?= h($mv['title']) ?><?= $mv['year'] ? ' (' . h($mv['year']) . ')' : '' ?></option>
<?php endforeach; ?>
        </select>
        <button class="btn red sm" type="submit">Import</button>
        <a class="btn line sm" href="https://archive.org/details/<?= h(rawurlencode($ident)) ?>" target="_blank" rel="noopener noreferrer">Item page</a>
      </form>
    </div>
  </article>
<?php endforeach; endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

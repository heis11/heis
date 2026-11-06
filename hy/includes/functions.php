<?php
/* Shared helpers for the public API and the admin dashboard. */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/movie-sources.php';

const TMDB_IMG = 'https://image.tmdb.org/t/p/';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function redirect(string $to): void { header('Location: ' . $to); exit; }

/* ---------- one-time messages ---------- */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_take(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/* ---------- rights ---------- */
function rights_options(): array {
    return [
        'unreviewed'       => 'Not reviewed yet',
        'public_domain'    => 'Public domain',
        'creative_commons' => 'Creative Commons or other open license',
        'licensed'         => 'Licensed to me (permission or contract)',
        'owner'            => 'I own the rights',
        'not_cleared'      => 'Not cleared (do not publish)',
    ];
}
function rights_cleared(): array { return ['public_domain', 'creative_commons', 'licensed', 'owner']; }

/* SQL condition for "visible on the public site". Defense in depth: status, rights and source are all checked. */
function published_sql(string $a = 'm'): string {
    $in = "'" . implode("','", rights_cleared()) . "'";
    return "$a.status = 'published' AND $a.rights_status IN ($in) AND EXISTS (SELECT 1 FROM movie_sources s WHERE s.movie_id = $a.id AND s.is_active = 1)";
}

/* ---------- images ---------- */
function movie_poster_url(array $m, string $size = 'w342', string $prefix = ''): ?string {
    if (!empty($m['poster_file'])) return $prefix . 'assets/uploads/posters/' . $m['poster_file'];
    if (!empty($m['poster_path'])) return TMDB_IMG . $size . $m['poster_path'];
    return null;
}
function movie_backdrop_url(array $m): ?string { return !empty($m['backdrop_path']) ? TMDB_IMG . 'w1280' . $m['backdrop_path'] : null; }

/* ---------- movies ---------- */
function movie_find(int $id): ?array {
    $st = db()->prepare('SELECT * FROM movies WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function movie_sources_of(int $id): array {
    $st = db()->prepare('SELECT * FROM movie_sources WHERE movie_id = ? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll();
}

/* Returns a list of reasons a movie cannot be published. Empty list means it can. */
function can_publish(int $id): array {
    $m = movie_find($id);
    if (!$m) return ['Movie not found.'];
    $e = [];
    if (trim($m['title']) === '') $e[] = 'Add a title.';
    if (!in_array($m['rights_status'], rights_cleared(), true)) {
        $e[] = 'Review the rights and mark the movie as public domain, open license, licensed or owned. Current status: ' . (rights_options()[$m['rights_status']] ?? $m['rights_status']) . '.';
    }
    if (mb_strlen(trim((string)$m['rights_notes'])) < 10) $e[] = 'Write the rights basis in the rights notes (the license, contract, or public-domain reasoning, at least 10 characters).';
    $st = db()->prepare('SELECT COUNT(*) FROM movie_sources WHERE movie_id = ? AND is_active = 1');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() < 1) $e[] = 'Add at least one active, legally authorized playback source.';
    return $e;
}

function movie_publish(int $id): array {
    $errs = can_publish($id);
    if ($errs) return $errs;
    db()->prepare("UPDATE movies SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?")->execute([$id]);
    return [];
}
function movie_unpublish(int $id): void { db()->prepare("UPDATE movies SET status = 'draft' WHERE id = ?")->execute([$id]); }

/* If a published movie no longer meets the rules (rights changed, last source removed), take it down. */
function movie_revalidate(int $id): bool {
    $m = movie_find($id);
    if (!$m || $m['status'] !== 'published') return false;
    if (can_publish($id)) { movie_unpublish($id); return true; }
    return false;
}

/* Reads and validates the movie form. Returns [data, errors]. */
function movie_collect_input(array $p, array $files, ?array $old): array {
    $e = []; $d = [];
    $d['title'] = trim((string)($p['title'] ?? ''));
    if ($d['title'] === '' || mb_strlen($d['title']) > 255) $e[] = 'Title is required (up to 255 characters).';

    $y = trim((string)($p['year'] ?? ''));
    $d['year'] = null;
    if ($y !== '') {
        if (!ctype_digit($y) || (int)$y < 1888 || (int)$y > (int)date('Y') + 2) $e[] = 'Year must be between 1888 and next year.';
        else $d['year'] = (int)$y;
    }

    $d['tagline'] = trim((string)($p['tagline'] ?? ''));
    if (mb_strlen($d['tagline']) > 255) $e[] = 'Tagline is limited to 255 characters.';

    $r = trim((string)($p['runtime'] ?? ''));
    $d['runtime'] = null;
    if ($r !== '') {
        if (!ctype_digit($r) || (int)$r < 1 || (int)$r > 1000) $e[] = 'Runtime must be 1 to 1000 minutes.';
        else $d['runtime'] = (int)$r;
    }

    $g = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($p['genres'] ?? ''))), fn($x) => $x !== '')));
    foreach ($g as $x) if (mb_strlen($x) > 30) $e[] = 'Each genre is limited to 30 characters.';
    if (count($g) > 8) $e[] = 'Use at most 8 genres.';
    $d['genres'] = mb_substr(implode(',', $g), 0, 255);

    $rt = trim((string)($p['rating'] ?? ''));
    $d['rating'] = 0.0;
    if ($rt !== '') {
        if (!is_numeric($rt) || (float)$rt < 0 || (float)$rt > 10) $e[] = 'Rating must be between 0 and 10.';
        else $d['rating'] = round((float)$rt, 1);
    }

    $d['overview'] = trim((string)($p['overview'] ?? ''));
    if (mb_strlen($d['overview']) > 5000) $e[] = 'Overview is limited to 5000 characters.';

    $tr = trim((string)($p['trailer'] ?? ''));
    $d['trailer_key'] = null;
    if ($tr !== '') {
        $k = parse_youtube_id($tr);
        if ($k) $d['trailer_key'] = $k; else $e[] = 'Trailer must be a YouTube link or 11-character video id.';
    }

    $rs = (string)($p['rights_status'] ?? 'unreviewed');
    if (!isset(rights_options()[$rs])) { $e[] = 'Choose a rights status.'; $rs = 'unreviewed'; }
    $d['rights_status'] = $rs;
    $d['rights_notes'] = trim((string)($p['rights_notes'] ?? ''));
    if (mb_strlen($d['rights_notes']) > 3000) $e[] = 'Rights notes are limited to 3000 characters.';
    $pf = trim((string)($p['rights_proof_url'] ?? ''));
    if ($pf !== '' && !valid_https_url($pf)) { $e[] = 'Rights proof must be a full https:// link (or leave it empty).'; $pf = ''; }
    $d['rights_proof_url'] = $pf !== '' ? $pf : null;

    $d['tmdb_id'] = $old['tmdb_id'] ?? null;
    $d['poster_path'] = $old['poster_path'] ?? null;
    $d['backdrop_path'] = $old['backdrop_path'] ?? null;
    $d['poster_file'] = $old['poster_file'] ?? null;
    if (!empty($p['remove_poster'])) $d['poster_file'] = null;
    $uploaded = null;
    if (!empty($files['poster']) && ($files['poster']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try { $uploaded = save_image_upload($files['poster']); $d['poster_file'] = $uploaded; }
        catch (RuntimeException $x) { $e[] = $x->getMessage(); }
    }
    if ($e && $uploaded) delete_upload('assets/uploads/posters/' . $uploaded);
    return [$d, $e];
}

const MOVIE_COLS = ['tmdb_id', 'title', 'year', 'tagline', 'overview', 'runtime', 'genres', 'rating', 'poster_path', 'backdrop_path',
    'poster_file', 'trailer_key', 'rights_status', 'rights_notes', 'rights_proof_url'];

function movie_insert(array $d, int $adminId): int {
    $reviewed = $d['rights_status'] !== 'unreviewed';
    $d['rights_reviewed_by'] = $reviewed ? $adminId : null;
    $d['rights_reviewed_at'] = $reviewed ? date('Y-m-d H:i:s') : null;
    $d['created_by'] = $adminId;
    $cols = array_merge(MOVIE_COLS, ['rights_reviewed_by', 'rights_reviewed_at', 'created_by']);
    $sql = 'INSERT INTO movies (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
    $vals = [];
    foreach ($cols as $c) $vals[':' . $c] = $d[$c] ?? null;
    db()->prepare($sql)->execute($vals);
    return (int)db()->lastInsertId();
}

function movie_update(int $id, array $d, int $adminId): void {
    $old = movie_find($id);
    if (!$old) throw new RuntimeException('Movie not found.', 404);
    $changed = $d['rights_status'] !== $old['rights_status'] || $d['rights_notes'] !== (string)$old['rights_notes'] || $d['rights_proof_url'] !== $old['rights_proof_url'];
    if ($d['rights_status'] === 'unreviewed') { $d['rights_reviewed_by'] = null; $d['rights_reviewed_at'] = null; }
    elseif ($changed) { $d['rights_reviewed_by'] = $adminId; $d['rights_reviewed_at'] = date('Y-m-d H:i:s'); }
    else { $d['rights_reviewed_by'] = $old['rights_reviewed_by']; $d['rights_reviewed_at'] = $old['rights_reviewed_at']; }
    $cols = array_merge(MOVIE_COLS, ['rights_reviewed_by', 'rights_reviewed_at']);
    $sql = 'UPDATE movies SET ' . implode(',', array_map(fn($c) => "$c = :$c", $cols)) . ' WHERE id = :id';
    $vals = [':id' => $id];
    foreach ($cols as $c) $vals[':' . $c] = $d[$c] ?? null;
    db()->prepare($sql)->execute($vals);
    if (!empty($old['poster_file']) && $old['poster_file'] !== $d['poster_file']) delete_upload('assets/uploads/posters/' . $old['poster_file']);
}

function movie_delete(int $id): void {
    $m = movie_find($id);
    if (!$m) return;
    foreach (movie_sources_of($id) as $s) if ($s['source_type'] === 'direct_file') delete_upload($s['ref']);
    if (!empty($m['poster_file'])) delete_upload('assets/uploads/posters/' . $m['poster_file']);
    db()->prepare('DELETE FROM movies WHERE id = ?')->execute([$id]);
}

/* ---------- uploads (stored in assets/uploads, which Apache never runs as PHP) ---------- */
function upload_dir(string $sub): string {
    $dir = dirname(__DIR__) . '/assets/uploads/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Cannot create the folder assets/uploads/' . $sub . '. Check folder permissions.', 500);
    return $dir;
}
function upload_error_text(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is bigger than PHP allows. Raise upload_max_filesize and post_max_size in php.ini (see README), or use a link instead.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload. Check the temp folder in php.ini.',
        default => 'The upload failed.',
    };
}
function save_image_upload(array $f): string {
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException(upload_error_text((int)$f['error']));
    if ($f['size'] > 3 * 1024 * 1024) throw new RuntimeException('Poster images can be at most 3 MB.');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Invalid upload.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new RuntimeException('Posters must be JPG, PNG or WebP images.');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], upload_dir('posters') . '/' . $name)) throw new RuntimeException('Could not save the poster.');
    return $name;
}
/* Returns the path relative to the project root, e.g. assets/uploads/videos/abc.mp4 */
function save_video_upload(array $f): string {
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException(upload_error_text((int)$f['error']));
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Invalid upload.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv', 'application/ogg' => 'ogv'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Video files must be MP4, WebM or OGV.');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], upload_dir('videos') . '/' . $name)) throw new RuntimeException('Could not save the video.');
    return 'assets/uploads/videos/' . $name;
}
function delete_upload(string $rel): void {
    if (!preg_match('~^assets/uploads/(posters|videos)/[A-Za-z0-9._-]+$~', $rel)) return;
    $path = dirname(__DIR__) . '/' . $rel;
    if (is_file($path)) @unlink($path);
}

/* ---------- sources ---------- */
function source_insert(int $movieId, string $type, string $ref, string $label, string $licName, string $licUrl): int {
    db()->prepare('INSERT INTO movie_sources (movie_id, source_type, ref, label, license_name, license_url, is_active) VALUES (?,?,?,?,?,?,1)')
        ->execute([$movieId, $type, $ref, mb_substr($label, 0, 120), mb_substr($licName, 0, 160), valid_https_url($licUrl) || preg_match('~^http://~i', $licUrl) ? mb_substr($licUrl, 0, 500) : '']);
    return (int)db()->lastInsertId();
}

/* ---------- public catalog (used by api.php) ---------- */
function catalog_row(array $m): array {
    return [
        'id' => (int)$m['id'], 'title' => $m['title'], 'year' => $m['year'] ? (string)$m['year'] : '',
        'poster' => movie_poster_url($m), 'backdrop' => movie_backdrop_url($m),
        'rating' => round((float)$m['rating'], 1), 'overview' => (string)$m['overview'],
        'genres' => $m['genres'] !== '' ? explode(',', $m['genres']) : [],
    ];
}

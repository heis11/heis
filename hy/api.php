<?php
// Small proxy so the TMDB key stays on the server and never reaches the browser.
// Also serves the movies that the administrator published (catalog actions).
// Never let PHP warnings/notices leak into the JSON reply (they would break the page).
ini_set('display_errors', '0');
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail(string $msg, int $code = 400): void {
    http_response_code($code >= 400 && $code < 600 ? $code : 500);
    echo json_encode(['error' => $msg]);
    exit;
}

$GENRES = [28=>'Action',12=>'Adventure',35=>'Comedy',18=>'Drama',27=>'Horror',10749=>'Romance',878=>'Sci-Fi',
    16=>'Animation',14=>'Fantasy',53=>'Thriller',99=>'Documentary',80=>'Crime',10751=>'Family',9648=>'Mystery',
    36=>'History',10752=>'War',37=>'Western',10402=>'Music',10770=>'TV Movie'];

// TMDB calls go through includes/movie-sources.php; errors become JSON replies here.
function tmdb(string $path, array $q = []): array {
    try { return tmdb_request($path, $q); }
    catch (RuntimeException $e) { fail($e->getMessage(), (int)$e->getCode() ?: 502); }
}

function norm(array $m): array {
    global $GENRES;
    $names = [];
    foreach ($m['genre_ids'] ?? [] as $g) if (isset($GENRES[$g])) $names[] = $GENRES[$g];
    return [
        'id' => (int)$m['id'], 'title' => $m['title'] ?? 'Untitled', 'year' => substr($m['release_date'] ?? '', 0, 4),
        'poster' => $m['poster_path'] ?? null, 'backdrop' => $m['backdrop_path'] ?? null,
        'rating' => round((float)($m['vote_average'] ?? 0), 1), 'overview' => $m['overview'] ?? '', 'genres' => $names,
    ];
}
function listOut(array $j): void {
    echo json_encode(['page' => $j['page'] ?? 1, 'total_pages' => min((int)($j['total_pages'] ?? 1), 500),
        'results' => array_map('norm', $j['results'] ?? [])]);
}

// Catalog: movies published in the admin dashboard. Needs MySQL, never needs a TMDB key.
function catalogDb(callable $fn): void {
    try { $fn(); }
    catch (PDOException $e) { // must come first: PDOException extends RuntimeException
        error_log('HEISVERSE catalog error: ' . $e->getMessage());
        fail('The catalog tables are missing. Import database.sql in phpMyAdmin (see README).', 503);
    }
    catch (RuntimeException $e) { fail($e->getMessage(), (int)$e->getCode() ?: 503); }
}

$action = $_GET['action'] ?? '';
$page = max(1, min(500, (int)($_GET['page'] ?? 1)));

switch ($action) {
    case 'trending':
        listOut(tmdb('/trending/movie/week', ['page' => $page]));
        break;
    case 'discover':
        $q = ['sort_by' => 'popularity.desc', 'include_adult' => 'false', 'vote_count.gte' => 40, 'page' => $page];
        if (!empty($_GET['africa'])) {
            $q['with_origin_country'] = 'NG|GH|ZA|KE|EG|RW|TZ|UG|MA|SN|CI|ET|CM';
            $q['vote_count.gte'] = 5;
        }
        if (isset($_GET['genre']) && $_GET['genre'] !== '') {
            $g = (int)$_GET['genre'];
            if (!isset($GENRES[$g])) fail('Unknown genre.');
            $q['with_genres'] = $g;
        }
        listOut(tmdb('/discover/movie', $q));
        break;
    case 'search':
        $term = trim((string)($_GET['q'] ?? ''));
        if ($term === '' || mb_strlen($term) > 100) fail('Type 1 to 100 characters to search.');
        listOut(tmdb('/search/movie', ['query' => $term, 'include_adult' => 'false', 'page' => $page]));
        break;
    case 'detail':
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) fail('Invalid movie id.');
        $region = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_GET['region'] ?? DEFAULT_REGION)));
        if (strlen($region) !== 2) $region = DEFAULT_REGION;
        $d = tmdb('/movie/' . $id, ['append_to_response' => 'videos,watch/providers']);
        $out = norm($d + ['genre_ids' => []]);
        $out['genres'] = array_map(fn($g) => $g['name'], $d['genres'] ?? []);
        $out['runtime'] = (int)($d['runtime'] ?? 0);
        $out['tagline'] = $d['tagline'] ?? '';
        $out['tmdbUrl'] = 'https://www.themoviedb.org/movie/' . $id;
        $trailer = null;
        foreach ($d['videos']['results'] ?? [] as $v) {
            if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer' && preg_match('/^[\w-]{6,20}$/', $v['key'] ?? '')) {
                if ($trailer === null || !empty($v['official'])) $trailer = $v['key'];
            }
        }
        $out['trailer'] = $trailer;
        $p = $d['watch/providers']['results'][$region] ?? null;
        $names = fn($k) => array_map(fn($x) => $x['provider_name'], $p[$k] ?? []);
        $out['providers'] = $p ? ['region' => $region, 'link' => $p['link'] ?? null, 'free' => $names('free'),
            'ads' => $names('ads'), 'flatrate' => $names('flatrate'), 'rent' => $names('rent'), 'buy' => $names('buy')] : null;
        $out['region'] = $region;
        echo json_encode($out);
        break;
    case 'catalog':
        catalogDb(function () use ($page) {
            $per = 24;
            $where = [published_sql('m')]; $args = [];
            $term = trim((string)($_GET['q'] ?? ''));
            if ($term !== '') {
                if (mb_strlen($term) > 100) fail('Type 1 to 100 characters to search.');
                $where[] = 'm.title LIKE ?'; $args[] = '%' . addcslashes($term, '%_\\') . '%';
            }
            $genre = trim((string)($_GET['genre'] ?? ''));
            if ($genre !== '') { $where[] = 'FIND_IN_SET(?, m.genres) > 0'; $args[] = mb_substr($genre, 0, 30); }
            $w = implode(' AND ', $where);
            $st = db()->prepare("SELECT COUNT(*) FROM movies m WHERE $w");
            $st->execute($args);
            $total = (int)$st->fetchColumn();
            $off = ($page - 1) * $per;
            $st = db()->prepare("SELECT m.* FROM movies m WHERE $w ORDER BY m.published_at DESC, m.id DESC LIMIT $per OFFSET $off");
            $st->execute($args);
            $res = ['page' => $page, 'total_pages' => max(1, (int)ceil($total / $per)), 'results' => array_map('catalog_row', $st->fetchAll())];
            if ($page === 1) {
                $gs = [];
                foreach (db()->query('SELECT m.genres FROM movies m WHERE ' . published_sql('m')) as $r) foreach (explode(',', $r['genres']) as $g) if ($g !== '') $gs[$g] = true;
                $res['genres'] = array_keys($gs); sort($res['genres']);
            }
            echo json_encode($res);
        });
        break;
    case 'catalog_detail':
        catalogDb(function () {
            $id = (int)($_GET['id'] ?? 0);
            if ($id < 1) fail('Invalid movie id.');
            $st = db()->prepare('SELECT m.* FROM movies m WHERE m.id = ? AND ' . published_sql('m'));
            $st->execute([$id]);
            $m = $st->fetch();
            if (!$m) fail('This movie is not available.', 404);
            $out = catalog_row($m);
            $out['runtime'] = (int)$m['runtime'];
            $out['tagline'] = $m['tagline'];
            $out['trailer'] = $m['trailer_key'];
            $out['rights'] = rights_options()[$m['rights_status']] ?? '';
            $out['sources'] = array_map('source_playback', array_values(array_filter(movie_sources_of($id), fn($s) => (int)$s['is_active'] === 1)));
            echo json_encode($out);
        });
        break;
    case '':
        echo json_encode(['status' => 'ok', 'tmdb_key' => TMDB_API_KEY !== '']);
        break;
    default:
        fail('Unknown action.');
}

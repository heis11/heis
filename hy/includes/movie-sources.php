<?php
/* Online catalogs and playback sources.
   - TMDB supplies metadata only (titles, posters, trailers). It never supplies full movies.
   - Internet Archive search is used by the admin to find films, and shows each item's declared license.
   - A "playback source" is a place a full movie may legally be watched. Admins review rights first. */
require_once dirname(__DIR__) . '/config.php';

/* ---------- HTTP ---------- */
function http_get(string $url, array $headers = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Enable the curl extension in php.ini (extension=curl), then restart Apache.', 500);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => TMDB_VERIFY_SSL, CURLOPT_SSL_VERIFYHOST => TMDB_VERIFY_SSL ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $err];
}

/* ---------- TMDB (metadata only) ---------- */
function tmdb_request(string $path, array $q = []): array {
    if (TMDB_API_KEY === '') throw new RuntimeException('TMDB API key is not set. Open config.php and paste your key.', 503);
    $q['language'] = TMDB_LANGUAGE;
    $headers = ['Accept: application/json'];
    if (strlen(TMDB_API_KEY) > 40) $headers[] = 'Authorization: Bearer ' . TMDB_API_KEY;
    else $q['api_key'] = TMDB_API_KEY;
    [$code, $body, $err] = http_get('https://api.themoviedb.org/3' . $path . '?' . http_build_query($q), $headers);
    if ($body === false) throw new RuntimeException('Could not reach TMDB (' . $err . '). Check your internet connection.', 502);
    $j = json_decode($body, true);
    if ($code === 401) throw new RuntimeException('TMDB rejected the API key. Check config.php.', 401);
    if ($code >= 400 || !is_array($j)) throw new RuntimeException('TMDB request failed (HTTP ' . $code . ').', 502);
    return $j;
}

/* ---------- Internet Archive ---------- */
function ia_search(string $term, int $page = 1): array {
    $clean = trim((string)preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $term));
    $q = 'collection:feature_films AND mediatype:movies' . ($clean !== '' ? ' AND title:(' . $clean . ')' : '');
    $qs = http_build_query(['q' => $q, 'rows' => 20, 'page' => max(1, $page), 'output' => 'json'])
        . '&fl%5B%5D=identifier&fl%5B%5D=title&fl%5B%5D=year&fl%5B%5D=description&fl%5B%5D=licenseurl&sort%5B%5D=downloads+desc';
    [$code, $body, $err] = http_get('https://archive.org/advancedsearch.php?' . $qs, ['Accept: application/json']);
    if ($body === false) throw new RuntimeException('Could not reach the Internet Archive (' . $err . ').', 502);
    $j = json_decode($body, true);
    if ($code >= 400 || !isset($j['response']['docs'])) throw new RuntimeException('The Internet Archive did not answer correctly. Try again later.', 502);
    return ['docs' => $j['response']['docs'], 'total' => (int)($j['response']['numFound'] ?? 0)];
}

function ia_metadata(string $identifier): array {
    [$code, $body, $err] = http_get('https://archive.org/metadata/' . rawurlencode($identifier), ['Accept: application/json']);
    if ($body === false) throw new RuntimeException('Could not reach the Internet Archive (' . $err . ').', 502);
    $j = json_decode($body, true);
    if ($code >= 400 || !is_array($j) || empty($j['metadata'])) throw new RuntimeException('That Internet Archive item was not found.', 404);
    return $j['metadata'];
}

/* ---------- playback sources ---------- */
function source_types(): array {
    return [
        'internet_archive' => 'Internet Archive',
        'youtube'          => 'YouTube (official or authorized channel)',
        'official_page'    => 'Official free page (opens a link)',
        'direct_file'      => 'Direct video file (you host it or the rights holder does)',
    ];
}

function valid_https_url(string $u): bool {
    return strlen($u) <= 500 && preg_match('~^https://[^\s/]+~i', $u) === 1 && filter_var($u, FILTER_VALIDATE_URL) !== false;
}

function parse_youtube_id(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^[\w-]{11}$/', $s)) return $s;
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})~i', $s, $m)) return $m[1];
    return null;
}

/* Returns [clean reference, error message or null]. */
function normalize_source(string $type, string $input): array {
    $input = trim($input);
    switch ($type) {
        case 'internet_archive':
            if (preg_match('~archive\.org/(?:details|embed)/([A-Za-z0-9][A-Za-z0-9._-]{0,99})~i', $input, $m)) $input = $m[1];
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $input) === 1
                ? [$input, null] : [null, 'Enter an Internet Archive identifier or an archive.org/details/... link.'];
        case 'youtube':
            $id = parse_youtube_id($input);
            return $id ? [$id, null] : [null, 'Enter a YouTube video link or the 11-character video id.'];
        case 'official_page':
            return valid_https_url($input) ? [$input, null] : [null, 'Enter a full https:// link to the official page.'];
        case 'direct_file':
            if (valid_https_url($input) && preg_match('/\.(mp4|webm|ogv)$/i', (string)parse_url($input, PHP_URL_PATH)) === 1) return [$input, null];
            return [null, 'Enter an https:// link ending in .mp4, .webm or .ogv, or upload a file.'];
    }
    return [null, 'Unknown source type.'];
}

/* Public-safe description of how to play a source. */
function source_playback(array $s): array {
    $type = $s['source_type'];
    $label = $s['label'] !== '' ? $s['label'] : (source_types()[$type] ?? 'Source');
    $out = ['id' => (int)$s['id'], 'type' => $type, 'label' => $label, 'license' => $s['license_name'], 'licenseUrl' => $s['license_url']];
    switch ($type) {
        case 'internet_archive':
            return $out + ['mode' => 'iframe', 'embed' => 'https://archive.org/embed/' . rawurlencode($s['ref']), 'url' => 'https://archive.org/details/' . rawurlencode($s['ref'])];
        case 'youtube':
            return $out + ['mode' => 'iframe', 'embed' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($s['ref']) . '?rel=0', 'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($s['ref'])];
        case 'direct_file':
            return $out + ['mode' => 'video', 'file' => $s['ref']];
        default:
            return $out + ['mode' => 'link', 'url' => $s['ref']];
    }
}

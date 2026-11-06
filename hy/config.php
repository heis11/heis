<?php
// HEISVERSE settings.
// IMPORTANT: this file must start with "<?php" on the very first line (no blank line or space
// before it), otherwise PHP sends output early and every header() call fails.

// TMDB API key. An environment variable (for example on Render) overrides the built-in key.
define('TMDB_API_KEY', getenv('TMDB_API_KEY') ?: '5bc2f1bff7fc9b7651fd5dca0fde4e82');

// Country used for "where to watch" results.
const DEFAULT_REGION = 'RW';

const TMDB_LANGUAGE = 'en-US';

// Keep true on a real server. If XAMPP on Windows shows "SSL certificate problem",
// set it to false (local testing only) or set curl.cainfo in php.ini.
const TMDB_VERIFY_SSL = true;

// MySQL configuration. Defaults suit XAMPP; environment variables override them
// (use them on Docker / Render / any hosted MySQL).
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'heisverse');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

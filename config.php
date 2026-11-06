
<?php
// HEISVERSE settings.

// Read TMDB API key securely from Render environment variables.
define('TMDB_API_KEY', getenv('TMDB_API_KEY') ?: '');

// Country used for "where to watch" results.
const DEFAULT_REGION = 'RW';

const TMDB_LANGUAGE = 'en-US';

const TMDB_VERIFY_SSL = true;

// MySQL configuration (for local development).
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'heisverse';
const DB_USER = 'root';
const DB_PASS = '';


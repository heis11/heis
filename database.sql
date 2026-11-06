-- HEISVERSE database setup.
-- Import this file once in phpMyAdmin (Import tab, with no database selected).
-- It is safe to import again: it only creates what is missing and never deletes data.

CREATE DATABASE IF NOT EXISTS `heisverse` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `heisverse`;

-- Administrators. No default account exists: the first one is created
-- from http://localhost/heisverse/admin/ the first time you open it.
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(40)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed and successful sign-in attempts, used to slow down password guessing.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45) NOT NULL,
  `attempted_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_ip_time` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The movie catalog. A movie is public only when status = 'published',
-- its rights are cleared, and it has at least one active playback source.
CREATE TABLE IF NOT EXISTS `movies` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tmdb_id`            INT UNSIGNED NULL,
  `title`              VARCHAR(255) NOT NULL,
  `year`               SMALLINT UNSIGNED NULL,
  `tagline`            VARCHAR(255) NOT NULL DEFAULT '',
  `overview`           TEXT NULL,
  `runtime`            SMALLINT UNSIGNED NULL,
  `genres`             VARCHAR(255) NOT NULL DEFAULT '',          -- comma separated, no spaces: Action,Drama
  `rating`             DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  `poster_path`        VARCHAR(255) NULL,                          -- TMDB image path such as /abc123.jpg
  `backdrop_path`      VARCHAR(255) NULL,
  `poster_file`        VARCHAR(255) NULL,                          -- file in assets/uploads/posters/
  `trailer_key`        VARCHAR(20)  NULL,                          -- YouTube video id
  `status`             ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `rights_status`      ENUM('unreviewed','public_domain','creative_commons','licensed','owner','not_cleared') NOT NULL DEFAULT 'unreviewed',
  `rights_notes`       TEXT NULL,
  `rights_proof_url`   VARCHAR(500) NULL,
  `rights_reviewed_by` INT UNSIGNED NULL,
  `rights_reviewed_at` DATETIME NULL,
  `published_at`       DATETIME NULL,
  `created_by`         INT UNSIGNED NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_movie_tmdb` (`tmdb_id`),
  KEY `idx_movie_status` (`status`, `published_at`),
  KEY `idx_movie_title` (`title`),
  CONSTRAINT `fk_movie_reviewer` FOREIGN KEY (`rights_reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_movie_creator`  FOREIGN KEY (`created_by`)         REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legal playback sources for a movie (Internet Archive, an official YouTube upload,
-- an official free page, or a video file you are allowed to host).
CREATE TABLE IF NOT EXISTS `movie_sources` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `movie_id`     INT UNSIGNED NOT NULL,
  `source_type`  ENUM('internet_archive','youtube','official_page','direct_file') NOT NULL,
  `ref`          VARCHAR(500) NOT NULL,                            -- identifier, video id, URL, or uploaded file path
  `label`        VARCHAR(120) NOT NULL DEFAULT '',
  `license_name` VARCHAR(160) NOT NULL DEFAULT '',
  `license_url`  VARCHAR(500) NOT NULL DEFAULT '',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_movie` (`movie_id`, `is_active`),
  CONSTRAINT `fk_source_movie` FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

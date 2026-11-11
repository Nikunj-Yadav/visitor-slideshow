-- ============================================================================
-- Memory Wall - MySQL schema (import this file in phpMyAdmin > Import)
-- Safe to import more than once: existing tables are kept.
-- ============================================================================
CREATE DATABASE IF NOT EXISTS `visitor_slideshow`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `visitor_slideshow`;

-- One row per visitor. Only a SHA-256 hash of the visitor code is stored.
CREATE TABLE IF NOT EXISTS `visitors` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(40)  NOT NULL,
  `code_hash`  CHAR(64)     NOT NULL,
  `consent_at` DATETIME     NULL COMMENT 'NULL = no consent to display photos',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visitors_code_hash` (`code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photo metadata only. The image files live in the uploads/ folder, never in MySQL.
CREATE TABLE IF NOT EXISTS `photos` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visitor_id` INT UNSIGNED    NOT NULL,
  `filename`   VARCHAR(40)     NOT NULL COMMENT 'random server-made name, e.g. 3f9a...c1.jpg',
  `sha256`     CHAR(64)        NOT NULL COMMENT 'hash of the original upload, blocks duplicates',
  `bytes`      INT UNSIGNED    NOT NULL,
  `status`     ENUM('approved','hidden') NOT NULL DEFAULT 'approved',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_photos_filename` (`filename`),
  UNIQUE KEY `uq_photos_visitor_hash` (`visitor_id`, `sha256`),
  KEY `idx_photos_status_id` (`status`, `id`),
  CONSTRAINT `fk_photos_visitor` FOREIGN KEY (`visitor_id`)
    REFERENCES `visitors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Display settings. 'data_version' goes up whenever photos or settings change;
-- the slideshow page polls it to know when to reload.
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(50)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('duration_seconds', '8'),
  ('show_names',       'false'),
  ('data_version',     '1');

-- Dashboard activity feed (the app keeps only the latest 200 rows).
CREATE TABLE IF NOT EXISTS `activity` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message`    VARCHAR(255) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The single admin account. The password is created on first visit to /admin
-- (setup page) and stored only as a password_hash() hash. id is always 1.
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            TINYINT UNSIGNED NOT NULL,
  `password_hash` VARCHAR(255)     NOT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counts requests per IP address to slow down guessing and flooding.
CREATE TABLE IF NOT EXISTS `rate_limit_hits` (
  `id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket` VARCHAR(20)     NOT NULL,
  `ip`     VARCHAR(45)     NOT NULL,
  `hit_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rate_lookup` (`bucket`, `ip`, `hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional (recommended outside a classroom): do not use MySQL "root" in production.
-- CREATE USER 'memorywall'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON visitor_slideshow.* TO 'memorywall'@'localhost';

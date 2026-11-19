-- Subieto MySQL Optimization Script (Safe & Idempotent for Existing Databases)
SET NAMES utf8mb4;

-- 1. Clean zero/invalid season & episode values
UPDATE `sh_subtitles` SET `season` = NULL WHERE `season` IS NOT NULL AND `season` <= 0;
UPDATE `sh_subtitles` SET `episode` = NULL WHERE `episode` IS NOT NULL AND `episode` <= 0;

-- 2. Add composite indexes for fast season & language lookups (ignore error if already exists)
ALTER TABLE `sh_subtitles`
    ADD INDEX `idx_imdb_active_season` (`imdb_id`, `is_active`, `season`),
    ADD INDEX `idx_imdb_active_lang` (`imdb_id`, `is_active`, `language_code`);

ALTER TABLE `sh_indexed_media`
    ADD INDEX `idx_type_year` (`media_type`, `year`);

ALTER TABLE `sh_download_stats`
    ADD INDEX `idx_dl_lang_provider` (`language_code`, `provider`);

-- 3. Purge expired cache & rate limit windows
DELETE FROM `sh_api_cache` WHERE `expires_at` < UNIX_TIMESTAMP();

-- 4. Defragment tables & update index statistics
OPTIMIZE TABLE
    `sh_subtitles`,
    `sh_media_sync`,
    `sh_api_cache`,
    `sh_indexed_media`,
    `sh_download_stats`,
    `sh_daily_metrics`,
    `sh_settings`;

-- 5. Ensure poster_url column & subtitle votes table exist
ALTER TABLE `sh_indexed_media` ADD COLUMN `poster_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `year`;

CREATE TABLE IF NOT EXISTS `sh_subtitle_votes` (
    `url_hash` CHAR(64) NOT NULL,
    `imdb_id` VARCHAR(20) NOT NULL DEFAULT '',
    `upvotes` INT UNSIGNED NOT NULL DEFAULT 0,
    `downvotes` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`url_hash`),
    INDEX `idx_imdb` (`imdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

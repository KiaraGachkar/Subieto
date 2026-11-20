<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\SubtitleItem;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

/**
 * Permanent MySQL subtitle archive repository (Local-Archive-Only mode).
 * Serves strictly verified self-hosted `localzip://` subtitles uploaded by `subieto_auto_sync.py`.
 */
final class SubtitleRepository
{
    private static bool $tablesReady = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_',
        private readonly string $storageDir = ''
    ) {
        $this->ensureTables();
    }

    public function ensureTables(): void
    {
        if (self::$tablesReady) {
            return;
        }
        self::$tablesReady = true;

        $markerFile = $this->storageDir !== '' ? rtrim($this->storageDir, '/') . '/.sub_tables_v3' : '';
        if ($markerFile !== '' && is_file($markerFile)) {
            return;
        }

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->prefix}subtitles` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `url_hash` CHAR(64) NOT NULL,
                    `imdb_id` VARCHAR(20) NOT NULL,
                    `provider` VARCHAR(40) NOT NULL DEFAULT '',
                    `language_code` VARCHAR(10) NOT NULL DEFAULT 'en',
                    `language_label` VARCHAR(60) NOT NULL DEFAULT 'English',
                    `release_name` VARCHAR(255) NOT NULL DEFAULT '',
                    `translator` VARCHAR(120) NOT NULL DEFAULT '',
                    `quality_tag` VARCHAR(30) NOT NULL DEFAULT 'All',
                    `download_url` TEXT NOT NULL,
                    `file_name` VARCHAR(255) NOT NULL DEFAULT 'subtitle.srt',
                    `season` SMALLINT UNSIGNED NULL DEFAULT NULL,
                    `episode` SMALLINT UNSIGNED NULL DEFAULT NULL,
                    `hearing_impaired` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_url_hash` (`url_hash`),
                    INDEX `idx_imdb_active` (`imdb_id`, `is_active`),
                    INDEX `idx_imdb_season` (`imdb_id`, `season`, `is_active`),
                    INDEX `idx_imdb_lang` (`imdb_id`, `language_code`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->prefix}media_sync` (
                    `imdb_id` VARCHAR(20) NOT NULL PRIMARY KEY,
                    `sub_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `fa_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `is_complete` TINYINT(1) NOT NULL DEFAULT 0,
                    `last_synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_sync_time` (`is_complete`, `last_synced_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->prefix}subtitle_votes` (
                    `url_hash` CHAR(64) NOT NULL PRIMARY KEY,
                    `imdb_id` VARCHAR(20) NOT NULL,
                    `upvotes` INT UNSIGNED NOT NULL DEFAULT 0,
                    `downvotes` INT UNSIGNED NOT NULL DEFAULT 0,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_votes_imdb` (`imdb_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            );

            if ($markerFile !== '' && is_dir(dirname($markerFile))) {
                @file_put_contents($markerFile, '1');
            }
        } catch (Throwable) {
            // Non-fatal
        }
    }

    /**
     * Fetches active self-hosted (`localzip://`) subtitles for an IMDb title.
     *
     * @return SubtitleItem[]
     */
    public function getByImdbId(string $imdbId, ?int $season = null): array
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return [];
        }

        try {
            if ($season !== null && $season > 0) {
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM `{$this->prefix}subtitles`
                     WHERE `imdb_id` = :imdb
                       AND `is_active` = 1
                       AND `download_url` LIKE 'localzip://%'
                       AND (`season` = :s OR `season` IS NULL)
                     ORDER BY `id` ASC LIMIT 1000"
                );
                $stmt->execute([':imdb' => $cleanImdb, ':s' => $season]);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM `{$this->prefix}subtitles`
                     WHERE `imdb_id` = :imdb
                       AND `is_active` = 1
                       AND `download_url` LIKE 'localzip://%'
                     ORDER BY `id` ASC LIMIT 1000"
                );
                $stmt->execute([':imdb' => $cleanImdb]);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $items = [];
            foreach ($rows as $r) {
                $items[] = new SubtitleItem(
                    id: 'db_' . (string) $r['id'],
                    provider: (string) $r['provider'],
                    languageCode: (string) $r['language_code'],
                    languageLabel: (string) $r['language_label'],
                    releaseName: (string) $r['release_name'],
                    translator: (string) $r['translator'],
                    qualityTag: (string) $r['quality_tag'],
                    downloadUrl: (string) $r['download_url'],
                    fileName: (string) $r['file_name'],
                    season: ($r['season'] !== null && (int) $r['season'] > 0) ? (int) $r['season'] : null,
                    episode: ($r['episode'] !== null && (int) $r['episode'] > 0) ? (int) $r['episode'] : null,
                    hearingImpaired: ((int) $r['hearing_impaired']) === 1
                );
            }
            return $items;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns map of season_number => active local subtitle count for a TV series.
     *
     * @return array<int, int>
     */
    public function getSeasonCounts(string $imdbId): array
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `season`, COUNT(*) AS `cnt`
                 FROM `{$this->prefix}subtitles`
                 WHERE `imdb_id` = :imdb
                   AND `is_active` = 1
                   AND `download_url` LIKE 'localzip://%'
                   AND `season` IS NOT NULL
                   AND `season` > 0
                 GROUP BY `season`
                 ORDER BY `season` ASC"
            );
            $stmt->execute([':imdb' => $cleanImdb]);
            $map = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $map[(int) $row['season']] = (int) $row['cnt'];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param SubtitleItem[] $items
     */
    public function saveItems(string $imdbId, array $items, bool $markComplete = false): int
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return 0;
        }

        $saved = 0;
        try {
            if ($items !== []) {
                $validItems = [];
                $seenHashes = [];
                foreach ($items as $item) {
                    if ($item->downloadUrl === '' || !str_starts_with($item->downloadUrl, 'localzip://')) {
                        continue;
                    }
                    $urlHash = hash('sha256', $cleanImdb . '|' . $item->downloadUrl);
                    if (isset($seenHashes[$urlHash])) {
                        continue;
                    }
                    $seenHashes[$urlHash] = true;
                    $validItems[] = [$urlHash, $item];
                }

                $updateSql = " ON DUPLICATE KEY UPDATE
                    `release_name` = IF(VALUES(`release_name`) != '', VALUES(`release_name`), `release_name`),
                    `translator` = IF(VALUES(`translator`) != '', VALUES(`translator`), `translator`),
                    `quality_tag` = IF(VALUES(`quality_tag`) != 'All', VALUES(`quality_tag`), `quality_tag`),
                    `season` = COALESCE(VALUES(`season`), `season`),
                    `episode` = COALESCE(VALUES(`episode`), `episode`),
                    `is_active` = 1";

                $baseSql = "INSERT INTO `{$this->prefix}subtitles`
                    (`url_hash`, `imdb_id`, `provider`, `language_code`, `language_label`, `release_name`, `translator`, `quality_tag`, `download_url`, `file_name`, `season`, `episode`, `hearing_impaired`, `is_active`)
                 VALUES ";

                foreach (array_chunk($validItems, 100) as $chunk) {
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as [$uHash, $item]) {
                        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
                        $params[] = $uHash;
                        $params[] = substr($cleanImdb, 0, 20);
                        $params[] = substr($item->provider, 0, 40);
                        $params[] = substr($item->languageCode, 0, 10);
                        $params[] = substr($item->languageLabel, 0, 60);
                        $params[] = substr($item->releaseName, 0, 250);
                        $params[] = substr($item->translator, 0, 100);
                        $params[] = substr($item->qualityTag, 0, 30);
                        $params[] = $item->downloadUrl;
                        $params[] = substr($item->fileName, 0, 250);
                        $params[] = ($item->season !== null && $item->season > 0) ? $item->season : null;
                        $params[] = ($item->episode !== null && $item->episode > 0) ? $item->episode : null;
                        $params[] = $item->hearingImpaired ? 1 : 0;
                    }
                    $stmt = $this->pdo->prepare($baseSql . implode(', ', $placeholders) . $updateSql);
                    $stmt->execute($params);
                    $saved += count($chunk);
                }
            }

            $cntStmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS `tot`,
                        COALESCE(SUM(CASE WHEN `language_code` = 'fa' THEN 1 ELSE 0 END), 0) AS `fa`
                 FROM `{$this->prefix}subtitles`
                 WHERE `imdb_id` = :imdb AND `is_active` = 1 AND `download_url` LIKE 'localzip://%'"
            );
            $cntStmt->execute([':imdb' => $cleanImdb]);
            $cntRow = $cntStmt->fetch(PDO::FETCH_ASSOC) ?: ['tot' => 0, 'fa' => 0];

            $syncStmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}media_sync` (`imdb_id`, `sub_count`, `fa_count`, `is_complete`)
                 VALUES (:imdb, :tot, :fa, :comp)
                 ON DUPLICATE KEY UPDATE
                    `sub_count` = VALUES(`sub_count`),
                    `fa_count` = VALUES(`fa_count`),
                    `is_complete` = GREATEST(`is_complete`, VALUES(`is_complete`))"
            );
            $syncStmt->execute([
                ':imdb' => substr($cleanImdb, 0, 20),
                ':tot'  => (int) $cntRow['tot'],
                ':fa'   => (int) $cntRow['fa'],
                ':comp' => $markComplete ? 1 : 0,
            ]);
        } catch (Throwable) {
            // Never block requests
        }

        return $saved;
    }

    /**
     * Removes legacy external (non-localzip) subtitle rows from MySQL.
     */
    public function purgeExternalRows(): int
    {
        try {
            $deleted = (int) $this->pdo->exec(
                "DELETE FROM `{$this->prefix}subtitles` WHERE `download_url` NOT LIKE 'localzip://%'"
            );
            $this->pdo->exec(
                "DELETE FROM `{$this->prefix}media_sync`
                 WHERE `imdb_id` NOT IN (
                    SELECT DISTINCT `imdb_id` FROM `{$this->prefix}subtitles` WHERE `download_url` LIKE 'localzip://%'
                 )"
            );
            return $deleted;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{total: int, persian: int, titles: int, legacy: int}
     */
    public function getArchiveStats(): array
    {
        try {
            $row = $this->pdo->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN `download_url` LIKE 'localzip://%' THEN 1 ELSE 0 END), 0) AS `total`,
                    COALESCE(SUM(CASE WHEN `download_url` LIKE 'localzip://%' AND `language_code` = 'fa' THEN 1 ELSE 0 END), 0) AS `persian`,
                    COUNT(DISTINCT CASE WHEN `download_url` LIKE 'localzip://%' THEN `imdb_id` ELSE NULL END) AS `titles`,
                    COALESCE(SUM(CASE WHEN `download_url` NOT LIKE 'localzip://%' THEN 1 ELSE 0 END), 0) AS `legacy`
                 FROM `{$this->prefix}subtitles`
                 WHERE `is_active` = 1"
            )->fetch(PDO::FETCH_ASSOC);

            return [
                'total'   => (int) ($row['total'] ?? 0),
                'persian' => (int) ($row['persian'] ?? 0),
                'titles'  => (int) ($row['titles'] ?? 0),
                'legacy'  => (int) ($row['legacy'] ?? 0),
            ];
        } catch (Throwable) {
            return ['total' => 0, 'persian' => 0, 'titles' => 0, 'legacy' => 0];
        }
    }

    /**
     * Returns top user-requested titles from `sh_missing_subtitles` for `subieto_auto_sync.py`.
     *
     * @return array<int, array{imdb_id: string, title: string, media_type: string, year: string, requests_count: int}>
     */
    public function getRequestedQueue(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `imdb_id`, `title`, `media_type`, `year`, `requests_count`
                 FROM `{$this->prefix}missing_subtitles`
                 ORDER BY `requests_count` DESC, `last_requested_at` DESC
                 LIMIT {$limit}"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'imdb_id'        => strtolower(trim((string) $r['imdb_id'])),
                    'title'          => (string) $r['title'],
                    'media_type'     => ($r['media_type'] === 'tv') ? 'tv' : 'movie',
                    'year'           => (string) $r['year'],
                    'requests_count' => (int) $r['requests_count'],
                ];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }


    /**
     * Returns all IMDb IDs that currently have active `localzip://` rows in MySQL
     * AND a valid `.zip` file on disk in `storage/subtitles/{imdb_id}.zip`.
     *
     * @return string[]
     */
    public function getVerifiedPresentImdbIds(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT `imdb_id`
                 FROM `{$this->prefix}subtitles`
                 WHERE `is_active` = 1 AND `download_url` LIKE 'localzip://%'"
            );
            $dbIds = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        } catch (Throwable) {
            return [];
        }

        $subDir = $this->storageDir !== '' ? rtrim($this->storageDir, '/') . '/subtitles' : '';
        $verified = [];
        foreach ($dbIds as $rawId) {
            $cid = strtolower(trim((string) $rawId));
            if ($cid === '') {
                continue;
            }
            if ($subDir !== '' && !is_file($subDir . '/' . $cid . '.zip')) {
                continue;
            }
            $verified[] = $cid;
        }
        return $verified;
    }

    /**
     * Returns titles already registered in `sh_indexed_media` (homepage trending, visited pages,
     * bulk-seeded catalog) that do NOT yet have any verified local subtitles.
     *
     * @param string[] $presentIds
     * @return array<int, array{imdb_id: string, title: string, media_type: string, year: string, poster_url: string, slug: string}>
     */
    public function getMissingIndexedMedia(array $presentIds, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $presentMap = array_flip($presentIds);
        try {
            $stmt = $this->pdo->query(
                "SELECT `imdb_id`, `slug`, `title`, `media_type`, `year`, `poster_url`
                 FROM `{$this->prefix}indexed_media`
                 ORDER BY `updated_at` DESC
                 LIMIT " . ($limit + count($presentIds) + 50)
            );
            $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            return [];
        }

        $missing = [];
        foreach ($rows as $r) {
            $cid = strtolower(trim((string) ($r['imdb_id'] ?? '')));
            if (!preg_match('/^tt\d{5,10}$/', $cid) || isset($presentMap[$cid])) {
                continue;
            }
            $missing[] = [
                'imdb_id'    => $cid,
                'slug'       => (string) ($r['slug'] ?? 'title'),
                'title'      => (string) ($r['title'] ?? 'Untitled'),
                'media_type' => ($r['media_type'] === 'tv') ? 'tv' : 'movie',
                'year'       => (string) ($r['year'] ?? ''),
                'poster_url' => (string) ($r['poster_url'] ?? ''),
            ];
            if (count($missing) >= $limit) {
                break;
            }
        }
        return $missing;
    }

    public function deleteRequest(string $imdbId): void
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return;
        }
        try {
            $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}missing_subtitles` WHERE `imdb_id` = :imdb");
            $stmt->execute([':imdb' => $cleanImdb]);
        } catch (Throwable) {
            // Non-fatal
        }
    }

    /**
     * Returns map of url_hash => ['up' => int, 'down' => int] for an IMDb title.
     *
     * @return array<string, array{up: int, down: int}>
     */
    public function getVotesMapForImdb(string $imdbId): array
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `url_hash`, `upvotes`, `downvotes`
                 FROM `{$this->prefix}subtitle_votes`
                 WHERE `imdb_id` = :imdb"
            );
            $stmt->execute([':imdb' => $cleanImdb]);
            $map = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $map[(string) $row['url_hash']] = [
                    'up'   => (int) $row['upvotes'],
                    'down' => (int) $row['downvotes'],
                ];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Atomically records a registration-free sync vote ('up' or 'down').
     *
     * @return array{up: int, down: int}
     */
    public function recordVote(string $urlHash, string $imdbId, string $vote): array
    {
        $isUp = ($vote === 'up');
        $cleanImdb = strtolower(trim($imdbId));
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}subtitle_votes` (`url_hash`, `imdb_id`, `upvotes`, `downvotes`)
                 VALUES (:h, :imdb, :up, :down)
                 ON DUPLICATE KEY UPDATE
                    `upvotes` = `upvotes` + VALUES(`upvotes`),
                    `downvotes` = `downvotes` + VALUES(`downvotes`)"
            );
            $stmt->execute([
                ':h'    => $urlHash,
                ':imdb' => substr($cleanImdb, 0, 20),
                ':up'   => $isUp ? 1 : 0,
                ':down' => $isUp ? 0 : 1,
            ]);

            $get = $this->pdo->prepare("SELECT `upvotes`, `downvotes` FROM `{$this->prefix}subtitle_votes` WHERE `url_hash` = :h");
            $get->execute([':h' => $urlHash]);
            $row = $get->fetch(PDO::FETCH_ASSOC);
            return ['up' => (int) ($row['upvotes'] ?? 0), 'down' => (int) ($row['downvotes'] ?? 0)];
        } catch (Throwable) {
            return ['up' => 0, 'down' => 0];
        }
    }

    public function clearForImdb(string $imdbId): void
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return;
        }
        try {
            $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}subtitles` WHERE `imdb_id` = :imdb");
            $stmt->execute([':imdb' => $cleanImdb]);
        } catch (Throwable) {
            // Non-fatal
        }
    }

    public function isSyncComplete(string $imdbId): bool
    {
        $cleanImdb = strtolower(trim($imdbId));
        if ($cleanImdb === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT `is_complete` FROM `{$this->prefix}media_sync` WHERE `imdb_id` = :imdb");
            $stmt->execute([':imdb' => $cleanImdb]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }
}

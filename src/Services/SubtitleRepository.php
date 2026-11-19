<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Providers\FreeOpenSubtitlesProvider;
use App\Providers\OpenSubtitlesProvider;
use App\Providers\PodnapisiProvider;
use App\Providers\SubdlProvider;
use App\Providers\Subf2mProvider;
use App\Providers\SubkadeProvider;
use App\Providers\SubzoneProvider;
use App\Providers\TmdbMetadataProvider;
use App\Providers\YifySubtitlesProvider;
use App\Core\Cache;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class SubtitleRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly string $storageDir
    ) {
        $this->ensureTables();
    }

    public function ensureTables(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $flagFile = $this->storageDir . '/cache/.schema_subtitles_v58_auto_ok';
        if (is_file($flagFile)) {
            return;
        }

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->prefix}subtitles` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `url_hash` CHAR(64) NOT NULL,
                    `imdb_id` VARCHAR(20) NOT NULL,
                    `provider` VARCHAR(40) NOT NULL DEFAULT '',
                    `language_code` VARCHAR(10) NOT NULL DEFAULT 'en',
                    `language_label` VARCHAR(60) NOT NULL DEFAULT 'English',
                    `release_name` VARCHAR(255) NOT NULL DEFAULT '',
                    `translator` VARCHAR(100) NOT NULL DEFAULT '',
                    `quality_tag` VARCHAR(30) NOT NULL DEFAULT 'All',
                    `download_url` TEXT NOT NULL,
                    `file_name` VARCHAR(255) NOT NULL DEFAULT 'subtitle.srt',
                    `season` SMALLINT UNSIGNED NULL DEFAULT NULL,
                    `episode` SMALLINT UNSIGNED NULL DEFAULT NULL,
                    `hearing_impaired` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_url_hash` (`url_hash`),
                    INDEX `idx_imdb_active` (`imdb_id`, `is_active`),
                    INDEX `idx_imdb_season` (`imdb_id`, `season`),
                    INDEX `idx_imdb_active_season` (`imdb_id`, `is_active`, `season`),
                    INDEX `idx_imdb_active_lang` (`imdb_id`, `is_active`, `language_code`),
                    INDEX `idx_lang` (`language_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->prefix}subtitle_votes` (
                    `url_hash` CHAR(64) NOT NULL,
                    `imdb_id` VARCHAR(20) NOT NULL DEFAULT '',
                    `upvotes` INT UNSIGNED NOT NULL DEFAULT 0,
                    `downvotes` INT UNSIGNED NOT NULL DEFAULT 0,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`url_hash`),
                    INDEX `idx_imdb` (`imdb_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->prefix}media_sync` (
                    `imdb_id` VARCHAR(20) NOT NULL,
                    `total_subs` INT UNSIGNED NOT NULL DEFAULT 0,
                    `fa_subs` INT UNSIGNED NOT NULL DEFAULT 0,
                    `is_complete` TINYINT(1) NOT NULL DEFAULT 0,
                    `last_synced_at` INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`imdb_id`),
                    INDEX `idx_complete_synced` (`is_complete`, `last_synced_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Auto-migrate existing tables & indexes safely (replaces manual optimize.sql)
            $safeExec = function (string $sql): void {
                try {
                    $this->pdo->exec($sql);
                } catch (Throwable) {
                    // Index/column already exists or table not ready; safe to ignore
                }
            };

            $safeExec("UPDATE `{$this->prefix}subtitles` SET `season` = NULL WHERE `season` IS NOT NULL AND `season` <= 0");
            $safeExec("UPDATE `{$this->prefix}subtitles` SET `episode` = NULL WHERE `episode` IS NOT NULL AND `episode` <= 0");
            $safeExec("ALTER TABLE `{$this->prefix}subtitles` ADD INDEX `idx_imdb_active_season` (`imdb_id`, `is_active`, `season`)");
            $safeExec("ALTER TABLE `{$this->prefix}subtitles` ADD INDEX `idx_imdb_active_lang` (`imdb_id`, `is_active`, `language_code`)");
            $safeExec("ALTER TABLE `{$this->prefix}indexed_media` ADD COLUMN `poster_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `year`");
            $safeExec("ALTER TABLE `{$this->prefix}indexed_media` ADD INDEX `idx_type_year` (`media_type`, `year`)");
            $safeExec("ALTER TABLE `{$this->prefix}download_stats` ADD INDEX `idx_dl_lang_provider` (`language_code`, `provider`)");
            $safeExec("DELETE FROM `{$this->prefix}api_cache` WHERE `expires_at` < " . time());

            // Only write schema lock if poster_url column is verified present
            $colCheck = $this->pdo->query("SHOW COLUMNS FROM `{$this->prefix}indexed_media` LIKE 'poster_url'");
            if ($colCheck !== false && $colCheck->fetch() !== false) {
                if (!is_dir(dirname($flagFile))) {
                    @mkdir(dirname($flagFile), 0755, true);
                }
                @file_put_contents($flagFile, '1');
            }
        } catch (Throwable $e) {
            error_log('[subtitle-repo] schema migration warning: ' . $e->getMessage());
        }
    }

    /**
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
                $chk = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM `{$this->prefix}subtitles`
                     WHERE `imdb_id` = :imdb AND `is_active` = 1 AND `season` = :s"
                );
                $chk->execute([':imdb' => $cleanImdb, ':s' => $season]);
                if ((int) $chk->fetchColumn() < 3) {
                    return [];
                }

                $stmt = $this->pdo->prepare(
                    "SELECT * FROM `{$this->prefix}subtitles`
                     WHERE `imdb_id` = :imdb AND `is_active` = 1 AND (`season` = :s OR `season` IS NULL)
                     ORDER BY `id` ASC LIMIT 1000"
                );
                $stmt->execute([':imdb' => $cleanImdb, ':s' => $season]);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM `{$this->prefix}subtitles`
                     WHERE `imdb_id` = :imdb AND `is_active` = 1
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
     * Returns map of season_number => active subtitle count for a TV series.
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
                 WHERE `imdb_id` = :imdb AND `is_active` = 1 AND `season` IS NOT NULL AND `season` > 0
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
                    if ($item->downloadUrl === '') {
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
                        $saved++;
                    }
                    $stmt = $this->pdo->prepare($baseSql . implode(',', $placeholders) . $updateSql);
                    $stmt->execute($params);
                }
            }

            // Update sync status
            $cntStmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS `tot`, SUM(CASE WHEN `language_code` = 'fa' THEN 1 ELSE 0 END) AS `fa_cnt`
                 FROM `{$this->prefix}subtitles` WHERE `imdb_id` = :imdb AND `is_active` = 1"
            );
            $cntStmt->execute([':imdb' => $cleanImdb]);
            $counts = $cntStmt->fetch(PDO::FETCH_ASSOC) ?: ['tot' => 0, 'fa_cnt' => 0];
            $tot = (int) ($counts['tot'] ?? 0);
            $fa  = (int) ($counts['fa_cnt'] ?? 0);

            $syncStmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}media_sync` (`imdb_id`, `total_subs`, `fa_subs`, `is_complete`, `last_synced_at`)
                 VALUES (:imdb, :tot, :fa, :comp, :ts)
                 ON DUPLICATE KEY UPDATE
                    `total_subs` = VALUES(`total_subs`),
                    `fa_subs` = VALUES(`fa_subs`),
                    `is_complete` = GREATEST(`is_complete`, VALUES(`is_complete`)),
                    `last_synced_at` = VALUES(`last_synced_at`)"
            );
            $syncStmt->execute([
                ':imdb' => $cleanImdb,
                ':tot'  => $tot,
                ':fa'   => $fa,
                ':comp' => $markComplete ? 1 : 0,
                ':ts'   => time(),
            ]);

            if ($fa > 0) {
                $delMiss = $this->pdo->prepare("DELETE FROM `{$this->prefix}missing_subtitles` WHERE `imdb_id` = :imdb");
                $delMiss->execute([':imdb' => $cleanImdb]);
            }
        } catch (Throwable $e) {
            error_log('[subtitle-repo] saveItems error: ' . $e->getMessage());
        }

        return $saved;
    }

    public function deactivateUrl(string $downloadUrl): void
    {
        if ($downloadUrl === '') {
            return;
        }
        try {
            $stmt = $this->pdo->prepare("UPDATE `{$this->prefix}subtitles` SET `is_active` = 0 WHERE `download_url` = :dl");
            $stmt->execute([':dl' => $downloadUrl]);
        } catch (Throwable) {
            // Ignore
        }
    }

    /**
     * @return array{total: int, persian: int, titles: int}
     */
    public function getArchiveStats(): array
    {
        try {
            $row = $this->pdo->query(
                "SELECT
                    COUNT(*) AS `total`,
                    COALESCE(SUM(CASE WHEN `language_code` = 'fa' THEN 1 ELSE 0 END), 0) AS `persian`,
                    COUNT(DISTINCT `imdb_id`) AS `titles`
                 FROM `{$this->prefix}subtitles`
                 WHERE `is_active` = 1"
            )->fetch(PDO::FETCH_ASSOC);

            return [
                'total'   => (int) ($row['total'] ?? 0),
                'persian' => (int) ($row['persian'] ?? 0),
                'titles'  => (int) ($row['titles'] ?? 0),
            ];
        } catch (Throwable) {
            return ['total' => 0, 'persian' => 0, 'titles' => 0];
        }
    }

    /**
     * Picks next batch of titles needing archive completion (prioritizing missing Persian demand,
     * incomplete series, and stale/unsynced indexed media).
     *
     * @return string[]
     */
    public function getNextSyncQueue(int $limit = 5): array
    {
        $queue = [];
        try {
            // 1. High-demand titles missing Persian subtitles
            $s1 = $this->pdo->prepare(
                "SELECT m.`imdb_id`
                 FROM `{$this->prefix}missing_subtitles` m
                 LEFT JOIN `{$this->prefix}media_sync` s ON s.`imdb_id` = m.`imdb_id`
                 WHERE s.`last_synced_at` IS NULL OR s.`last_synced_at` < :cutoff
                 ORDER BY m.`requests_count` DESC, m.`last_requested_at` DESC
                 LIMIT :lim"
            );
            $s1->bindValue(':cutoff', time() - 21600, PDO::PARAM_INT);
            $s1->bindValue(':lim', $limit, PDO::PARAM_INT);
            $s1->execute();
            foreach ($s1->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $queue[(string) $id] = true;
            }

            // 2. Indexed titles never synced or marked incomplete
            if (count($queue) < $limit) {
                $rem = $limit - count($queue);
                $s2 = $this->pdo->prepare(
                    "SELECT i.`imdb_id`
                     FROM `{$this->prefix}indexed_media` i
                     LEFT JOIN `{$this->prefix}media_sync` s ON s.`imdb_id` = i.`imdb_id`
                     WHERE s.`imdb_id` IS NULL OR s.`is_complete` = 0 OR s.`last_synced_at` < :stale
                     ORDER BY (s.`imdb_id` IS NULL) DESC, i.`updated_at` DESC
                     LIMIT :lim"
                );
                $s2->bindValue(':stale', time() - 604800, PDO::PARAM_INT);
                $s2->bindValue(':lim', $rem, PDO::PARAM_INT);
                $s2->execute();
                foreach ($s2->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                    $queue[(string) $id] = true;
                }
            }
        } catch (Throwable) {
            // Ignore
        }

        return array_slice(array_keys($queue), 0, $limit);
    }

    /**
     * Performs a thorough, deep sync for a single IMDb title (used by Cron and Admin manual sync).
     * Starts with Subzone.ir (primary Subf2m mirror) and fails over to Subf2m.co + Subkade.ir.
     *
     * @return array{imdb_id: string, title: string, fetched: int, persian: int}
     */
    public function syncSingleTitle(
        string $imdbId,
        HttpClient $http,
        SettingsManager $settings,
        Cache $cache
    ): array {
        $cleanImdb = strtolower(trim($imdbId));
        $tmdb = new TmdbMetadataProvider($http, $settings, $cache);
        $media = $tmdb->findByImdbId($cleanImdb);
        if ($media === null) {
            return ['imdb_id' => $cleanImdb, 'title' => '', 'fetched' => 0, 'persian' => 0];
        }

        $freeOpenSub = new FreeOpenSubtitlesProvider($http, $settings, $cache);
        $podnapisi   = new PodnapisiProvider($http, $settings);
        $yify        = new YifySubtitlesProvider($http, $settings);
        $subzone     = new SubzoneProvider($http, $settings, $cache);
        $subf2m      = new Subf2mProvider($http, $settings, $cache);
        $subkade     = new SubkadeProvider($http, $settings, $cache);
        $subdl       = new SubdlProvider($http, $settings);
        $openSub     = new OpenSubtitlesProvider($http, $settings);

        $batch = [];
        foreach ([$freeOpenSub, $podnapisi, $yify, $subdl, $openSub] as $idx => $p) {
            if ($p->isConfigured() && ($req = $p->buildSearchRequest($cleanImdb, $media->mediaType)) !== null) {
                $batch[$idx] = $req;
            }
        }
        $responses = $http->getJsonBatch($batch, 10);

        $all = [];
        foreach ([$freeOpenSub, $podnapisi, $yify, $subdl, $openSub] as $idx => $p) {
            if (isset($batch[$idx])) {
                foreach ($p->parseSearchResponse($responses[$idx] ?? null) as $item) {
                    $all[] = $item;
                }
            }
        }

        if ($media->mediaType === 'tv') {
            if ($freeOpenSub->isConfigured()) {
                foreach ($freeOpenSub->searchSeries($cleanImdb, 150, null) as $item) {
                    $all[] = $item;
                }
            }
            if ($subzone->isConfigured()) {
                foreach ($subzone->searchSeries($media->title, $media->year, 15, null) as $item) {
                    $all[] = $item;
                }
            }
            if ($subf2m->isConfigured()) {
                foreach ($subf2m->searchSeries($media->title, $media->year, 15, null) as $item) {
                    $all[] = $item;
                }
            }
            if ($subkade->isConfigured()) {
                foreach ($subkade->searchByTitle($media->title, $media->year, 'tv', null) as $item) {
                    $all[] = $item;
                }
            }
        } else {
            if ($subzone->isConfigured()) {
                foreach ($subzone->searchByTitle($media->title, $media->year) as $item) {
                    $all[] = $item;
                }
            }
            if ($subf2m->isConfigured()) {
                foreach ($subf2m->searchByTitle($media->title, $media->year) as $item) {
                    $all[] = $item;
                }
            }
            if ($subkade->isConfigured()) {
                foreach ($subkade->searchByTitle($media->title, $media->year, 'movie', null) as $item) {
                    $all[] = $item;
                }
            }
        }

        if ($all !== []) {
            $this->clearForImdb($cleanImdb);
        }
        $savedCount = $this->saveItems($cleanImdb, $all, true);
        $faCount = count(array_filter($all, static fn(SubtitleItem $s): bool => $s->languageCode === 'fa'));

        $langCodes = array_values(array_unique(array_map(static fn(SubtitleItem $s): string => $s->languageCode, $all)));
        $seasons = [];
        foreach ($all as $s) {
            if ($s->season !== null && $s->season > 0) {
                $seasons[$s->season] = $s->season;
            }
        }
        ksort($seasons);
        $metrics = new MetricsService($this->pdo, $this->prefix, $this->storageDir);
        $metrics->indexMediaForSeo($media, array_values($seasons), $langCodes);

        return [
            'imdb_id' => $cleanImdb,
            'title'   => $media->title,
            'fetched' => $savedCount,
            'persian' => $faCount,
        ];
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
     * Records an upvote ('up') or downvote ('down') for a subtitle hash.
     *
     * @return array{up: int, down: int}
     */
    public function recordVote(string $urlHash, string $imdbId, string $vote): array
    {
        $col = $vote === 'up' ? 'upvotes' : 'downvotes';
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}subtitle_votes` (`url_hash`, `imdb_id`, `{$col}`)
                 VALUES (:h, :imdb, 1)
                 ON DUPLICATE KEY UPDATE `{$col}` = `{$col}` + 1"
            );
            $stmt->execute([
                ':h'    => substr($urlHash, 0, 64),
                ':imdb' => substr(strtolower(trim($imdbId)), 0, 20),
            ]);

            $sel = $this->pdo->prepare("SELECT `upvotes`, `downvotes` FROM `{$this->prefix}subtitle_votes` WHERE `url_hash` = :h LIMIT 1");
            $sel->execute([':h' => substr($urlHash, 0, 64)]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            return [
                'up'   => (int) ($row['upvotes'] ?? 0),
                'down' => (int) ($row['downvotes'] ?? 0),
            ];
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
            // Ignore
        }
    }
}

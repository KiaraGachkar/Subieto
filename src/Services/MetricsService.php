<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ClientIp;
use App\DTOs\MediaItem;
use PDO;
use RuntimeException;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class MetricsService
{
    private const FLUSH_INTERVAL_SECONDS = 60;
    private const FLUSH_BATCH_THRESHOLD  = 40;
    private const DEDUP_WINDOW_SECONDS   = 900; // 15 minutes per IP + URI

    private string $bufferFile;
    private string $lockFile;
    private string $dedupFile;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_',
        string $storageDir = ''
    ) {
        $baseStorage = $storageDir !== '' ? $storageDir : dirname(__DIR__, 2) . '/storage';
        if (!is_dir($baseStorage)) {
            @mkdir($baseStorage, 0755, true);
        }
        $this->bufferFile = $baseStorage . '/metrics_buffer.jsonl';
        $this->lockFile   = $baseStorage . '/metrics_flush.lock';
        $this->dedupFile  = $baseStorage . '/metrics_dedup.json';
    }

    public function recordPageView(bool $allowDedupFile = true): void
    {
        // The cache-hit path must never use the serialized file dedup map.
        if (!$allowDedupFile && !$this->hasApcu()) {
            return;
        }

        if (!$this->isHumanPageView($allowDedupFile)) {
            return;
        }

        if (!$this->enqueueEvent(['type' => 'pv', 'date' => date('Y-m-d')])) {
            $this->incrementDailyColumnSafe('page_views', date('Y-m-d'), 1);
        }
    }

    /**
     * Lightweight stats hook for STATIC-PAGE-CACHE HITS. Call this instead of
     * recordPageView() from the cache-hit branch in public/index.php.
     *
     * - Skips entirely when APCu is unavailable (no dedup file is opened,
     *   json_decoded or rewritten, so concurrent visitors are never serialized).
     * - Uses only the cheap in-process APCu dedup (IP+URI, 15-minute window).
     * - Does a single append to the JSONL buffer; flushBuffer() aggregates it.
     */
    public static function recordCachedViewFast(string $storageDir, string $prefix = 'sh_'): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return;
        }
        $ua = strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($ua === '' || strlen($ua) < 12 || preg_match('/bot|crawl|spider|slurp|curl\/|wget\/|python|go-http|lighthouse|pagespeed|gtmetrix|ahrefs|semrush/i', $ua)) {
            return;
        }
        $ip   = ClientIp::get();
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $k    = $prefix . 'pv_' . substr(hash('sha256', $ip . '|' . $path), 0, 20);
        if (apcu_exists($k)) {
            return;
        }
        @apcu_store($k, 1, self::DEDUP_WINDOW_SECONDS);
        $line = json_encode(['type' => 'pv', 'date' => date('Y-m-d')], JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents($storageDir . '/metrics_buffer.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function recordCachedView(): void
    {
        if (!$this->hasApcu() || !$this->isHumanPageView(false)) {
            return;
        }

        $line = json_encode(['type' => 'pv', 'date' => date('Y-m-d')], JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents($this->bufferFile, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function hasApcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    private function isHumanPageView(bool $allowDedupFile = true): bool
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return false;
        }

        // Ignore browser prefetch / prerender speculative requests
        $purpose = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_X_MOZ'] ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }

        // Filter out bots, crawlers, uptime monitors, and CLI tools
        $ua = strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($ua === '' || strlen($ua) < 12) {
            return false;
        }

        $botSignatures = [
            'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'bingpreview',
            'facebookexternalhit', 'whatsapp', 'telegrambot', 'twitterbot',
            'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'hetrixtools',
            'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client',
            'httpclient', 'guzzlehttp', 'headlesschrome', 'phantomjs', 'lighthouse',
            'pagespeed', 'gtmetrix', 'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'yandex', 'baidu'
        ];
        foreach ($botSignatures as $sig) {
            if (str_contains($ua, $sig)) {
                return false;
            }
        }

        // Deduplicate same IP + same URL within 15 minutes
        $ip   = ClientIp::get();
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $hash = substr(hash('sha256', $ip . '|' . $path), 0, 20);
        $now  = time();

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $k = $this->prefix . 'pv_' . $hash;
            if (apcu_exists($k)) {
                return false;
            }
            @apcu_store($k, 1, self::DEDUP_WINDOW_SECONDS);
            return true;
        }

        if (!$allowDedupFile) {
            return true; // Caller already skipped the no-APCu case
        }

        // File-based deduplication fallback for shared hosting without APCu
        $dir = dirname($this->dedupFile);
        if (is_dir($dir) && is_writable($dir)) {
            $fp = @fopen($this->dedupFile, 'c+');
            if ($fp !== false) {
                if (flock($fp, LOCK_EX)) {
                    $raw = stream_get_contents($fp);
                    $map = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
                    if (!is_array($map)) {
                        $map = [];
                    }

                    // Prune expired entries occasionally
                    if (count($map) > 500 || random_int(1, 25) === 1) {
                        foreach ($map as $k => $exp) {
                            if ((int) $exp <= $now) {
                                unset($map[$k]);
                            }
                        }
                    }

                    if (isset($map[$hash]) && (int) $map[$hash] > $now) {
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        return false;
                    }

                    $map[$hash] = $now + self::DEDUP_WINDOW_SECONDS;
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, (string) json_encode($map));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
        }

        return true;
    }

    public function recordDownload(): void
    {
        $this->incrementDailyColumnSafe('downloads', date('Y-m-d'), 1);
    }

    public function recordAdClick(string $slotName): void
    {
        $cleanSlot = substr($slotName, 0, 60);
        if (!$this->enqueueEvent(['type' => 'ad', 'slot' => $cleanSlot])) {
            $this->incrementAdClickSafe($cleanSlot, 1);
        }
    }

    /**
     * @param array<string, string> $event
     */
    private function enqueueEvent(array $event): bool
    {
        $dir = dirname($this->bufferFile);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $line = json_encode($event, JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return false;
        }

        $lock = @fopen($this->lockFile, 'c+');
        $haveShared = $lock !== false && flock($lock, LOCK_SH);
        $written = @file_put_contents($this->bufferFile, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($haveShared) {
            flock($lock, LOCK_UN);
        }
        if ($lock !== false) {
            fclose($lock);
        }
        if ($written === false) {
            return false;
        }

        $size = @filesize($this->bufferFile) ?: 0;
        $lastFlush = @filemtime($this->lockFile) ?: 0;

        if ($size > (self::FLUSH_BATCH_THRESHOLD * 45) || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flushBuffer();
        }

        return true;
    }

    public function flushBuffer(): void
    {
        $processingFile = $this->bufferFile . '.processing';
        if (!is_file($this->bufferFile) && !is_file($processingFile)) {
            return;
        }

        $fp = @fopen($this->lockFile, 'c+');
        if ($fp === false) {
            return;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return;
        }

        try {
            @touch($this->lockFile);
            if (!is_file($processingFile) && !@rename($this->bufferFile, $processingFile)) {
                return;
            }

            $raw = @file_get_contents($processingFile);
            if (!is_string($raw)) {
                return;
            }

            if (trim($raw) === '') {
                @unlink($processingFile);
                return;
            }

            $pvByDate  = [];
            $adsBySlot = [];

            foreach (explode("\n", trim($raw)) as $line) {
                $ev = json_decode($line, true);
                if (!is_array($ev)) {
                    continue;
                }
                if (($ev['type'] ?? '') === 'pv' && !empty($ev['date'])) {
                    $d = (string) $ev['date'];
                    $pvByDate[$d] = ($pvByDate[$d] ?? 0) + 1;
                } elseif (($ev['type'] ?? '') === 'ad' && !empty($ev['slot'])) {
                    $s = (string) $ev['slot'];
                    $adsBySlot[$s] = ($adsBySlot[$s] ?? 0) + 1;
                }
            }

            try {
                $this->pdo->beginTransaction();
                foreach ($pvByDate as $date => $count) {
                    $this->incrementDailyColumnOrFail('page_views', $date, $count);
                }
                foreach ($adsBySlot as $slot => $count) {
                    $this->incrementAdClickOrFail($slot, $count);
                }
                $this->pdo->commit();
                @unlink($processingFile);
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log('[metrics] flush failed, will retry: ' . $e->getMessage());
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @return array<string, int>
     */
    public function getAdClicksMap(): array
    {
        $this->flushBuffer();
        try {
            $stmt = $this->pdo->query("SELECT `slot_name`, `clicks` FROM `{$this->prefix}ad_clicks`");
            if ($stmt === false) {
                return [];
            }
            $map = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $map[(string) $row['slot_name']] = (int) $row['clicks'];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    public function recordMissingPersianSubtitle(MediaItem $media): void
    {
        if ($media->imdbId === '') {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}missing_subtitles` (`imdb_id`, `title`, `persian_title`, `media_type`, `year`, `requests_count`)
                 VALUES (:imdb, :title, :fa_title, :type, :year, 1)
                 ON DUPLICATE KEY UPDATE
                    `requests_count` = `requests_count` + 1,
                    `persian_title` = IF(VALUES(`persian_title`) != '', VALUES(`persian_title`), `persian_title`)"
            );
            $stmt->execute([
                ':imdb'     => substr($media->imdbId, 0, 20),
                ':title'    => substr($media->title, 0, 250),
                ':fa_title' => substr($media->persianTitle, 0, 250),
                ':type'     => $media->mediaType === 'tv' ? 'tv' : 'movie',
                ':year'     => substr($media->year, 0, 10),
            ]);
        } catch (Throwable) {
            // Ignore
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopMissingSubtitles(int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `imdb_id`, `title`, `persian_title`, `media_type`, `year`, `requests_count`, `last_requested_at`
                 FROM `{$this->prefix}missing_subtitles`
                 ORDER BY `requests_count` DESC, `last_requested_at` DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param int[]    $seasons
     * @param string[] $langCodes
     */
    public function indexMediaForSeo(MediaItem $media, array $seasons = [], array $langCodes = []): void
    {
        if ($media->imdbId === '') {
            return;
        }

        $seasonsJson = '';
        if (!empty($seasons)) {
            $unique = array_values(array_unique(array_filter($seasons, static fn(int $s): bool => $s > 0 && $s <= 100)));
            $encoded = json_encode($unique);
            if (is_string($encoded) && strlen($encoded) <= 490) {
                $seasonsJson = $encoded;
            }
        }

        $langsJson = '';
        if (!empty($langCodes)) {
            $cleanLangs = array_values(array_unique(array_filter(
                array_map(static fn(string $c): string => strtolower(trim($c)), $langCodes),
                static fn(string $c): bool => (bool) preg_match('/^[a-z]{2,3}$/', $c)
            )));
            $encodedLangs = json_encode($cleanLangs);
            if (is_string($encodedLangs) && strlen($encodedLangs) <= 490) {
                $langsJson = $encodedLangs;
            }
        }

        try {
            $this->ensureLangsColumn();
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}indexed_media` (`imdb_id`, `slug`, `title`, `persian_title`, `media_type`, `year`, `seasons_json`, `langs_json`)
                 VALUES (:imdb, :slug, :title, :fa_title, :type, :year, :seasons, :langs)
                 ON DUPLICATE KEY UPDATE
                    `slug` = VALUES(`slug`),
                    `title` = VALUES(`title`),
                    `persian_title` = IF(VALUES(`persian_title`) != '', VALUES(`persian_title`), `persian_title`),
                    `year` = VALUES(`year`),
                    `seasons_json` = IF(VALUES(`seasons_json`) != '', VALUES(`seasons_json`), `seasons_json`),
                    `langs_json` = IF(VALUES(`langs_json`) != '', VALUES(`langs_json`), `langs_json`)"
            );
            $stmt->execute([
                ':imdb'     => substr($media->imdbId, 0, 20),
                ':slug'     => substr($media->slug, 0, 190),
                ':title'    => substr($media->title, 0, 250),
                ':fa_title' => substr($media->persianTitle, 0, 250),
                ':type'     => $media->mediaType === 'tv' ? 'tv' : 'movie',
                ':year'     => substr($media->year, 0, 10),
                ':seasons'  => $seasonsJson,
                ':langs'    => $langsJson,
            ]);
        } catch (Throwable) {
            // Never disrupt user traffic
        }
    }

    private function ensureLangsColumn(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        $flagFile = dirname($this->bufferFile) . '/cache/.schema_langs_ok';
        if (is_file($flagFile)) {
            return;
        }
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM `{$this->prefix}indexed_media` LIKE 'langs_json'");
            if ($col !== false && $col->fetch() === false) {
                $this->pdo->exec("ALTER TABLE `{$this->prefix}indexed_media` ADD COLUMN `langs_json` VARCHAR(500) NOT NULL DEFAULT '' AFTER `seasons_json`");
            }
            @file_put_contents($flagFile, '1');
        } catch (Throwable) {
            // Ignore if table not ready or lacks ALTER privilege
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLast7Days(): array
    {
        $this->flushBuffer();
        try {
            $stmt = $this->pdo->query(
                "SELECT `metric_date`, `page_views`, `downloads`
                 FROM `{$this->prefix}daily_metrics`
                 ORDER BY `metric_date` DESC LIMIT 7"
            );
            $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            return array_reverse($rows);
        } catch (Throwable) {
            return [];
        }
    }

    public function getIndexedMediaCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}indexed_media`");
            return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function incrementDailyColumnOrFail(string $column, string $date, int $amount): void
    {
        if (!in_array($column, ['page_views', 'downloads'], true)) {
            throw new RuntimeException('Invalid metric column');
        }
        if ($amount <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->prefix}daily_metrics` (`metric_date`, `{$column}`)
             VALUES (:d, :a1)
             ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + :a2"
        );
        $stmt->execute([':d' => $date, ':a1' => $amount, ':a2' => $amount]);
    }

    private function incrementAdClickOrFail(string $slotName, int $amount): void
    {
        if ($slotName === '' || $amount <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->prefix}ad_clicks` (`slot_name`, `clicks`)
             VALUES (:slot, :a1)
             ON DUPLICATE KEY UPDATE `clicks` = `clicks` + :a2"
        );
        $stmt->execute([':slot' => $slotName, ':a1' => $amount, ':a2' => $amount]);
    }

    private function incrementDailyColumnSafe(string $column, string $date, int $amount): void
    {
        try {
            $this->incrementDailyColumnOrFail($column, $date, $amount);
        } catch (Throwable) {
            // Ignore in fallback mode
        }
    }

    private function incrementAdClickSafe(string $slotName, int $amount): void
    {
        try {
            $this->incrementAdClickOrFail($slotName, $amount);
        } catch (Throwable) {
            // Ignore in fallback mode
        }
    }
}

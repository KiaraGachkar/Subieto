<?php

declare(strict_types=1);

namespace App\Controllers;

use App\DTOs\MediaItem;
use App\DTOs\SubtitleItem;
use App\Services\SubtitleRepository;

defined('APP_SECURE_BOOT') or exit;

final class CronController extends BaseController
{
    /**
     * Local-archive status endpoint (no external scraping runs on shared hosting).
     */
    public function sync(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $expectedToken = $this->ensureCronToken();
        $providedToken = trim((string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            return;
        }

        $repo = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');
        echo json_encode([
            'ok'    => true,
            'mode'  => 'local_archive_only',
            'stats' => $repo->getArchiveStats(),
            'queue' => count($repo->getRequestedQueue(50)),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns pending user subtitle requests (`sh_missing_subtitles`) for `subieto_auto_sync.py`.
     */

    /**
     * Two-Way Sync Manifest (`GET /api/sync-manifest`).
     * Gives `subieto_auto_sync.py` the exact live truth of the host:
     *   - `present`: IMDb IDs with BOTH a real ZIP file in storage/subtitles/ AND MySQL rows
     *   - `requests`: Priority-1 user-requested titles (`sh_missing_subtitles`)
     *   - `missing_on_site`: Priority-2 titles already on site (`sh_indexed_media`) with no subtitles yet
     *   - `storage_mb`: Real disk usage of `storage/subtitles/` on host
     *   - `max_file_mb`: PHP upload_max_filesize / post_max_size effective cap per ZIP
     */
    public function syncManifest(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $expectedToken = $this->ensureCronToken();
        $providedToken = trim((string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? ''));

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            return;
        }

        $limit = max(1, min(300, (int) ($_GET['limit'] ?? 200)));
        $repo = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');
        $present = $repo->getVerifiedPresentImdbIds();
        $requests = $repo->getRequestedQueue(min(100, $limit));
        $missingOnSite = $repo->getMissingIndexedMedia($present, $limit);

        // Calculate actual disk usage of `storage/subtitles/*.zip`
        $subDir = $this->basePath . '/storage/subtitles';
        $storageBytes = 0;
        $zipCount = 0;
        if (is_dir($subDir)) {
            foreach (glob($subDir . '/*.zip') ?: [] as $zf) {
                $sz = @filesize($zf);
                if ($sz !== false) {
                    $storageBytes += $sz;
                    $zipCount++;
                }
            }
        }

        $parseIniBytes = static function (string $val): int {
            $val = trim($val);
            if ($val === '') {
                return 8 * 1024 * 1024;
            }
            $unit = strtolower(substr($val, -1));
            $num = (float) $val;
            return match ($unit) {
                'g' => (int) ($num * 1024 * 1024 * 1024),
                'm' => (int) ($num * 1024 * 1024),
                'k' => (int) ($num * 1024),
                default => (int) $num,
            };
        };
        $upMax   = $parseIniBytes((string) ini_get('upload_max_filesize'));
        $postMax = $parseIniBytes((string) ini_get('post_max_size'));
        $effMaxBytes = min(25 * 1024 * 1024, max(1024 * 1024, min($upMax, $postMax)));

        echo json_encode([
            'ok'              => true,
            'present'         => $present,
            'requests'        => $requests,
            'missing_on_site' => $missingOnSite,
            'storage_mb'      => round($storageBytes / (1024 * 1024), 2),
            'zip_count'       => $zipCount,
            'max_file_mb'     => round($effMaxBytes / (1024 * 1024), 2),
            'stats'           => $repo->getArchiveStats(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function requestQueue(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $expectedToken = $this->ensureCronToken();
        $providedToken = trim((string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? ''));

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            return;
        }

        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $repo = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');
        $items = $repo->getRequestedQueue($limit);

        echo json_encode([
            'ok'    => true,
            'count' => count($items),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Legacy JSON import endpoint (disabled for external URLs in clean local-archive mode).
     */
    public function importSubs(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(410);
        echo json_encode([
            'ok'    => false,
            'error' => 'use_upload_archive',
            'hint'  => 'Use POST /api/upload-archive with subieto_auto_sync.py to upload self-hosted ZIP archives.',
        ]);
    }

    /**
     * Receives a self-hosted per-title ZIP archive (`archive`) + JSON metadata (`payload`)
     * from `subieto_auto_sync.py`, stores the ZIP in `storage/subtitles/{imdb_id}.zip`,
     * indexes all `localzip://` entries in MySQL, and clears fulfilled user requests.
     */
    public function uploadArchive(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $expectedToken = $this->ensureCronToken();
        $providedToken = trim((string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? ''));

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            return;
        }

        $payloadRaw = (string) ($_POST['payload'] ?? '');
        $payload = $payloadRaw !== '' ? json_decode($payloadRaw, true) : null;
        if (!is_array($payload) || !is_array($payload['media'] ?? null)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
            return;
        }

        $m = $payload['media'];
        $imdbId = strtolower(trim((string) ($m['imdb_id'] ?? '')));
        if (!preg_match('/^tt\d{5,10}$/', $imdbId)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_imdb']);
            return;
        }

        if (!isset($_FILES['archive']) || !is_array($_FILES['archive']) || (int) ($_FILES['archive']['error'] ?? 4) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_archive_file']);
            return;
        }

        $tmpFile = (string) ($_FILES['archive']['tmp_name'] ?? '');
        $fileSize = (int) ($_FILES['archive']['size'] ?? 0);
        if ($tmpFile === '' || !is_uploaded_file($tmpFile) || $fileSize <= 22 || $fileSize > 25 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_archive_size']);
            return;
        }

        $fh = @fopen($tmpFile, 'rb');
        $magic = $fh ? (string) fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "PK\x03\x04") {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'not_a_zip']);
            return;
        }

        $subDir = $this->basePath . '/storage/subtitles';
        if (!is_dir($subDir) && !@mkdir($subDir, 0755, true) && !is_dir($subDir)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'storage_unwritable']);
            return;
        }
        $htaccess = $subDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\nRequire all denied\n");
        }

        $destPath = $subDir . '/' . $imdbId . '.zip';
        $partPath = $destPath . '.tmp';
        if (!@move_uploaded_file($tmpFile, $partPath) || !@rename($partPath, $destPath)) {
            @unlink($partPath);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'move_failed']);
            return;
        }

        $title = trim((string) ($m['title'] ?? 'Untitled'));
        $mediaType = (($m['media_type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
        $year = trim((string) ($m['year'] ?? ''));
        $poster = trim((string) ($m['poster_url'] ?? ''));
        $slug = trim((string) ($m['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'title', '-')) ?: 'title';
        }

        $mediaItem = new MediaItem(
            tmdbId: 0,
            imdbId: $imdbId,
            title: $title,
            originalTitle: $title,
            mediaType: $mediaType,
            year: $year,
            posterUrl: $poster !== '' ? $poster : ('https://live.metahub.space/poster/medium/' . $imdbId . '/img'),
            backdropUrl: '',
            overview: '',
            voteAverage: 0.0,
            voteCount: 100,
            slug: $slug
        );

        $items = [];
        $seasons = [];
        $langCodes = [];
        $expectedPrefix = 'localzip://' . $imdbId . '.zip#';

        foreach (is_array($payload['subtitles'] ?? null) ? $payload['subtitles'] : [] as $idx => $s) {
            if (!is_array($s)) {
                continue;
            }
            $dlUrl = trim((string) ($s['download_url'] ?? ''));
            if (!str_starts_with($dlUrl, $expectedPrefix) || str_contains($dlUrl, '..')) {
                continue;
            }
            $sNum = isset($s['season']) && (int) $s['season'] > 0 ? (int) $s['season'] : null;
            $eNum = isset($s['episode']) && (int) $s['episode'] > 0 ? (int) $s['episode'] : null;
            $lCode = strtolower(trim((string) ($s['language_code'] ?? 'en'))) ?: 'en';

            if ($sNum !== null) {
                $seasons[$sNum] = $sNum;
            }
            $langCodes[$lCode] = $lCode;

            $items[] = new SubtitleItem(
                id: 'loc_' . $idx,
                provider: substr(trim((string) ($s['provider'] ?? 'Subieto')), 0, 40),
                languageCode: substr($lCode, 0, 10),
                languageLabel: substr(trim((string) ($s['language_label'] ?? 'English')), 0, 60),
                releaseName: substr(trim((string) ($s['release_name'] ?? 'Release')), 0, 250),
                translator: substr(trim((string) ($s['translator'] ?? 'Subieto Archive')), 0, 100),
                qualityTag: substr(trim((string) ($s['quality_tag'] ?? 'All')), 0, 30),
                downloadUrl: $dlUrl,
                fileName: substr(trim((string) ($s['file_name'] ?? 'subtitle.srt')), 0, 250),
                season: $sNum,
                episode: $eNum,
                hearingImpaired: !empty($s['hearing_impaired'])
            );
        }

        $repo = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');
        if ($items !== []) {
            $repo->clearForImdb($imdbId);
        }
        $saved = $repo->saveItems($imdbId, $items, $items !== []);
        $repo->deleteRequest($imdbId);

        ksort($seasons);
        $this->metrics->indexMediaForSeo($mediaItem, array_values($seasons), array_values($langCodes));
        $this->pageCache?->clearAll();

        echo json_encode([
            'ok'        => true,
            'imdb_id'   => $imdbId,
            'saved'     => $saved,
            'zip_bytes' => $fileSize,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function ensureCronToken(): string
    {
        $token = trim((string) $this->settings->get('cron.secret_token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
            $this->settings->updateMany(['cron.secret_token' => $token]);
        }
        return $token;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\SettingsManager;
use PDO;
use Throwable;
use ZipArchive;

defined('APP_SECURE_BOOT') or exit;

/**
 * Serves verified UTF-8 `.srt` subtitles directly from local per-title ZIP archives
 * stored in `storage/subtitles/{imdb_id}.zip` with zero external HTTP calls.
 */
final class ProxyDownloadService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_'
    ) {
    }

    /**
     * @param array{imdbId?: string, title?: string, provider?: string, lang?: string} $meta
     */
    public function deliver(string $rawUrl, string $desiredFileName, array $meta = []): void
    {
        if (!str_starts_with($rawUrl, 'localzip://')) {
            http_response_code(410);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'این لینک قدیمی دیگر پشتیبانی نمی‌شود. لطفاً از فایل‌های آرشیو مستقیم سایت استفاده کنید.';
            return;
        }

        $spec = substr($rawUrl, strlen('localzip://'));
        $parts = explode('#', $spec, 2);
        if (count($parts) !== 2) {
            http_response_code(400);
            echo 'لینک فایل محلی نامعتبر است.';
            return;
        }

        [$zipFile, $entryName] = $parts;
        if (
            !preg_match('/^tt\d{5,10}\.zip$/i', $zipFile)
            || $entryName === ''
            || str_contains($entryName, '..')
            || str_starts_with($entryName, '/')
            || str_contains($entryName, '\\')
        ) {
            http_response_code(400);
            echo 'مسیر فایل زیرنویس نامعتبر است.';
            return;
        }

        $zipFullPath = dirname(__DIR__, 2) . '/storage/subtitles/' . strtolower($zipFile);
        if (!is_file($zipFullPath) || !class_exists(ZipArchive::class)) {
            http_response_code(404);
            echo 'فایل آرشیو زیرنویس روی سرور یافت نشد.';
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFullPath) !== true) {
            http_response_code(500);
            echo 'خطا در باز کردن فایل آرشیو زیرنویس.';
            return;
        }

        $content = $zip->getFromName($entryName);
        $zip->close();

        if (!is_string($content) || $content === '') {
            http_response_code(404);
            echo 'فایل زیرنویس مورد نظر داخل آرشیو یافت نشد.';
            return;
        }

        $cleanFileName = $this->brandFileName(
            $this->sanitizeFileName($desiredFileName !== '' ? $desiredFileName : basename($entryName))
        );
        $this->recordDownload($cleanFileName, $meta);

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: application/x-subrip; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $cleanFileName . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        echo $content;
        exit;
    }

    /**
     * @param array{imdbId?: string, title?: string, provider?: string, lang?: string} $meta
     */
    private function recordDownload(string $fileName, array $meta): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}download_stats` (`imdb_id`, `media_title`, `provider`, `language_code`, `file_name`)
                 VALUES (:imdb, :title, :provider, :lang, :fname)"
            );
            $stmt->execute([
                ':imdb'     => substr((string) ($meta['imdbId'] ?? ''), 0, 20),
                ':title'    => substr((string) ($meta['title'] ?? ''), 0, 250),
                ':provider' => substr((string) ($meta['provider'] ?? ''), 0, 50),
                ':lang'     => substr((string) ($meta['lang'] ?? 'fa'), 0, 10),
                ':fname'    => substr($fileName, 0, 250),
            ]);

            $metrics = new MetricsService($this->pdo, $this->prefix);
            $metrics->recordDownload();
        } catch (Throwable) {
            // Never block download
        }
    }

    private function sanitizeFileName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'subtitle.srt';
        return $name !== '' ? $name : 'subtitle.srt';
    }

    private function brandFileName(string $name): string
    {
        $site = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) $this->settings->get('site.title', 'Subieto')) ?: 'Subieto';
        $foreignSites = 'subkade|subzone|subf2m|subdl|yifysubtitles|yify|yts|opensubtitles|podnapisi|addic7ed|tvsubtitles|tsubtitle|worldsub|animesub|farsisubtitle|subpars';
        $name = preg_replace('/[\[\(\{][^\]\)\}]*(?:' . $foreignSites . ')[^\]\)\}]*[\]\)\}]/ui', '', $name) ?? $name;
        $name = preg_replace('/(?<![A-Za-z0-9])(?:' . $foreignSites . ')(?![A-Za-z0-9])/ui', '', $name) ?? $name;

        $pathInfo = pathinfo($name);
        $filename = $pathInfo['filename'] ?? 'subtitle';
        $ext = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '.srt';

        if (!str_contains(strtolower($filename), strtolower($site))) {
            $filename = rtrim($filename, '._-') . '_' . $site;
        }

        return preg_replace('/[._-]{2,}/', '_', $filename) . $ext;
    }
}

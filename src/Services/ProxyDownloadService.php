<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\Providers\OpenSubtitlesProvider;
use App\Providers\Subf2mProvider;
use PDO;
use Throwable;
use ZipArchive;

defined('APP_SECURE_BOOT') or exit;

final class ProxyDownloadService
{
    private const ZIP_MAX_ENTRIES     = 50;
    private const ZIP_MAX_ENTRY_BYTES = 2097152; // 2 MB per subtitle entry
    private const ZIP_MAX_TOTAL_BYTES = 8388608; // 8 MB total uncompressed

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings,
        private readonly OpenSubtitlesProvider $openSubtitlesProvider,
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_'
    ) {
    }


    /**
     * @param array{imdbId?: string, title?: string, provider?: string, lang?: string} $meta
     */
    public function deliver(string $rawUrl, string $desiredFileName, array $meta = []): void
    {
        $resolvedUrl = $this->resolveTargetUrl($rawUrl);
        $pinnedIp = null;
        if ($resolvedUrl === null || !$this->isAllowedDomain($resolvedUrl, $pinnedIp)) {
            http_response_code(400);
            echo 'لینک دانلود نامعتبر است یا منقضی شده است.';
            return;
        }

        $cleanFileName = $this->sanitizeFileName($desiredFileName);

        $mode = (string) $this->settings->get('site.download_mode', 'stream');

        if ($mode === 'redirect') {
            $this->recordDownload($cleanFileName, $meta);
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $resolvedUrl, true, 302);
            exit;
        }

        $response = $this->fetchPinnedHttps($resolvedUrl, 20, $pinnedIp);

        if ($response['status'] >= 300 && $response['status'] < 400 && !empty($response['headers']['location'])) {
            $redirTarget = HttpClient::absolutize($resolvedUrl, (string) $response['headers']['location']);
            $redirIp = null;
            if ($this->isAllowedDomain($redirTarget, $redirIp)) {
                $response = $this->fetchPinnedHttps($redirTarget, 20, $redirIp);
            }
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $resolvedUrl, true, 302);
            exit;
        }

        $content = $response['body'];
        $isZip = str_starts_with($content, "PK\x03\x04") || str_ends_with(strtolower($cleanFileName), '.zip');

        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');

        if ($isZip) {
            if (!str_ends_with(strtolower($cleanFileName), '.zip')) {
                $cleanFileName .= '.zip';
            }
            $processedZip = $this->processZipArchive($content);
            if ($processedZip === null) {
                error_log('[proxy-download] Rejected oversized ZIP archive: ' . $cleanFileName);
                http_response_code(422);
                echo 'فایل آرشیو زیرنویس بیش از حد مجاز حجیم است و قابل پردازش نیست.';
                return;
            }
            $content = $processedZip;
            header('Content-Type: application/zip');
        } else {
            $content = $this->normalizeToUtf8($content);
            header('Content-Type: application/x-subrip; charset=utf-8');
        }

        $this->recordDownload($cleanFileName, $meta);

        header('Content-Disposition: attachment; filename="' . $cleanFileName . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        echo $content;
        exit;
    }

    private function zipWithinLimits(ZipArchive $zip): bool
    {
        if ($zip->numFiles > self::ZIP_MAX_ENTRIES) {
            return false;
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                return false;
            }
            $size = (int) ($stat['size'] ?? 0);
            if ($size > self::ZIP_MAX_ENTRY_BYTES) {
                return false;
            }
            $totalUncompressed += $size;
            if ($totalUncompressed > self::ZIP_MAX_TOTAL_BYTES) {
                return false;
            }
        }

        return true;
    }

    private function processZipArchive(string $zipBinary): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return $zipBinary;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'shzip_');
        if ($tmpFile === false) {
            return $zipBinary;
        }

        try {
            if (file_put_contents($tmpFile, $zipBinary) === false) {
                return $zipBinary;
            }

            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return $zipBinary;
            }

            if (!$this->zipWithinLimits($zip)) {
                $zip->close();
                return null;
            }

            $modified = false;
            $textSubExtensions = ['srt', 'vtt', 'ass', 'ssa'];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (!is_string($entryName) || str_ends_with($entryName, '/')) {
                    continue;
                }

                $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                if (!in_array($ext, $textSubExtensions, true)) {
                    continue;
                }

                $rawEntry = $zip->getFromIndex($i);
                if (!is_string($rawEntry) || $rawEntry === '') {
                    continue;
                }

                $processed = $this->normalizeToUtf8($rawEntry);

                if ($processed !== $rawEntry) {
                    $zip->addFromString($entryName, $processed);
                    $modified = true;
                }
            }

            $zip->close();

            if ($modified) {
                $updated = file_get_contents($tmpFile);
                if (is_string($updated) && $updated !== '') {
                    return $updated;
                }
            }

            return $zipBinary;
        } catch (Throwable) {
            return $zipBinary;
        } finally {
            @unlink($tmpFile);
        }
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

    private function resolveTargetUrl(string $rawUrl): ?string
    {
        if (str_starts_with($rawUrl, 'opensubtitles://file/')) {
            $fileId = (int) substr($rawUrl, strlen('opensubtitles://file/'));
            return $fileId > 0 ? $this->openSubtitlesProvider->resolveDownloadLink($fileId) : null;
        }

        if (str_starts_with($rawUrl, 'subf2m://')) {
            $detailUrl = substr($rawUrl, strlen('subf2m://'));
            if (str_starts_with($detailUrl, 'https://subf2m.co/')) {
                $subf2m = new Subf2mProvider($this->http, $this->settings);
                return $subf2m->resolveDownloadLink($detailUrl);
            }
            return null;
        }

        return filter_var($rawUrl, FILTER_VALIDATE_URL) ? $rawUrl : null;
    }

    private function isAllowedDomain(string $url, ?string &$pinnedIp = null): bool
    {
        $pinnedIp = null;
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $matched = false;
        foreach (['subdl.com', 'opensubtitles.com', 'opensubtitles.org', 'strem.io', 'yifysubtitles.ch', 'subf2m.co', 'podnapisi.net'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return false;
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            if ($records === []) {
                return false;
            }
            foreach ($records as $r) {
                $ip = (string) ($r['ip'] ?? $r['ipv6'] ?? '');
                if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return false;
                }
                // Reuse this resolution for CURLOPT_RESOLVE in fetchPinnedHttps
                // instead of doing a second DNS lookup. Prefer an IPv4 address.
                if ($pinnedIp === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $pinnedIp = $ip;
                }
            }
        }

        return true;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function fetchPinnedHttps(string $url, int $timeout, ?string $pinnedIp = null): array
    {
        $host  = (string) parse_url($url, PHP_URL_HOST);
        $ip    = ($pinnedIp !== null && $pinnedIp !== '') ? $pinnedIp : ($host !== '' ? gethostbyname($host) : '');
        $extra = ($ip !== '' && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            ? [CURLOPT_RESOLVE => [$host . ':443:' . $ip]]
            : [];
        return $this->http->request('GET', $url, [], null, $timeout, false, $extra);
    }

    private function normalizeToUtf8(string $binary): string
    {
        if (str_starts_with($binary, "\xEF\xBB\xBF")) {
            return $binary;
        }

        if (str_starts_with($binary, "\xFF\xFE") && function_exists('iconv')) {
            $utf8 = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($binary, 2));
            if (is_string($utf8) && $utf8 !== '') {
                return "\xEF\xBB\xBF" . $utf8;
            }
        } elseif (str_starts_with($binary, "\xFE\xFF") && function_exists('iconv')) {
            $utf8 = @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($binary, 2));
            if (is_string($utf8) && $utf8 !== '') {
                return "\xEF\xBB\xBF" . $utf8;
            }
        }

        if (mb_check_encoding($binary, 'UTF-8')) {
            return "\xEF\xBB\xBF" . $binary;
        }

        if (function_exists('iconv')) {
            try {
                $converted = @iconv('CP1256', 'UTF-8//IGNORE', $binary);
                if (is_string($converted) && $converted !== '') {
                    return "\xEF\xBB\xBF" . $converted;
                }
            } catch (Throwable) {
                // Fall through
            }
        }

        return "\xEF\xBB\xBF" . $binary;
    }

    private function sanitizeFileName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'subtitle.srt';
        return $name !== '' ? $name : 'subtitle.srt';
    }
}

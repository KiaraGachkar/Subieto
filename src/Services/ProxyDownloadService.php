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
     * @return array<int, array{time: string, text: string}>
     */
    public function extractPreview(string $rawUrl, float $shiftSeconds = 0.0): array
    {
        $shiftSeconds = max(-300.0, min(300.0, $shiftSeconds));

        $resolvedUrl = $this->resolveTargetUrl($rawUrl);
        $pinnedIp = null;
        if ($resolvedUrl === null || !$this->isAllowedDomain($resolvedUrl, $pinnedIp)) {
            return [];
        }

        $response = $this->fetchPinnedHttps($resolvedUrl, 15, $pinnedIp);
        if ($response['status'] >= 300 && $response['status'] < 400 && !empty($response['headers']['location'])) {
            $redirTarget = HttpClient::absolutize($resolvedUrl, (string) $response['headers']['location']);
            $redirIp = null;
            if ($this->isAllowedDomain($redirTarget, $redirIp)) {
                $response = $this->fetchPinnedHttps($redirTarget, 15, $redirIp);
            }
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            return [];
        }

        $content = $response['body'];
        $subText = '';

        if (str_starts_with($content, "PK\x03\x04") && class_exists(ZipArchive::class)) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'shprev_');
            if ($tmpFile !== false) {
                try {
                    file_put_contents($tmpFile, $content);
                    $zip = new ZipArchive();
                    if ($zip->open($tmpFile) === true) {
                        if ($this->zipWithinLimits($zip)) {
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $name = $zip->getNameIndex($i);
                                if (is_string($name) && preg_match('/\.(srt|vtt)$/i', $name)) {
                                    $entry = $zip->getFromIndex($i);
                                    if (is_string($entry) && $entry !== '') {
                                        $subText = $entry;
                                        break;
                                    }
                                }
                            }
                        }
                        $zip->close();
                    }
                } catch (Throwable) {
                    $subText = '';
                } finally {
                    @unlink($tmpFile);
                }
            }
        } else {
            $subText = $content;
        }

        if ($subText === '') {
            return [];
        }

        $subText = $this->normalizeToUtf8($subText);
        if (str_starts_with($subText, "\xEF\xBB\xBF")) {
            $subText = substr($subText, 3);
        }

        if (abs($shiftSeconds) >= 0.05) {
            $subText = $this->shiftSubtitleTimestamps($subText, $shiftSeconds);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $subText);
        $blocks = preg_split('/\n{2,}/', trim($normalized)) ?: [];
        $cues = [];

        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn($l) => $l !== ''));
            if (count($lines) < 2) {
                continue;
            }
            $timeIdx = null;
            foreach ($lines as $idx => $line) {
                if (str_contains($line, '-->')) {
                    $timeIdx = $idx;
                    break;
                }
            }
            if ($timeIdx === null || !isset($lines[$timeIdx + 1])) {
                continue;
            }
            $textLines = array_slice($lines, $timeIdx + 1);
            $cleanText = strip_tags(implode("\n", $textLines));
            $cues[] = [
                'time' => $lines[$timeIdx],
                'text' => $cleanText,
            ];
            if (count($cues) >= 6) {
                break;
            }
        }

        return $cues;
    }

    /**
     * @param array{imdbId?: string, title?: string, provider?: string, lang?: string} $meta
     */
    public function deliver(string $rawUrl, string $desiredFileName, array $meta = [], float $shiftSeconds = 0.0): void
    {
        $resolvedUrl = $this->resolveTargetUrl($rawUrl);
        $pinnedIp = null;
        if ($resolvedUrl === null || !$this->isAllowedDomain($resolvedUrl, $pinnedIp)) {
            http_response_code(400);
            echo 'لینک دانلود نامعتبر است یا منقضی شده است.';
            return;
        }

        $shiftSeconds = max(-300.0, min(300.0, $shiftSeconds));

        $cleanFileName = $this->sanitizeFileName($desiredFileName);
        if (abs($shiftSeconds) >= 0.05) {
            $sign = $shiftSeconds > 0 ? '+' : '';
            $ext  = pathinfo($cleanFileName, PATHINFO_EXTENSION);
            $base = pathinfo($cleanFileName, PATHINFO_FILENAME);
            $cleanFileName = $base . '_sync_' . $sign . rtrim(rtrim(number_format($shiftSeconds, 1, '.', ''), '0'), '.') . 's' . ($ext !== '' ? '.' . $ext : '');
        }

        $mode = (string) $this->settings->get('site.download_mode', 'stream');

        if ($mode === 'redirect' && abs($shiftSeconds) < 0.05) {
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
            $processedZip = $this->processZipArchive($content, $shiftSeconds);
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
            if (abs($shiftSeconds) >= 0.05) {
                $content = $this->shiftSubtitleTimestamps($content, $shiftSeconds);
            }
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

    private function processZipArchive(string $zipBinary, float $shiftSeconds): ?string
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
                if (abs($shiftSeconds) >= 0.05 && in_array($ext, ['srt', 'vtt'], true)) {
                    $processed = $this->shiftSubtitleTimestamps($processed, $shiftSeconds);
                }

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

    private function shiftSubtitleTimestamps(string $content, float $shiftSeconds): string
    {
        $shiftMs = (int) round($shiftSeconds * 1000);

        return preg_replace_callback(
            '/(?:(\d{1,3}):)?(\d{1,3}):(\d{2})([,.])(\d{3})\s*-->\s*(?:(\d{1,3}):)?(\d{1,3}):(\d{2})([,.])(\d{3})/',
            static function (array $m) use ($shiftMs): string {
                $hasStartHours = ($m[1] ?? '') !== '';
                $hasEndHours   = ($m[6] ?? '') !== '';
                $startMs = (((int) ($m[1] ?? 0) * 3600) + ((int) $m[2] * 60) + (int) $m[3]) * 1000 + (int) $m[5];
                $endMs   = (((int) ($m[6] ?? 0) * 3600) + ((int) $m[7] * 60) + (int) $m[8]) * 1000 + (int) $m[10];

                $newStart = max(0, $startMs + $shiftMs);
                $newEnd   = max(0, $endMs + $shiftMs);

                $formatTime = static function (int $totalMs, string $sep, bool $withHours): string {
                    $hours = intdiv($totalMs, 3600000);
                    $rem   = $totalMs % 3600000;
                    $mins  = intdiv($rem, 60000);
                    $rem   = $rem % 60000;
                    $secs  = intdiv($rem, 1000);
                    $ms    = $rem % 1000;
                    if ($withHours) {
                        return sprintf('%02d:%02d:%02d%s%03d', $hours, $mins, $secs, $sep, $ms);
                    }
                    return sprintf('%02d:%02d%s%03d', ($hours * 60) + $mins, $secs, $sep, $ms);
                };

                return $formatTime($newStart, $m[4], $hasStartHours)
                    . ' --> '
                    . $formatTime($newEnd, $m[9], $hasEndHours);
            },
            $content
        ) ?? $content;
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

<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

final class StaticPageCache
{
    private string $pagesDir;

    public function __construct(string $basePath)
    {
        $this->pagesDir = $basePath . '/storage/pages';
        if (!is_dir($this->pagesDir)) {
            @mkdir($this->pagesDir, 0755, true);
        }
    }

    public static function emitCacheHeaders(int $maxAge = 3600): void
    {
        if (headers_sent() || $maxAge <= 0) {
            return;
        }

        header("Cache-Control: public, max-age={$maxAge}, s-maxage={$maxAge}");
        header('Vary: Accept-Language');
    }

    public function serveIfValid(string $cacheKey, int $fallbackTtl = 7200): bool
    {
        if ($fallbackTtl <= 0 || !$this->isCacheableKey($cacheKey)) {
            return false;
        }

        $file = $this->getFilePath($cacheKey);
        if (!is_file($file)) {
            return false;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $now = time();
        $html = $raw;
        $remainingTtl = $fallbackTtl;

        if (preg_match('/^<!-- exp:(\d+) -->\n/', $raw, $m)) {
            $expiresAt = (int) $m[1];
            if ($expiresAt <= $now) {
                @unlink($file);
                return false;
            }
            $remainingTtl = $expiresAt - $now;
            $html = substr($raw, strlen($m[0]));
        } else {
            $mtime = @filemtime($file);
            if ($mtime === false || ($now - $mtime) > $fallbackTtl) {
                @unlink($file);
                return false;
            }
            $remainingTtl = $fallbackTtl - ($now - $mtime);
        }

        self::emitCacheHeaders(min($remainingTtl, 1800));
        header('X-Page-Cache: HIT');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $html;
        }
        return true;
    }

    public function store(string $cacheKey, string $html, int $ttl): void
    {
        if ($ttl <= 0 || $html === '' || !$this->isCacheableKey($cacheKey)) {
            return;
        }

        if (!is_dir($this->pagesDir) || !is_writable($this->pagesDir)) {
            return;
        }

        $expiresAt = time() + $ttl;
        $payload = "<!-- exp:{$expiresAt} -->\n" . $html;

        $target = $this->getFilePath($cacheKey);
        $tmp = $target . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
            }
        }
    }

    public function clearAll(): int
    {
        if (!is_dir($this->pagesDir)) {
            return 0;
        }

        $files = glob($this->pagesDir . '/*.html*') ?: [];
        $count = 0;
        foreach ($files as $f) {
            if (@unlink($f)) {
                $count++;
            }
        }
        return $count;
    }

    public function countFiles(): int
    {
        if (!is_dir($this->pagesDir)) {
            return 0;
        }
        return count(glob($this->pagesDir . '/*.html*') ?: []);
    }

    private function isCacheableKey(string $key): bool
    {
        $pathOnly = explode(':', $key, 2)[0];
        $pathOnly = preg_replace('/\?[0-9a-f]{32}$/', '', $pathOnly) ?? $pathOnly;
        return (bool) preg_match(
            '#^(?:/|/search|/robots\.txt|/sitemap(?:-pages|-media-\d+)?\.xml|/subtitles/tt\d{5,10}(?:/[a-z0-9-]+)?(?:/(?:season-\d+|[a-z-]+))?|/subtitles/release/[a-z0-9-]+)$#',
            $pathOnly
        );
    }

    private function getFilePath(string $key): string
    {
        return $this->pagesDir . '/' . hash('sha256', $key) . '.html';
    }
}

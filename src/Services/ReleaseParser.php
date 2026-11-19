<?php

declare(strict_types=1);

namespace App\Services;

defined('APP_SECURE_BOOT') or exit;

final class ReleaseParser
{
    private const SEASON_WORDS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
        'eleventh' => 11, 'twelfth' => 12, 'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15,
        'sixteenth' => 16, 'seventeenth' => 17, 'eighteenth' => 18, 'nineteenth' => 19, 'twentieth' => 20,
    ];

    /**
     * Extracts positive [season, episode] from release titles or descriptions.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function extractSeasonEpisode(string $text, ?int $fallbackSeason = null): array
    {
        $cleanFallback = ($fallbackSeason !== null && $fallbackSeason > 0) ? $fallbackSeason : null;

        if (preg_match('/\bS(\d{1,2})\s*E(\d{1,3})\b/i', $text, $m)) {
            $s = (int) $m[1];
            $e = (int) $m[2];
            return [$s > 0 ? $s : $cleanFallback, $e > 0 ? $e : null];
        }

        if (preg_match('/\b(\d{1,2})x(\d{1,3})\b/i', $text, $m)) {
            $s = (int) $m[1];
            $e = (int) $m[2];
            return [$s > 0 ? $s : $cleanFallback, $e > 0 ? $e : null];
        }

        if (preg_match('/فصل\s*(\d{1,2}).{0,20}قسمت\s*(\d{1,3})/u', $text, $m)) {
            $s = (int) $m[1];
            $e = (int) $m[2];
            return [$s > 0 ? $s : $cleanFallback, $e > 0 ? $e : null];
        }

        if (preg_match('/(?:\bS0?(\d{1,2})\b|\bSeason\s*(\d{1,2})\b|فصل\s*(\d{1,2}))/iu', $text, $m)) {
            $rawSeason = (int) ($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? 0)));
            return [$rawSeason > 0 ? $rawSeason : $cleanFallback, null];
        }

        return [$cleanFallback, null];
    }

    public static function seasonFromSlug(string $slug): ?int
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $wordPattern = implode('|', array_keys(self::SEASON_WORDS));
        if (preg_match('/(?:^|-)(' . $wordPattern . ')-season(?:-|$)/', $slug, $m)) {
            return self::SEASON_WORDS[$m[1]] ?? null;
        }

        if (preg_match('/(?:^|-)(\d{1,2})(?:st|nd|rd|th)-season(?:-|$)/', $slug, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? $n : null;
        }

        if (preg_match('/(?:^|-)season-(\d{1,2})(?:-|$)/', $slug, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? $n : null;
        }

        if (preg_match('/(?:^|-)s0?(\d{1,2})(?:-|$)/', $slug, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? $n : null;
        }

        return null;
    }

    public static function seasonFromText(string $text): ?int
    {
        $low = strtolower($text);
        $wordPattern = implode('|', array_keys(self::SEASON_WORDS));

        if (preg_match('/\b(' . $wordPattern . ')\s+season\b/', $low, $m)) {
            return self::SEASON_WORDS[$m[1]] ?? null;
        }

        if (preg_match('/\bseason\s+(\d{1,2})\b/', $low, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? $n : null;
        }

        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+season\b/', $low, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? $n : null;
        }

        if (preg_match('/فصل\s*(\d{1,2})/u', $text, $m)) {
            $n = (int) $m[1];
            return $n > 0 ? $n : null;
        }

        return null;
    }

    public static function detectQuality(string $release): string
    {
        $upper = strtoupper($release);
        return match (true) {
            str_contains($upper, '2160P'), str_contains($upper, '4K'), str_contains($upper, 'UHD') => '2160p',
            str_contains($upper, 'BLURAY'), str_contains($upper, 'BDRIP'), str_contains($upper, 'BRRIP') => 'BluRay',
            str_contains($upper, 'WEB-DL'), str_contains($upper, 'WEBDL'), str_contains($upper, 'WEBRIP') => 'WEB-DL',
            str_contains($upper, 'HDRIP'), str_contains($upper, 'DVDRIP') => 'HDRip',
            str_contains($upper, 'HDTV') => 'HDTV',
            default => 'All',
        };
    }

    public static function normalizeTitle(string $text): string
    {
        $clean = strtolower($text);
        $clean = preg_replace('/\((?:19|20)\d{2}\)/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? $clean;
        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }
}

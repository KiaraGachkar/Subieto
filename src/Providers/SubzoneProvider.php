<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use DOMDocument;
use DOMXPath;

defined('APP_SECURE_BOOT') or exit;

final class SubzoneProvider implements SubtitleProviderInterface
{
    private const BASE_URL = 'https://subzone.ir';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings,
        private readonly ?Cache $cache = null
    ) {
    }

    public function getName(): string
    {
        return 'Subzone';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('providers.subzone_enabled', '1') === '1';
    }

    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array
    {
        return null;
    }

    public function parseSearchResponse(?array $data): array
    {
        return [];
    }

    public function searchByImdbId(string $imdbId, string $mediaType = 'movie'): array
    {
        return [];
    }

    /**
     * Searches subzone.ir for movies or TV series by title (+ optional year/season).
     *
     * @return SubtitleItem[]
     */
    public function searchByTitle(string $title, string $year = '', string $mediaType = 'movie', ?int $season = null): array
    {
        $cleanTitle = trim($title);
        if (!$this->isConfigured() || $cleanTitle === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = 'subzone_v1_' . md5(strtolower($cleanTitle) . '|' . trim($year) . '|' . $mediaType . '|' . ($season ?? 'all'));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $headers = $this->browserHeaders();
        $searchCandidates = [
            self::BASE_URL . '/?s=' . urlencode($cleanTitle),
            self::BASE_URL . '/search?q=' . urlencode($cleanTitle),
        ];

        $searchHtml = '';
        foreach ($searchCandidates as $sUrl) {
            $res = $this->http->request('GET', $sUrl, $headers, null, 7);
            if ($res['status'] >= 200 && $res['status'] < 300 && $res['body'] !== '') {
                $searchHtml = $res['body'];
                break;
            }
        }

        if ($searchHtml === '') {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $detailUrls = $this->extractMatchingDetailUrls($searchHtml, $cleanTitle, $year, $mediaType, $season);
        if ($detailUrls === []) {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $batchReqs = [];
        foreach (array_slice($detailUrls, 0, 5) as $idx => $dUrl) {
            $batchReqs[$idx] = [
                'url'     => $dUrl,
                'headers' => $headers,
                'rawHtml' => true,
            ];
        }

        $items = [];
        $seen = [];
        foreach ($this->http->getJsonBatch($batchReqs, 8) as $idx => $resp) {
            $html = is_array($resp) ? (string) ($resp['_html'] ?? '') : '';
            if ($html === '') {
                continue;
            }
            $pageUrl = $batchReqs[$idx]['url'] ?? self::BASE_URL;
            foreach ($this->extractSubtitlesFromPage($html, $pageUrl, $cleanTitle, $season) as $sub) {
                if (isset($seen[$sub->downloadUrl])) {
                    continue;
                }
                $seen[$sub->downloadUrl] = true;
                $items[] = $sub;
            }
            if (count($items) >= 120) {
                break;
            }
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), $items === [] ? 900 : 43200);
        }

        return $items;
    }

    /**
     * Resolves an indirect subzone detail page into a direct archive/srt link when needed.
     */
    public function resolveDownloadLink(string $detailUrl): ?string
    {
        if (preg_match('/\.(?:zip|rar|srt|ass|vtt)(?:\?|$)/i', $detailUrl)) {
            return $detailUrl;
        }

        $res = $this->http->request('GET', $detailUrl, $this->browserHeaders(), null, 8);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return null;
        }

        if (preg_match('#href=["']([^"']+\.(?:zip|rar|srt)(?:\?[^"']*)?)["']#i', $res['body'], $m)) {
            return HttpClient::absolutize($detailUrl, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        if (preg_match('#href=["']([^"']*(?:/download|/dl/|get-sub)[^"']*)["']#i', $res['body'], $m)) {
            return HttpClient::absolutize($detailUrl, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractMatchingDetailUrls(string $html, string $title, string $year, string $mediaType, ?int $season): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $needle = mb_strtolower(trim($title));
        $slugNeedle = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?: '';
        $scored = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $abs = HttpClient::absolutize(self::BASE_URL . '/', $href);
            $host = strtolower((string) parse_url($abs, PHP_URL_HOST));
            if ($host !== 'subzone.ir' && $host !== 'www.subzone.ir') {
                continue;
            }
            $path = (string) (parse_url($abs, PHP_URL_PATH) ?? '/');
            if ($path === '/' || preg_match('#^/(?:tag|category|author|page|wp-content|wp-admin|feed|search)#i', $path)) {
                continue;
            }
            if (isset($_GET['s']) || str_contains($abs, '?s=')) {
                continue;
            }

            $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $a->textContent) ?: ''));
            $combined = $text . ' ' . strtolower($path);

            $score = 0;
            if ($needle !== '' && str_contains($text, $needle)) {
                $score += 50;
            }
            if ($slugNeedle !== '' && str_contains(strtolower($path), $slugNeedle)) {
                $score += 40;
            }
            if ($score === 0) {
                continue;
            }
            if ($year !== '' && str_contains($combined, $year)) {
                $score += 25;
            }
            if ($mediaType === 'tv' && (str_contains($combined, 'سریال') || str_contains($combined, 'series') || str_contains($combined, 'season') || str_contains($combined, 'فصل'))) {
                $score += 15;
            }
            if ($season !== null && (str_contains($combined, "فصل {$season}") || str_contains($combined, "season {$season}") || str_contains($combined, "s0{$season}") || str_contains($combined, "s{$season}"))) {
                $score += 30;
            }

            $scored[$abs] = max($scored[$abs] ?? 0, $score);
        }

        arsort($scored);
        return array_keys($scored);
    }

    /**
     * @return SubtitleItem[]
     */
    private function extractSubtitlesFromPage(string $html, string $pageUrl, string $fallbackTitle, ?int $requestedSeason): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $pageSeason = $this->detectSeasonFromText($html, false);
        $items = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $idx => $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $abs = HttpClient::absolutize($pageUrl, $href);
            $host = strtolower((string) parse_url($abs, PHP_URL_HOST));
            if ($host !== 'subzone.ir' && $host !== 'www.subzone.ir' && !str_ends_with($host, '.subzone.ir')) {
                continue;
            }

            $isDirectArchive = (bool) preg_match('/\.(?:zip|rar|srt|ass|vtt)(?:\?|$)/i', $abs);
            $isDownloadRoute = (bool) preg_match('#(?:/download|/dl/|get-sub|dl=)#i', $abs);
            if (!$isDirectArchive && !$isDownloadRoute) {
                continue;
            }

            $linkText = trim(preg_replace('/\s+/', ' ', (string) $a->textContent) ?: '');
            $contextText = $linkText;
            $parent = $a->parentNode;
            if ($parent !== null) {
                $pText = trim(preg_replace('/\s+/', ' ', (string) $parent->textContent) ?: '');
                if ($pText !== '' && mb_strlen($pText) <= 260) {
                    $contextText = $pText;
                }
            }

            $releaseName = $linkText;
            if ($releaseName === '' || mb_strlen($releaseName) < 4 || preg_match('/^(?:دانلود|download|لینک مستقیم)$/iu', $releaseName)) {
                $pathBase = pathinfo((string) parse_url($abs, PHP_URL_PATH), PATHINFO_FILENAME);
                $releaseName = $pathBase !== '' ? urldecode($pathBase) : ($fallbackTitle . ' Persian Subtitle');
            }
            $releaseName = trim(preg_replace('/\s+/', ' ', $releaseName) ?: $fallbackTitle);

            [$season, $episode] = $this->extractSeasonEpisode($releaseName . ' ' . $contextText, $requestedSeason ?? $pageSeason);

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ('subzone_' . $idx);
            $ext = preg_match('/\.(zip|rar|srt|ass|vtt)(?:\?|$)/i', $abs, $em) ? ('.' . strtolower($em[1])) : '.zip';
            $dlUrl = $isDirectArchive ? $abs : ('subzone://' . $abs);

            $items[] = new SubtitleItem(
                id: 'subzone_' . md5($abs . '_' . $idx),
                provider: 'Subzone',
                languageCode: 'fa',
                languageLabel: 'Persian',
                releaseName: $releaseName,
                translator: $this->extractTranslator($contextText),
                qualityTag: $this->detectQuality($releaseName . ' ' . $contextText),
                downloadUrl: $dlUrl,
                fileName: substr($safeBase, 0, 120) . $ext,
                season: $season,
                episode: $episode,
                hearingImpaired: false
            );
        }

        return $items;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function extractSeasonEpisode(string $text, ?int $fallbackSeason = null): array
    {
        if (preg_match('/S(\d{1,2})\s*E(\d{1,3})/i', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/فصل\s*(\d{1,2}).{0,15}قسمت\s*(\d{1,3})/u', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(?:Season|فصل)\s*(\d{1,2})/iu', $text, $m)) {
            return [(int) $m[1], null];
        }
        if (preg_match('/\bS(\d{1,2})\b/i', $text, $m)) {
            return [(int) $m[1], null];
        }
        return [$fallbackSeason, null];
    }

    private function detectSeasonFromText(string $html, bool $allowLoose = true): ?int
    {
        if (preg_match('/<title[^>]*>.*?(?:فصل|Season)\s*(\d{1,2})/isu', $html, $m)) {
            return (int) $m[1];
        }
        if ($allowLoose && preg_match('/(?:فصل|Season)\s*(\d{1,2})/iu', $html, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function extractTranslator(string $text): string
    {
        if (preg_match('/مترجم\s*[:：]\s*([^\n\r|،,-]{2,40})/u', $text, $m)) {
            return trim($m[1]);
        }
        return 'Subzone';
    }

    private function detectQuality(string $release): string
    {
        $upper = strtoupper($release);
        return match (true) {
            str_contains($upper, 'BLURAY'), str_contains($upper, 'BDRIP'), str_contains($upper, 'BRRIP') => 'BluRay',
            str_contains($upper, 'WEB-DL'), str_contains($upper, 'WEBDL'), str_contains($upper, 'WEBRIP') => 'WEB-DL',
            str_contains($upper, 'HDRIP'), str_contains($upper, 'DVDRIP') => 'HDRip',
            str_contains($upper, 'HDTV') => 'HDTV',
            default => 'All',
        };
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer'         => self::BASE_URL . '/',
        ];
    }
}

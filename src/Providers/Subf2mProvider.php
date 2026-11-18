<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;
use DOMDocument;
use DOMXPath;

defined('APP_SECURE_BOOT') or exit;

class Subf2mProvider implements SubtitleProviderInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings,
        private readonly ?Cache $cache = null,
        private readonly string $baseUrl = 'https://subf2m.co',
        private readonly string $id = 'subf2m',
        private readonly string $name = 'Subf2m',
        private readonly string $settingKey = 'providers.subf2m_enabled'
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return $this->settings->get($this->settingKey, '1') === '1';
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

    public function searchByTitle(string $title, string $year = ''): array
    {
        if (!$this->isConfigured() || trim($title) === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = $this->id . '_title_v3_' . md5(strtolower(trim($title)) . '|' . trim($year));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $headers = $this->browserHeaders();
        $searchUrl = $this->baseUrl . '/subtitles/searchbytitle?query=' . urlencode(trim($title)) . '&l=';
        $searchRes = $this->http->request('GET', $searchUrl, $headers, null, 6);
        if ($searchRes['status'] < 200 || $searchRes['status'] >= 300 || $searchRes['body'] === '') {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $moviePath = $this->extractBestMoviePath($searchRes['body'], $title, $year);
        if ($moviePath === null) {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $detailReqs = [
            'all' => ['url' => $this->baseUrl . $moviePath, 'headers' => $headers, 'rawHtml' => true],
            'fa'  => ['url' => $this->baseUrl . $moviePath . '/farsi_persian', 'headers' => $headers, 'rawHtml' => true],
        ];
        $detailBatch = $this->http->getJsonBatch($detailReqs, 6);
        $allHtml = is_array($detailBatch['all'] ?? null) ? (string) ($detailBatch['all']['_html'] ?? '') : '';
        $faHtml  = is_array($detailBatch['fa'] ?? null) ? (string) ($detailBatch['fa']['_html'] ?? '') : '';

        if ($allHtml === '' && $faHtml === '') {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $items = [];
        $seenUrls = [];
        foreach ([$faHtml, $allHtml] as $pageHtml) {
            if ($pageHtml === '') {
                continue;
            }
            foreach ($this->extractSubtitlesFromDetailHtml($pageHtml) as $subItem) {
                if (isset($seenUrls[$subItem->downloadUrl])) {
                    continue;
                }
                $seenUrls[$subItem->downloadUrl] = true;
                $items[] = $subItem;
            }
        }
        if ($this->cache !== null) {
            $ttl = $items === [] ? 900 : 86400;
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), $ttl);
        }

        return $items;
    }

    public function resolveDownloadLink(string $detailUrl): ?string
    {
        $res = $this->http->request('GET', $detailUrl, $this->browserHeaders(), null, 8);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return null;
        }

        if (preg_match('#href=["\']([^"\']*/subtitle/download[^"\']+)["\']#i', $res['body'], $m)) {
            return HttpClient::absolutize($this->baseUrl . '/', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        return null;
    }

    private function extractBestMoviePath(string $html, string $title, string $year): ?string
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//div[contains(@class,"search-result")]//a[contains(@href,"/subtitles/")] | //ul//li//a[starts-with(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            return null;
        }

        $firstHref = null;
        $needle = mb_strtolower(trim($title));
        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_contains($href, 'searchbytitle') || preg_match('#^/subtitles/[^/]+/[^/]+/\d+#', $href)) {
                continue;
            }
            if (!preg_match('#^/subtitles/[a-z0-9-]+$#i', $href)) {
                continue;
            }
            $text = trim((string) $a->textContent);
            if ($firstHref === null && ($needle === '' || str_contains(mb_strtolower($text), $needle))) {
                $firstHref = $href;
            }
            if ($year !== '' && str_contains($text, $year)) {
                return $href;
            }
        }

        return $firstHref;
    }

    private function extractSubtitlesFromDetailHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//li[contains(@class,"item")]');
        if (!$rows || $rows->length === 0) {
            $rows = $xpath->query('//ul[contains(@class,"scrolllist")]//li');
        }
        if (!$rows || $rows->length === 0) {
            $rows = $xpath->query('//a[contains(@href,"/subtitles/")]/ancestor::li[1]');
        }
        if (!$rows || $rows->length === 0) {
            return [];
        }

        $items = [];
        $seen = [];
        foreach ($rows as $idx => $li) {
            $linkNode = $xpath->query('.//a[contains(@href,"/subtitles/")]', $li);
            if (!$linkNode || $linkNode->length === 0) {
                continue;
            }
            $href = '';
            $matchedLangSeg = '';
            foreach ($linkNode as $candidateA) {
                $candHref = trim((string) $candidateA->getAttribute('href'));
                if (preg_match('#^/subtitles/[^/]+/([^/]+)/\d+#i', $candHref, $lm)) {
                    $href = $candHref;
                    $matchedLangSeg = $lm[1];
                    break;
                }
            }
            if ($href === '' || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;

            $langNode = $xpath->query('.//span[contains(@class,"language")]', $li);
            $langText = ($langNode && $langNode->length > 0) ? trim((string) $langNode->item(0)?->textContent) : '';
            $lang = LanguageMap::resolve($langText !== '' ? $langText : $matchedLangSeg);

            $releaseNodes = $xpath->query('.//ul[contains(@class,"scrolllist")]//li | .//span[contains(@class,"name")] | .//div[contains(@class,"name")]', $li);
            $releaseName = '';
            if ($releaseNodes && $releaseNodes->length > 0) {
                foreach ($releaseNodes as $rn) {
                    $cand = trim(preg_replace('/\s+/', ' ', (string) $rn->textContent) ?: '');
                    if ($cand !== '' && mb_strlen($cand) > 3) {
                        $releaseName = $cand;
                        break;
                    }
                }
            }
            if ($releaseName === '') {
                $releaseName = trim((string) $linkNode->item(0)?->textContent);
            }
            $releaseName = trim(preg_replace('/\s+/', ' ', $releaseName) ?: 'Subf2m Release');

            $authorNode = $xpath->query('.//div[contains(@class,"comment-col")]//a', $li);
            $translator = ($authorNode && $authorNode->length > 0)
                ? trim((string) $authorNode->item(0)?->textContent)
                : $this->name;

            $season = null;
            $episode = null;
            if (preg_match('/S(\d{1,2})E(\d{1,3})/i', $releaseName, $sm)) {
                $season = (int) $sm[1];
                $episode = (int) $sm[2];
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ($this->id . '_' . $idx);
            $detailFullUrl = $this->baseUrl . $href;

            $items[] = new SubtitleItem(
                id: $this->id . '_' . md5($detailFullUrl . '_' . $idx),
                provider: $this->name,
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $translator !== '' ? $translator : $this->name,
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: $this->id . '://' . $detailFullUrl,
                fileName: substr($safeBase, 0, 120) . '.zip',
                season: $season,
                episode: $episode,
                hearingImpaired: false
            );

            if (count($items) >= 80) {
                break;
            }
        }

        return $items;
    }

    public function searchSeries(string $title, string $year = '', int $maxPages = 20, ?int $season = null): array
    {
        if (!$this->isConfigured() || trim($title) === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = $this->id . '_series_v4_' . md5(strtolower(trim($title)) . '|' . trim($year) . '|' . ($season ?? 'all'));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $res = $this->http->request(
            'GET',
            $this->baseUrl . '/subtitles/searchbytitle?query=' . urlencode(trim($title)) . '&l=',
            $this->browserHeaders(),
            null,
            7
        );
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $res['body'], LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $links = $xpath->query('//ul//li//a[starts-with(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $normTitle = $this->normalizeTitleForMatch($title);
        $titleWords = array_values(array_filter(explode(' ', $normTitle), static fn(string $w): bool => strlen($w) >= 2));

        $matchedSeasonPaths = [];
        $seriesHubPaths = [];

        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            $text = trim(preg_replace('/\s+/', ' ', (string) $a->textContent) ?: '');
            if ($href === '' || str_contains($href, 'searchbytitle') || !preg_match('#^/subtitles/([a-z0-9-]+)$#i', $href, $hm)) {
                continue;
            }
            $slug = strtolower($hm[1]);
            $normText = $this->normalizeTitleForMatch($text);

            $matchesTitle = ($normTitle !== '' && str_contains($normText, $normTitle));
            if (!$matchesTitle && count($titleWords) >= 2) {
                $hits = 0;
                foreach ($titleWords as $w) {
                    if (str_contains($normText, $w)) {
                        $hits++;
                    }
                }
                $matchesTitle = ($hits === count($titleWords));
            }
            if (!$matchesTitle) {
                continue;
            }

            if (!str_contains($normTitle, 'story so far') && str_contains($normText, 'story so far')) {
                continue;
            }

            $detectedSeason = $this->seasonFromSlug($slug) ?? $this->seasonFromText($text);
            if ($detectedSeason !== null) {
                if ($season === null || $detectedSeason === $season) {
                    $matchedSeasonPaths[$href] = $detectedSeason;
                }
            } else {
                $seriesHubPaths[$href] = true;
            }
        }

        $targetPaths = $matchedSeasonPaths;
        if ($targetPaths === [] || $season === null) {
            foreach ($seriesHubPaths as $hPath => $_) {
                if (!isset($targetPaths[$hPath])) {
                    $targetPaths[$hPath] = null;
                }
            }
        }

        if ($targetPaths === []) {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        uasort($targetPaths, static fn(?int $a, ?int $b): int => ($a ?? 999) <=> ($b ?? 999));
        $targetPaths = array_slice($targetPaths, 0, max($maxPages, 25), true);

        $batchReqs = [];
        foreach ($targetPaths as $path => $sNum) {
            $batchReqs['all|' . $path] = [
                'url'     => $this->baseUrl . $path,
                'headers' => $this->browserHeaders(),
                'rawHtml' => true,
            ];
            $batchReqs['fa|' . $path] = [
                'url'     => $this->baseUrl . $path . '/farsi_persian',
                'headers' => $this->browserHeaders(),
                'rawHtml' => true,
            ];
        }

        $items = [];
        $seen = [];
        $discoveredExtraSeasons = [];

        $collect = function (string $html, ?int $pageSeason) use (&$items, &$seen): int {
            $added = 0;
            foreach ($this->extractSubtitlesFromDetailHtml($html) as $item) {
                if (isset($seen[$item->downloadUrl])) {
                    continue;
                }
                if ($item->season === null && $pageSeason !== null) {
                    $item->season = $pageSeason;
                }
                $seen[$item->downloadUrl] = true;
                $items[] = $item;
                $added++;
            }
            return $added;
        };

        $batchResponses = $this->http->getJsonBatch($batchReqs, 8);
        uksort($batchResponses, static fn(string $a, string $b): int => (str_starts_with($a, 'fa|') ? 0 : 1) <=> (str_starts_with($b, 'fa|') ? 0 : 1));

        foreach ($batchResponses as $reqKey => $resp) {
            $html = is_array($resp) ? (string) ($resp['_html'] ?? '') : '';
            if ($html === '') {
                continue;
            }
            [, $path] = explode('|', (string) $reqKey, 2);
            $pageSeason = $targetPaths[$path] ?? null;
            $added = $collect($html, $pageSeason);

            if ($added === 0 && $pageSeason === null) {
                $slug = substr($path, strlen('/subtitles/'));
                foreach ($this->extractSeasonLinks($html, $slug) as $sPath => $sNum) {
                    if (($season === null || $sNum === $season) && !isset($targetPaths[$sPath])) {
                        $discoveredExtraSeasons[$sPath] = $sNum;
                    }
                }
            }
        }

        if ($discoveredExtraSeasons !== []) {
            asort($discoveredExtraSeasons);
            $discoveredExtraSeasons = array_slice($discoveredExtraSeasons, 0, 20, true);
            $extraReqs = [];
            foreach ($discoveredExtraSeasons as $sPath => $sNum) {
                $extraReqs['fa|' . $sPath] = [
                    'url'     => $this->baseUrl . $sPath . '/farsi_persian',
                    'headers' => $this->browserHeaders(),
                    'rawHtml' => true,
                ];
                $extraReqs['all|' . $sPath] = [
                    'url'     => $this->baseUrl . $sPath,
                    'headers' => $this->browserHeaders(),
                    'rawHtml' => true,
                ];
            }
            foreach ($this->http->getJsonBatch($extraReqs, 8) as $reqKey => $resp) {
                $html = is_array($resp) ? (string) ($resp['_html'] ?? '') : '';
                if ($html !== '') {
                    [, $sPath] = explode('|', (string) $reqKey, 2);
                    $collect($html, $discoveredExtraSeasons[$sPath] ?? null);
                }
            }
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), $items === [] ? 900 : 86400);
        }
        return $items;
    }

    private function normalizeTitleForMatch(string $text): string
    {
        $clean = strtolower($text);
        $clean = preg_replace('/\((?:19|20)\d{2}\)/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? $clean;
        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }

    private function seasonFromText(string $text): ?int
    {
        static $words = [
            'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6,
            'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12,
            'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15, 'sixteenth' => 16, 'seventeenth' => 17,
            'eighteenth' => 18, 'nineteenth' => 19, 'twentieth' => 20,
        ];
        $low = strtolower($text);
        if (preg_match('/\b(' . implode('|', array_keys($words)) . ')\s+season\b/', $low, $m)) {
            return $words[$m[1]];
        }
        if (preg_match('/\bseason\s+(\d{1,2})\b/', $low, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+season\b/', $low, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function seasonFromSlug(string $slug): ?int
    {
        static $words = [
            'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6,
            'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12,
            'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15, 'sixteenth' => 16, 'seventeenth' => 17,
            'eighteenth' => 18, 'nineteenth' => 19, 'twentieth' => 20,
        ];
        $slug = strtolower($slug);
        if (preg_match('/(?:^|-)(' . implode('|', array_keys($words)) . ')-season(?:-|$)/', $slug, $m)) {
            return $words[$m[1]];
        }
        if (preg_match('/(?:^|-)(\d{1,2})(?:st|nd|rd|th)-season(?:-|$)/', $slug, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(?:^|-)season-(\d{1,2})(?:-|$)/', $slug, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function extractSeasonLinks(string $html, string $pageSlug): array
    {
        $base = preg_replace('/-(?:[a-z]+|\d{1,2}(?:st|nd|rd|th))-season.*$/', '', strtolower($pageSlug)) ?: strtolower($pageSlug);
        $base = preg_replace('/-(?:19|20)\d{2}$/', '', $base) ?: $base;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $out = [];
        foreach ($xpath->query('//a[starts-with(@href,"/subtitles/")]') ?: [] as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if (!preg_match('#^/subtitles/([a-z0-9-]+)$#i', $href, $m)) {
                continue;
            }
            $num = $this->seasonFromSlug($m[1]);
            if ($num === null || !str_starts_with(strtolower($m[1]), $base)) {
                continue;
            }
            $out[$href] = $num;
        }
        return $out;
    }

    private function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer'         => $this->baseUrl . '/',
        ];
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
}
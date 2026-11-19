<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;
use App\Services\ReleaseParser;
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

    /**
     * @return SubtitleItem[]
     */
    public function searchByTitle(string $title, string $year = ''): array
    {
        $cleanTitle = trim($title);
        if (!$this->isConfigured() || $cleanTitle === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = $this->id . '_title_v5_' . md5(strtolower($cleanTitle) . '|' . trim($year));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && $cached !== []) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $searchHtml = $this->fetchSearchHtml($cleanTitle);
        if ($searchHtml === '') {
            return [];
        }

        $moviePath = $this->extractBestMoviePath($searchHtml, $cleanTitle, $year);
        if ($moviePath === null) {
            return [];
        }

        $items = $this->fetchPagesAndCollect([$moviePath => null], 6);
        if ($this->cache !== null && $items !== []) {
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), 86400);
        }

        return $items;
    }

    /**
     * @return SubtitleItem[]
     */
    public function searchSeries(string $title, string $year = '', int $maxPages = 25, ?int $season = null): array
    {
        $cleanTitle = trim($title);
        if (!$this->isConfigured() || $cleanTitle === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = $this->id . '_series_v6_' . md5(strtolower($cleanTitle) . '|' . trim($year) . '|' . ($season ?? 'all'));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && $cached !== []) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $searchHtml = $this->fetchSearchHtml($cleanTitle);
        if ($searchHtml === '') {
            return [];
        }

        $targetPaths = $this->resolveSeriesTargetPaths($searchHtml, $cleanTitle, $season, $maxPages);
        if ($targetPaths === []) {
            return [];
        }

        $items = $this->fetchPagesAndCollect($targetPaths, 6, $season);
        if ($this->cache !== null && $items !== []) {
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), 86400);
        }

        return $items;
    }

    public function resolveDownloadLink(string $detailUrl): ?string
    {
        $headers = $this->browserHeaders();
        $headers['Referer'] = $this->baseUrl . '/';
        $res = $this->http->request('GET', $detailUrl, $headers, null, 12);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return null;
        }

        if (preg_match('#href=["\']([^"\']*/subtitles?/[^"\']*download[^"\']*)["\']#i', $res['body'], $m)) {
            return HttpClient::absolutize($detailUrl, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (preg_match('#href=["\']([^"\']*/download[^"\']+)["\']#i', $res['body'], $m)) {
            return HttpClient::absolutize($detailUrl, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        return null;
    }

    private function fetchSearchHtml(string $query): string
    {
        $url = $this->baseUrl . '/subtitles/searchbytitle?query=' . urlencode($query) . '&l=';
        $res = $this->http->request('GET', $url, $this->browserHeaders(), null, 5);
        return ($res['status'] >= 200 && $res['status'] < 300) ? $res['body'] : '';
    }

    /**
     * @param array<string, ?int> $targetPaths path => ?int season
     * @return SubtitleItem[]
     */
    private function fetchPagesAndCollect(array $targetPaths, int $timeout, ?int $requestedSeason = null): array
    {
        $headers = $this->browserHeaders();
        $batchReqs = [];
        foreach ($targetPaths as $path => $_) {
            $batchReqs['fa|' . $path] = [
                'url'     => $this->baseUrl . $path . '/farsi_persian',
                'headers' => $headers,
                'rawHtml' => true,
            ];
            $batchReqs['all|' . $path] = [
                'url'     => $this->baseUrl . $path,
                'headers' => $headers,
                'rawHtml' => true,
            ];
        }

        $items = [];
        $seen = [];
        $discoveredExtraSeasons = [];

        $collect = function (string $html, ?int $pageSeason) use (&$items, &$seen): int {
            $added = 0;
            foreach ($this->extractSubtitlesFromDetailHtml($html, $pageSeason) as $item) {
                if (isset($seen[$item->downloadUrl])) {
                    continue;
                }
                $seen[$item->downloadUrl] = true;
                $items[] = $item;
                $added++;
            }
            return $added;
        };

        foreach ($this->http->getJsonBatch($batchReqs, $timeout) as $reqKey => $resp) {
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
                    if (($requestedSeason === null || $sNum === $requestedSeason) && !isset($targetPaths[$sPath])) {
                        $discoveredExtraSeasons[$sPath] = $sNum;
                    }
                }
            }
        }

        if ($discoveredExtraSeasons !== []) {
            asort($discoveredExtraSeasons);
            $discoveredExtraSeasons = array_slice($discoveredExtraSeasons, 0, 20, true);
            $extraReqs = [];
            foreach ($discoveredExtraSeasons as $sPath => $_) {
                $extraReqs['fa|' . $sPath] = [
                    'url'     => $this->baseUrl . $sPath . '/farsi_persian',
                    'headers' => $headers,
                    'rawHtml' => true,
                ];
                $extraReqs['all|' . $sPath] = [
                    'url'     => $this->baseUrl . $sPath,
                    'headers' => $headers,
                    'rawHtml' => true,
                ];
            }
            foreach ($this->http->getJsonBatch($extraReqs, $timeout) as $reqKey => $resp) {
                $html = is_array($resp) ? (string) ($resp['_html'] ?? '') : '';
                if ($html !== '') {
                    [, $sPath] = explode('|', (string) $reqKey, 2);
                    $collect($html, $discoveredExtraSeasons[$sPath] ?? null);
                }
            }
        }

        return $items;
    }

    /**
     * @return array<string, ?int>
     */
    private function resolveSeriesTargetPaths(string $html, string $title, ?int $season, int $maxPages): array
    {
        $xpath = $this->buildXPath($html);
        $links = $xpath?->query('//a[starts-with(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            return [];
        }

        $matchedSeasonPaths = [];
        $seriesHubPaths = [];

        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            $text = trim(preg_replace('/\s+/', ' ', (string) $a->textContent) ?: '');
            if ($href === '' || str_contains($href, 'searchbytitle') || !preg_match('#^/subtitles/([a-z0-9-]+)$#i', $href, $hm)) {
                continue;
            }

            $slug = strtolower($hm[1]);
            $slugAsText = str_replace('-', ' ', $slug);

            $score = max(
                ReleaseParser::titleMatchScore($text, $title, '', true),
                ReleaseParser::titleMatchScore($slugAsText, $title, '', true)
            );
            if ($score <= 0) {
                continue;
            }

            $detectedSeason = ReleaseParser::seasonFromSlug($slug) ?? ReleaseParser::seasonFromText($text);
            if ($detectedSeason !== null) {
                if ($season === null || $detectedSeason === $season) {
                    $matchedSeasonPaths[$href] = $detectedSeason;
                }
            } else {
                $seriesHubPaths[$href] = $score;
            }
        }

        $targetPaths = $matchedSeasonPaths;
        if ($targetPaths === [] || $season === null) {
            arsort($seriesHubPaths);
            foreach (array_slice(array_keys($seriesHubPaths), 0, 2) as $hPath) {
                if (!isset($targetPaths[$hPath])) {
                    $targetPaths[$hPath] = null;
                }
            }
        }

        uasort($targetPaths, static fn(?int $a, ?int $b): int => ($a ?? 999) <=> ($b ?? 999));
        return array_slice($targetPaths, 0, max($maxPages, 15), true);
    }

    private function extractBestMoviePath(string $html, string $title, string $year): ?string
    {
        $xpath = $this->buildXPath($html);
        $links = $xpath?->query('//div[contains(@class,"search-result")]//a[contains(@href,"/subtitles/")] | //a[starts-with(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            return null;
        }

        $bestHref  = null;
        $bestScore = 0;

        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_contains($href, 'searchbytitle') || preg_match('#^/subtitles/[^/]+/[^/]+/\d+#', $href)) {
                continue;
            }
            if (!preg_match('#^/subtitles/([a-z0-9-]+)$#i', $href, $hm)) {
                continue;
            }

            $slug = strtolower($hm[1]);
            // Skip TV season pages when looking for a movie
            if (ReleaseParser::seasonFromSlug($slug) !== null) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', (string) $a->textContent) ?: '');
            if (ReleaseParser::seasonFromText($text) !== null) {
                continue;
            }

            // Also inspect parent/sibling text for release year (Subf2m often puts year in a sibling span)
            $contextText = $text;
            if ($a->parentNode !== null) {
                $pText = trim(preg_replace('/\s+/', ' ', (string) $a->parentNode->textContent) ?: '');
                if ($pText !== '' && mb_strlen($pText) <= 180) {
                    $contextText = $pText;
                }
            }

            $score = max(
                ReleaseParser::titleMatchScore($text, $title, $year, false),
                ReleaseParser::titleMatchScore(str_replace('-', ' ', $slug), $title, $year, false)
            );
            if ($score <= 0) {
                continue;
            }
            if ($year !== '' && str_contains($contextText, $year) && !str_contains($text, $year)) {
                $score += 60;
            }
            // Penalize wrong year explicitly present in the candidate text
            if ($year !== '' && preg_match('/\b((?:19|20)\d{2})\b/', $contextText, $ym) && $ym[1] !== $year) {
                $score -= 100;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestHref  = $href;
            }
        }

        return $bestScore >= 100 ? $bestHref : null;
    }

    /**
     * @return SubtitleItem[]
     */
    private function extractSubtitlesFromDetailHtml(string $html, ?int $pageSeason = null): array
    {
        $xpath = $this->buildXPath($html);
        if ($xpath === null) {
            return [];
        }

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

            [$season, $episode] = ReleaseParser::extractSeasonEpisode($releaseName, $pageSeason);
            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ($this->id . '_' . $idx);
            $detailFullUrl = $this->baseUrl . $href;

            $items[] = new SubtitleItem(
                id: $this->id . '_' . md5($detailFullUrl . '_' . $idx),
                provider: $this->name,
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $translator !== '' ? $translator : $this->name,
                qualityTag: ReleaseParser::detectQuality($releaseName),
                downloadUrl: $this->id . '://' . $detailFullUrl,
                fileName: substr($safeBase, 0, 120) . '.zip',
                season: $season,
                episode: $episode,
                hearingImpaired: false
            );

            if (count($items) >= 250) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<string, int>
     */
    private function extractSeasonLinks(string $html, string $pageSlug): array
    {
        $base = preg_replace('/-(?:[a-z]+|\d{1,2}(?:st|nd|rd|th))-season.*$/', '', strtolower($pageSlug)) ?: strtolower($pageSlug);
        $base = preg_replace('/-(?:19|20)\d{2}$/', '', $base) ?: $base;

        $xpath = $this->buildXPath($html);
        if ($xpath === null) {
            return [];
        }

        $out = [];
        foreach ($xpath->query('//a[starts-with(@href,"/subtitles/")]') ?: [] as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if (!preg_match('#^/subtitles/([a-z0-9-]+)$#i', $href, $m)) {
                continue;
            }
            $num = ReleaseParser::seasonFromSlug($m[1]);
            if ($num === null || !str_starts_with(strtolower($m[1]), $base)) {
                continue;
            }
            $out[$href] = $num;
        }
        return $out;
    }

    private function buildXPath(string $html): ?DOMXPath
    {
        if ($html === '') {
            return null;
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        return $loaded ? new DOMXPath($dom) : null;
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer'         => $this->baseUrl . '/',
        ];
    }
}

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

final class Subf2mProvider implements SubtitleProviderInterface
{
    private const BASE_URL = 'https://subf2m.co';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings,
        private readonly ?Cache $cache = null
    ) {
    }

    public function getName(): string
    {
        return 'Subf2m';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('providers.subf2m_enabled', '1') === '1';
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
        if (!$this->isConfigured() || trim($title) === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = 'subf2m_title_v3_' . md5(strtolower(trim($title)) . '|' . trim($year));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $headers = $this->browserHeaders();

        $searchUrl = self::BASE_URL . '/subtitles/searchbytitle?query=' . urlencode(trim($title)) . '&l=';
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

        // Fetch main movie page AND dedicated Farsi/Persian language page concurrently
        $detailReqs = [
            'all' => ['url' => self::BASE_URL . $moviePath, 'headers' => $headers, 'rawHtml' => true],
            'fa'  => ['url' => self::BASE_URL . $moviePath . '/farsi_persian', 'headers' => $headers, 'rawHtml' => true],
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

        if (preg_match('#href=["\'](/subtitle/download[^"\']+)["\']#i', $res['body'], $m)) {
            return self::BASE_URL . html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
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

    /**
     * @return SubtitleItem[]
     */
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
                : 'Subf2m';

            $season = null;
            $episode = null;
            if (preg_match('/S(\d{1,2})E(\d{1,3})/i', $releaseName, $sm)) {
                $season = (int) $sm[1];
                $episode = (int) $sm[2];
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ('subf2m_' . $idx);
            $detailFullUrl = self::BASE_URL . $href;

            $items[] = new SubtitleItem(
                id: 'subf2m_' . md5($detailFullUrl . '_' . $idx),
                provider: 'Subf2m',
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $translator !== '' ? $translator : 'Subf2m',
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: 'subf2m://' . $detailFullUrl,
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

    /**
     * Fetches matched series season pages concurrently via curl_multi.
     *
     * @return SubtitleItem[]
     */
    public function searchSeries(string $title, string $year = '', int $maxPages = 5, ?int $season = null): array
    {
        if (!$this->isConfigured() || trim($title) === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = 'subf2m_series_v3_' . md5(strtolower(trim($title)) . '|' . trim($year) . '|' . ($season ?? 'all'));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $res = $this->http->request(
            'GET',
            self::BASE_URL . '/subtitles/searchbytitle?query=' . urlencode(trim($title)) . '&l=',
            $this->browserHeaders(),
            null,
            6
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
        $links = $xpath->query('//div[contains(@class,"search-result")]//a[contains(@href,"/subtitles/")] | //ul//li//a[starts-with(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        $needle = mb_strtolower(trim($title));
        $paths = [];
        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            $text = mb_strtolower(trim((string) $a->textContent));
            if ($href === '' || str_contains($href, 'searchbytitle') || !preg_match('#^/subtitles/[a-z0-9-]+$#i', $href)) {
                continue;
            }
            if (!str_contains($text, $needle)) {
                continue;
            }
            $paths[$href] = true;
            if (count($paths) >= $maxPages) {
                break;
            }
        }
        if ($paths === []) {
            $this->cache?->set($cacheKey, [], 600);
            return [];
        }

        // Step 1: fetch the search-result pages. On Subf2m a series page first lists its
        // seasons; only the season pages list the actual subtitles.
        $batchReqs = [];
        foreach (array_keys($paths) as $path) {
            $pathSeason = $this->seasonFromSlug(substr($path, strlen('/subtitles/')));
            if ($season !== null && $pathSeason !== null && $pathSeason !== $season) {
                continue; // a different season's page, not needed
            }
            $batchReqs[$path] = [
                'url'     => self::BASE_URL . $path,
                'headers' => $this->browserHeaders(),
                'rawHtml' => true,
            ];
        }

        $items = [];
        $seen = [];
        $seasonPaths = []; // path => season number, discovered on series pages
        $collect = function (string $html, ?int $pageSeason) use (&$items, &$seen): int {
            $added = 0;
            foreach ($this->extractSubtitlesFromDetailHtml($html) as $item) {
                if (isset($seen[$item->id])) {
                    continue;
                }
                if ($item->season === null && $pageSeason !== null) {
                    $item->season = $pageSeason; // season pack: take the season from the page itself
                }
                $seen[$item->id] = true;
                $items[] = $item;
                $added++;
            }
            return $added;
        };

        foreach ($this->http->getJsonBatch($batchReqs, 7) as $path => $resp) {
            $html = is_array($resp) ? (string) ($resp['_html'] ?? '') : '';
            if ($html === '') {
                continue;
            }
            $slug = substr((string) $path, strlen('/subtitles/'));
            $pageSeason = $this->seasonFromSlug($slug);
            if ($collect($html, $pageSeason) > 0) {
                continue; // this page already is a season page with subtitles
            }
            // No subtitles here: it's the season list. Collect the season links.
            foreach ($this->extractSeasonLinks($html, $slug) as $sPath => $sNum) {
                if ($season !== null && $sNum !== $season) {
                    continue;
                }
                if (!isset($paths[$sPath])) {
                    $seasonPaths[$sPath] = $sNum;
                }
            }
        }

        // Step 2: fetch the season pages (the requested one, or all of them).
        if ($seasonPaths !== []) {
            asort($seasonPaths);
            $seasonPaths = array_slice($seasonPaths, 0, 20, true);
            $seasonReqs = [];
            foreach ($seasonPaths as $sPath => $sNum) {
                $seasonReqs[$sPath] = [
                    'url'     => self::BASE_URL . $sPath,
                    'headers' => $this->browserHeaders(),
                    'rawHtml' => true,
                ];
            }
            foreach ($this->http->getJsonBatch($seasonReqs, 7) as $sPath => $resp) {
                $html = is_array($resp) ? (string) ($resp['_html'] ?? '') : '';
                if ($html !== '') {
                    $collect($html, $seasonPaths[$sPath] ?? null);
                }
                if (count($items) >= 600) {
                    break;
                }
            }
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), $items === [] ? 900 : 86400);
        }
        return $items;
    }

    /**
     * Season number from a Subf2m slug such as "game-of-thrones-first-season",
     * "the-bear-3rd-season" or "show-season-4". Null when the slug is not a season page.
     */
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

    /**
     * Season links on a series page: "/subtitles/<series>-<nth>-season" => season number.
     *
     * @return array<string, int>
     */
    private function extractSeasonLinks(string $html, string $pageSlug): array
    {
        // Base slug of the series: strip any trailing "-<nth>-season" part and a trailing year.
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

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer'         => self::BASE_URL . '/',
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

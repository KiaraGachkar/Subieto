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

        // Cache the whole search -> detail resolution so repeated titles never
        // repeat the two sequential 5s HTTP calls.
        $cacheKey = 'subf2m_title_' . md5(strtolower(trim($title)) . '|' . trim($year));
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $cached);
            }
        }

        $headers = $this->browserHeaders();

        $searchUrl = self::BASE_URL . '/subtitles/searchbytitle?query=' . urlencode(trim($title)) . '&l=';
        $searchRes = $this->http->request('GET', $searchUrl, $headers, null, 5);
        if ($searchRes['status'] < 200 || $searchRes['status'] >= 300 || $searchRes['body'] === '') {
            return [];
        }

        $moviePath = $this->extractBestMoviePath($searchRes['body'], $title, $year);
        if ($moviePath === null) {
            return [];
        }

        $detailUrl = self::BASE_URL . $moviePath;
        $detailRes = $this->http->request('GET', $detailUrl, $headers, null, 5);
        if ($detailRes['status'] < 200 || $detailRes['status'] >= 300 || $detailRes['body'] === '') {
            return [];
        }

        $items = $this->extractSubtitlesFromDetailHtml($detailRes['body']);
        if ($this->cache !== null) {
            $ttl = $items === [] ? 3600 : 86400;
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), $ttl);
        }

        return $items;
    }

    public function resolveDownloadLink(string $detailUrl): ?string
    {
        $res = $this->http->request('GET', $detailUrl, $this->browserHeaders(), null, 10);
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
        $links = $xpath->query('//div[contains(@class,"search-result")]//a[contains(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            return null;
        }

        $firstHref = null;
        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_contains($href, 'searchbytitle')) {
                continue;
            }
            if ($firstHref === null) {
                $firstHref = $href;
            }
            $text = trim((string) $a->textContent);
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
        $rows = $xpath->query('//ul[contains(@class,"scrolllist")]//li');
        if (!$rows || $rows->length === 0) {
            return [];
        }

        $items = [];
        foreach ($rows as $idx => $li) {
            $linkNode = $xpath->query('.//a[contains(@href,"/subtitles/")]', $li);
            if (!$linkNode || $linkNode->length === 0) {
                continue;
            }
            $href = trim((string) $linkNode->item(0)?->getAttribute('href'));
            // Match /subtitles/{movie-slug}/{language}/{id}
            if (!preg_match('#^/subtitles/[^/]+/([^/]+)/\d+#i', $href, $lm)) {
                continue;
            }

            $lang = LanguageMap::resolve($lm[1]);

            $releaseNodes = $xpath->query('.//ul[contains(@class,"scrolllist")]//li | .//span[contains(@class,"name")]', $li);
            $releaseName = '';
            if ($releaseNodes && $releaseNodes->length > 0) {
                $releaseName = trim((string) $releaseNodes->item(0)?->textContent);
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
     * @return \App\DTOs\SubtitleItem[]
     */
    public function searchSeries(string $title, string $year = '', int $maxPages = 5): array
    {
        if (!$this->isConfigured() || trim($title) === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $cacheKey = 'subf2m_series_' . md5(strtolower(trim($title)) . '|' . trim($year));
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
            8
        );
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $res['body'], LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//div[contains(@class,"search-result")]//a[contains(@href,"/subtitles/")]');
        if (!$links || $links->length === 0) {
            return [];
        }

        $needle = mb_strtolower(trim($title));
        $paths = [];
        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            $text = mb_strtolower(trim((string) $a->textContent));
            if ($href === '' || str_contains($href, 'searchbytitle') || !str_contains($text, $needle)) {
                continue;
            }
            $paths[$href] = true;
            if (count($paths) >= $maxPages) {
                break;
            }
        }
        if ($paths === []) {
            return [];
        }

        $items = [];
        $seen = [];
        foreach (array_keys($paths) as $path) {
            $detail = $this->http->request('GET', self::BASE_URL . $path, $this->browserHeaders(), null, 8);
            if ($detail['status'] < 200 || $detail['status'] >= 300 || $detail['body'] === '') {
                continue;
            }
            foreach ($this->extractSubtitlesFromDetailHtml($detail['body']) as $item) {
                if (isset($seen[$item->id])) {
                    continue;
                }
                $seen[$item->id] = true;
                $items[] = $item;
            }
            if (count($items) >= 400) {
                break;
            }
        }

        if ($this->cache !== null) {
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $items), $items === [] ? 3600 : 86400);
        }
        return $items;
    }

    private function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
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

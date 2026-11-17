<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;

defined('APP_SECURE_BOOT') or exit;

final class FreeOpenSubtitlesProvider implements SubtitleProviderInterface
{
    private const BASE_URL     = 'https://opensubtitles-v3.strem.io/subtitles';
    private const CINEMETA_URL = 'https://v3-cinemeta.strem.io';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings,
        private readonly ?Cache $cache = null
    ) {
    }

    public function getName(): string
    {
        return 'OpenSubtitles (Free)';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('providers.free_opensub_enabled', '1') === '1';
    }

    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $type = $mediaType === 'tv' ? 'series' : 'movie';
        return [
            'url'     => self::BASE_URL . "/{$type}/" . urlencode(strtolower($imdbId)) . '.json',
            'headers' => $this->apiHeaders(),
        ];
    }

    public function parseSearchResponse(?array $data): array
    {
        if (!is_array($data) || empty($data['subtitles']) || !is_array($data['subtitles'])) {
            return [];
        }

        $items = [];
        foreach ($data['subtitles'] as $idx => $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $langRaw = trim((string) ($sub['lang'] ?? ''));
            if ($langRaw === '') {
                continue;
            }
            $lang = LanguageMap::resolve($langRaw);

            $dlUrl = trim((string) ($sub['url'] ?? ''));
            if ($dlUrl === '' || (!str_starts_with($dlUrl, 'https://') && !str_starts_with($dlUrl, 'http://'))) {
                continue;
            }
            if (str_starts_with($dlUrl, 'http://')) {
                $dlUrl = 'https://' . substr($dlUrl, 7);
            }

            $idStr = (string) ($sub['id'] ?? ('osfree_' . $idx));
            $releaseName = trim((string) ($sub['release'] ?? $sub['title'] ?? $sub['subtitleFileName'] ?? $sub['movieReleaseName'] ?? ''));
            if ($releaseName === '') {
                $releaseName = 'OpenSubtitles #' . $idStr;
            }
            $translator = trim((string) ($sub['releaseGroup'] ?? $sub['uploader'] ?? ''));
            if ($translator === '') {
                $translator = 'OpenSubtitles Archive';
            }

            $season  = isset($sub['season']) && is_numeric($sub['season']) ? (int) $sub['season'] : null;
            $episode = isset($sub['episode']) && is_numeric($sub['episode']) ? (int) $sub['episode'] : null;

            if ($season === null) {
                $vidId = (string) ($sub['video_id'] ?? $idStr);
                if (preg_match('/tt\d+:(\d{1,3}):(\d{1,4})/', $vidId, $sm)) {
                    $season  = (int) $sm[1];
                    $episode = (int) $sm[2];
                } elseif (preg_match('/S(\d{1,2})E(\d{1,3})/i', $releaseName, $sm)) {
                    $season  = (int) $sm[1];
                    $episode = (int) $sm[2];
                }
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ('sub_' . $idx);

            $items[] = new SubtitleItem(
                id: 'osfree_' . md5($dlUrl . '_' . $idx),
                provider: 'OpenSubtitles',
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $translator,
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: $dlUrl,
                fileName: substr($safeBase, 0, 120) . '.srt',
                season: $season,
                episode: $episode,
                hearingImpaired: str_contains(strtoupper($releaseName), 'HI')
            );
        }

        return $items;
    }

    public function searchByImdbId(string $imdbId, string $mediaType = 'movie'): array
    {
        $req = $this->buildSearchRequest($imdbId, $mediaType);
        if ($req === null) {
            return [];
        }
        $data = $this->http->getJson($req['url'], $req['headers'], 8);
        return $this->parseSearchResponse($data);
    }

    /**
     * Series subtitles are keyed by episode in this API (tt1234567:S:E).
     * Caches episode list and fetches episodes in a single parallel curl_multi batch.
     *
     * @return SubtitleItem[]
     */
    public function searchSeries(string $imdbId, int $maxEpisodes = 48): array
    {
        $cleanImdb = strtolower(trim($imdbId));
        if (!$this->isConfigured() || !preg_match('/^tt\d{5,10}$/', $cleanImdb)) {
            return [];
        }

        $epCacheKey = "cinemeta_eps_{$cleanImdb}";
        $episodeIds = $this->cache?->get($epCacheKey);

        if (!is_array($episodeIds)) {
            $meta = $this->http->getJson(self::CINEMETA_URL . '/meta/series/' . $cleanImdb . '.json', $this->apiHeaders(), 7);
            $videos = is_array($meta) ? ($meta['meta']['videos'] ?? []) : [];
            $episodeIds = [];
            if (is_array($videos)) {
                foreach ($videos as $video) {
                    $id = strtolower(trim((string) (is_array($video) ? ($video['id'] ?? '') : '')));
                    if (preg_match('/^tt\d+:\d{1,3}:\d{1,4}$/', $id)) {
                        $episodeIds[$id] = true;
                    }
                }
            }
            $episodeIds = array_keys($episodeIds);
            if ($this->cache !== null && $episodeIds !== []) {
                $this->cache->set($epCacheKey, $episodeIds, 86400 * 7);
            }
        }

        $episodeIds = array_slice($episodeIds, 0, $maxEpisodes);
        if ($episodeIds === []) {
            return [];
        }

        // Single parallel curl_multi batch for all episodes instead of sequential chunks
        $requests = [];
        foreach ($episodeIds as $episodeId) {
            $requests[$episodeId] = [
                'url'     => self::BASE_URL . '/series/' . rawurlencode((string) $episodeId) . '.json',
                'headers' => $this->apiHeaders(),
            ];
        }

        $items = [];
        foreach ($this->http->getJsonBatch($requests, 8) as $epKey => $data) {
            $parsed = $this->parseSearchResponse($data);
            foreach ($parsed as $item) {
                if ($item->season === null && preg_match('/^tt\d+:(\d+):(\d+)$/', (string) $epKey, $em)) {
                    $item->season  = (int) $em[1];
                    $item->episode = (int) $em[2];
                }
                $items[] = $item;
            }
        }

        return array_slice($items, 0, 600);
    }

    /**
     * @return array<string, string>
     */
    private function apiHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Origin'          => 'https://web.stremio.com',
            'Referer'         => 'https://web.stremio.com/',
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

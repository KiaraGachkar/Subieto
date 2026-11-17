<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;

defined('APP_SECURE_BOOT') or exit;

final class FreeOpenSubtitlesProvider implements SubtitleProviderInterface
{
    private const BASE_URL    = 'https://opensubtitles-v3.strem.io/subtitles';
    private const CINEMETA_URL = 'https://v3-cinemeta.strem.io';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings
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
            'headers' => [],
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
            if ($dlUrl === '' || !str_starts_with($dlUrl, 'https://')) {
                continue;
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
        $data = $this->http->getJson($req['url'], $req['headers']);
        return $this->parseSearchResponse($data);
    }

    /**
     * Series subtitles are keyed by episode in this API (tt1234567:S:E), so the
     * plain series ID returns nothing. Resolve the episode list first, then batch.
     *
     * @return \App\DTOs\SubtitleItem[]
     */
    public function searchSeries(string $imdbId, int $maxEpisodes = 36): array
    {
        if (!$this->isConfigured() || !preg_match('/^tt\d{5,10}$/i', $imdbId)) {
            return [];
        }

        $meta = $this->http->getJson(self::CINEMETA_URL . '/meta/series/' . strtolower($imdbId) . '.json', [], 10);
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
        $episodeIds = array_slice(array_keys($episodeIds), 0, $maxEpisodes);
        if ($episodeIds === []) {
            return [];
        }

        $items = [];
        foreach (array_chunk($episodeIds, 20) as $chunk) {
            $requests = [];
            foreach ($chunk as $episodeId) {
                $requests[$episodeId] = self::BASE_URL . '/series/' . rawurlencode($episodeId) . '.json';
            }
            foreach ($this->http->getJsonBatch($requests, 12) as $data) {
                foreach ($this->parseSearchResponse($data) as $item) {
                    $items[] = $item;
                }
            }
        }
        return array_slice($items, 0, 500);
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

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;

defined('APP_SECURE_BOOT') or exit;

final class OpenSubtitlesProvider implements SubtitleProviderInterface
{
    private const API_BASE = 'https://api.opensubtitles.com/api/v1';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings
    ) {
    }

    public function getName(): string
    {
        return 'OpenSubtitles';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('apis.opensubtitles_api_key', '')) !== '';
    }

    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $numericImdb = ltrim(strtolower($imdbId), 't');
        $apiKey = trim((string) $this->settings->get('apis.opensubtitles_api_key'));

        $query = http_build_query([
            'imdb_id'         => $numericImdb,
                        'order_by'        => 'download_count',
            'order_direction' => 'desc',
        ]);

        return [
            'url'     => self::API_BASE . '/subtitles?' . $query,
            'headers' => ['Api-Key' => $apiKey],
        ];
    }

    public function parseSearchResponse(?array $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data['data'] ?? [] as $row) {
            $attrs = $row['attributes'] ?? [];
            $files = $attrs['files'] ?? [];
            $firstFile = $files[0] ?? null;

            if (!is_array($firstFile) || empty($firstFile['file_id'])) {
                continue;
            }

            $fileId = (int) $firstFile['file_id'];
            $langCode = (string) ($attrs['language'] ?? 'en');
            $lang = LanguageMap::resolve($langCode);

            $releaseName = (string) ($attrs['release'] ?? $firstFile['file_name'] ?? 'OpenSubtitles Release');
            $uploader = (string) ($attrs['uploader']['name'] ?? 'OpenSubtitles');
            $featureDetails = $attrs['feature_details'] ?? [];

            $season = isset($featureDetails['season_number']) ? (int) $featureDetails['season_number'] : null;
            $episode = isset($featureDetails['episode_number']) ? (int) $featureDetails['episode_number'] : null;

            $items[] = new SubtitleItem(
                id: 'os_' . $fileId,
                provider: 'OpenSubtitles',
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $uploader !== '' ? $uploader : 'OpenSubtitles',
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: 'opensubtitles://file/' . $fileId,
                fileName: (string) ($firstFile['file_name'] ?? ('sub_' . $fileId . '.srt')),
                season: $season,
                episode: $episode,
                hearingImpaired: (bool) ($attrs['hearing_impaired'] ?? false)
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

    public function resolveDownloadLink(int $fileId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $apiKey = trim((string) $this->settings->get('apis.opensubtitles_api_key'));
        $res = $this->http->postJson(
            self::API_BASE . '/download',
            ['file_id' => $fileId],
            ['Api-Key' => $apiKey]
        );

        $link = $res['link'] ?? null;
        return is_string($link) && $link !== '' ? $link : null;
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

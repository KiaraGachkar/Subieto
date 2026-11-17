<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;

defined('APP_SECURE_BOOT') or exit;

final class SubdlProvider implements SubtitleProviderInterface
{
    private const API_ENDPOINT = 'https://api.subdl.com/api/v1/subtitles';
    private const DL_BASE      = 'https://dl.subdl.com';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings
    ) {
    }

    public function getName(): string
    {
        return 'SubDL';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('apis.subdl_api_key', '')) !== '';
    }

    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $apiKey = trim((string) $this->settings->get('apis.subdl_api_key'));
        $query = http_build_query([
            'api_key'       => $apiKey,
            'imdb_id'       => $imdbId,
            'type'          => $mediaType === 'tv' ? 'tv' : 'movie',
                        'subs_per_page' => 60,
            'languages'     => 'FA,EN,AR,TR,ES,FR',
        ]);

        return [
            'url'     => self::API_ENDPOINT . '?' . $query,
            'headers' => [],
        ];
    }

    public function parseSearchResponse(?array $data): array
    {
        if (!is_array($data) || ($data['status'] ?? false) !== true) {
            return [];
        }

        $items = [];
        foreach ($data['subtitles'] ?? [] as $index => $sub) {
            $rawUrl = (string) ($sub['url'] ?? '');
            if ($rawUrl === '') {
                continue;
            }

            $downloadUrl = str_starts_with($rawUrl, 'http') ? $rawUrl : self::DL_BASE . $rawUrl;
            $langRaw = (string) ($sub['lang'] ?? $sub['language'] ?? 'en');
            $lang = LanguageMap::resolve($langRaw);

            $releaseName = (string) ($sub['release_name'] ?? $sub['name'] ?? 'SubDL Release');
            $translator  = (string) ($sub['author'] ?? 'کاربر SubDL');
            $season      = isset($sub['season']) && is_numeric($sub['season']) ? (int) $sub['season'] : null;
            $episode     = isset($sub['episode']) && is_numeric($sub['episode']) ? (int) $sub['episode'] : null;

            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: 'subtitle';
            $ext = str_ends_with(strtolower($downloadUrl), '.zip') ? '.zip' : '.srt';

            $items[] = new SubtitleItem(
                id: 'subdl_' . md5($downloadUrl . '_' . $index),
                provider: 'SubDL',
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $translator !== '' ? $translator : 'کاربر SubDL',
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: $downloadUrl,
                fileName: $safeName . $ext,
                season: $season,
                episode: $episode,
                hearingImpaired: (bool) ($sub['hi'] ?? false)
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

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
    private const BASE_URL = 'https://opensubtitles-v3.strem.io/subtitles';

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
            $releaseName = trim((string) ($sub['release'] ?? $sub['title'] ?? ''));
            if ($releaseName === '') {
                $releaseName = 'OpenSubtitles #' . $idStr;
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
                translator: 'OpenSubtitles Archive',
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

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;
use DOMDocument;
use DOMXPath;

defined('APP_SECURE_BOOT') or exit;

final class YifySubtitlesProvider implements SubtitleProviderInterface
{
    private const BASE_URL = 'https://yifysubtitles.ch';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings
    ) {
    }

    public function getName(): string
    {
        return 'YIFY Subtitles';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('providers.yify_enabled', '1') === '1';
    }

    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array
    {
        if (!$this->isConfigured() || $mediaType === 'tv') {
            return null;
        }

        return [
            'url'     => self::BASE_URL . '/movie-imdb/' . urlencode(strtolower($imdbId)),
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            'rawHtml' => true,
        ];
    }

    public function parseSearchResponse(?array $data): array
    {
        $html = is_array($data) ? (string) ($data['_html'] ?? '') : '';
        if ($html === '') {
            return [];
        }

        return $this->parseHtml($html);
    }

    public function searchByImdbId(string $imdbId, string $mediaType = 'movie'): array
    {
        $req = $this->buildSearchRequest($imdbId, $mediaType);
        if ($req === null) {
            return [];
        }

        $res = $this->http->request('GET', $req['url'], $req['headers'], null, 10);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return [];
        }

        return $this->parseHtml($res['body']);
    }

    /**
     * @return SubtitleItem[]
     */
    private function parseHtml(string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table[contains(@class,"other-subs")]//tbody//tr');
        if ($rows === false) {
            return [];
        }

        $items = [];
        foreach ($rows as $idx => $tr) {
            $langNode = $xpath->query('.//td[contains(@class,"flag-cell")]//span[contains(@class,"sub-lang")]', $tr);
            $langText = $langNode && $langNode->length > 0 ? trim((string) $langNode->item(0)?->textContent) : '';
            if ($langText === '') {
                continue;
            }
            $lang = LanguageMap::resolve($langText);

            $linkNode = $xpath->query('.//a[contains(@href,"/subtitles/")]', $tr);
            if (!$linkNode || $linkNode->length === 0) {
                continue;
            }

            $href = (string) $linkNode->item(0)?->getAttribute('href');
            if ($href === '') {
                continue;
            }

            $zipPath = preg_replace('#^/subtitles/#', '/subtitle/', $href) . '.zip';
            $downloadUrl = self::BASE_URL . $zipPath;

            $rawTitle = trim((string) $linkNode->item(0)?->textContent);
            $releaseName = preg_replace('/^subtitle\s+/i', '', $rawTitle) ?: 'YIFY Release';
            $releaseName = trim(preg_replace('/\s+/', ' ', $releaseName) ?: $releaseName);

            $uploaderNode = $xpath->query('.//td[contains(@class,"uploader-cell")]', $tr);
            $translator = $uploaderNode && $uploaderNode->length > 0 ? trim((string) $uploaderNode->item(0)?->textContent) : 'YIFY';

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ('yify_' . $idx);

            $items[] = new SubtitleItem(
                id: 'yify_' . md5($downloadUrl . '_' . $idx),
                provider: 'YIFY',
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $translator !== '' ? $translator : 'YIFY',
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: $downloadUrl,
                fileName: substr($safeBase, 0, 120) . '.zip',
                season: null,
                episode: null,
                hearingImpaired: false
            );
        }

        return $items;
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

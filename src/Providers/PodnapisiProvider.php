<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SubtitleProviderInterface;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Services\LanguageMap;
use SimpleXMLElement;

defined('APP_SECURE_BOOT') or exit;

final class PodnapisiProvider implements SubtitleProviderInterface
{
    private const SEARCH_URL = 'https://www.podnapisi.net/subtitles/search/old';
    private const BASE_URL   = 'https://www.podnapisi.net';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings
    ) {
    }

    public function getName(): string
    {
        return 'Podnapisi';
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('providers.podnapisi_enabled', '1') === '1';
    }

    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $numericImdb = ltrim(strtolower($imdbId), 't');
        $query = http_build_query([
            'sXML' => 1,
            'sI'   => $numericImdb,
        ]);

        return [
            'url'     => self::SEARCH_URL . '?' . $query,
            'headers' => $this->browserHeaders(),
            'rawHtml' => true,
        ];
    }

    public function parseSearchResponse(?array $data): array
    {
        $xmlRaw = is_array($data) ? (string) ($data['_html'] ?? '') : '';
        if ($xmlRaw === '') {
            return [];
        }
        return $this->parseXml($xmlRaw);
    }

    public function searchByImdbId(string $imdbId, string $mediaType = 'movie'): array
    {
        $req = $this->buildSearchRequest($imdbId, $mediaType);
        if ($req === null) {
            return [];
        }

        $res = $this->http->request('GET', $req['url'], $req['headers'], null, 12);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return [];
        }

        return $this->parseXml($res['body']);
    }

    /**
     * @return SubtitleItem[]
     */
    private function parseXml(string $xmlRaw): array
    {
        if (!function_exists('simplexml_load_string')) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlRaw, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();

        if (!($xml instanceof SimpleXMLElement)) {
            return [];
        }

        $items = [];
        foreach ($xml->subtitle ?? [] as $sub) {
            $langId   = (int) ($sub->languageId ?? 0);
            $langName = trim((string) ($sub->languageName ?? $sub->language ?? ''));
            $lang     = LanguageMap::fromPodnapisiId($langId, $langName);

            $pid = trim((string) ($sub->pid ?? ''));
            if ($pid === '') {
                continue;
            }

            $releaseName = trim((string) ($sub->release ?? $sub->title ?? ('Podnapisi #' . $pid)));
            if ($releaseName === '') {
                $releaseName = 'Podnapisi #' . $pid;
            }

            $uploader = trim((string) ($sub->uploaderName ?? 'Podnapisi'));
            $season   = isset($sub->tvSeason) && (int) $sub->tvSeason > 0 ? (int) $sub->tvSeason : null;
            $episode  = isset($sub->tvEpisode) && (int) $sub->tvEpisode > 0 ? (int) $sub->tvEpisode : null;
            $flags    = strtolower((string) ($sub->flags ?? ''));

            $dlUrl    = self::BASE_URL . '/subtitles/' . urlencode($pid) . '/download';
            $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $releaseName) ?: ('pod_' . $pid);

            $items[] = new SubtitleItem(
                id: 'pod_' . $pid,
                provider: 'Podnapisi',
                languageCode: $lang['code'],
                languageLabel: $lang['label'],
                releaseName: $releaseName,
                translator: $uploader !== '' ? $uploader : 'Podnapisi',
                qualityTag: $this->detectQuality($releaseName),
                downloadUrl: $dlUrl,
                fileName: substr($safeBase, 0, 120) . '.zip',
                season: $season,
                episode: $episode,
                hearingImpaired: str_contains($flags, 'n')
            );
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept'          => 'application/xml,text/xml,application/xhtml+xml,*/*;q=0.8',
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

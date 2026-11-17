<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SubtitleProviderInterface;
use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;
use App\Providers\FreeOpenSubtitlesProvider;
use App\Providers\Subf2mProvider;

defined('APP_SECURE_BOOT') or exit;

final class SubtitleAggregatorService
{
    /**
     * @param SubtitleProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly Cache $cache,
        private readonly SettingsManager $settings,
        private readonly HttpClient $http = new HttpClient()
    ) {
    }

    /**
     * Fetches subtitles from all configured providers concurrently and sorts by admin priority languages.
     *
     * @return SubtitleItem[]
     */
    public function getSubtitlesForImdb(string $imdbId, string $mediaType = 'movie', string $title = '', string $year = ''): array
    {
        $imdbId = strtolower(trim($imdbId));
        $priorityRaw = strtolower(trim((string) $this->settings->get('providers.priority_languages', 'en,fa,ar,es,fr,tr')));
        $priorityHash = substr(md5($priorityRaw), 0, 8);

        $configParts = [];
        foreach ($this->providers as $provider) {
            $configParts[] = get_class($provider) . ':' . ($provider->isConfigured() ? '1' : '0');
        }
        foreach (['apis.tmdb_api_key', 'apis.subdl_api_key', 'apis.opensubtitles_api_key'] as $keyName) {
            $keyValue = trim((string) $this->settings->get($keyName, ''));
            $configParts[] = $keyName . ':' . ($keyValue !== '' ? substr(hash('sha256', $keyValue), 0, 8) : '0');
        }
        $configHash = substr(md5(implode('|', $configParts)), 0, 8);
        $cacheKey = "subs_v5_{$imdbId}_{$mediaType}_{$priorityHash}_{$configHash}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return self::fromRows($cached);
        }

        $lockAcquired = $this->cache->lock($cacheKey, 25);
        if (!$lockAcquired) {
            // Another worker is building this key. Back off and re-check the
            // cache a few times before proceeding, to avoid a thundering herd.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(75000 * ($attempt + 1)); // 75ms, 150ms, 225ms
                $retryCached = $this->cache->get($cacheKey);
                if (is_array($retryCached)) {
                    return self::fromRows($retryCached);
                }
                $lockAcquired = $this->cache->lock($cacheKey, 25);
                if ($lockAcquired) {
                    break;
                }
            }
        }

        try {
            $batchRequests = [];
            $activeProviders = [];

            foreach ($this->providers as $idx => $provider) {
                if (!$provider->isConfigured()) {
                    continue;
                }
                $req = $provider->buildSearchRequest($imdbId, $mediaType);
                if ($req !== null) {
                    $batchRequests[$idx] = $req;
                    $activeProviders[$idx] = $provider;
                }
            }

            $responses = $this->http->getJsonBatch($batchRequests, 10);

            $all = [];
            foreach ($activeProviders as $idx => $provider) {
                foreach ($provider->parseSearchResponse($responses[$idx] ?? null) as $item) {
                    $all[] = $item;
                }
            }

            // Series need special handling: the free OpenSubtitles endpoint is keyed by
            // episode, and Subf2m splits series into one page per season.
            if ($mediaType === 'tv') {
                foreach ($this->providers as $provider) {
                    if ($provider instanceof FreeOpenSubtitlesProvider && $provider->isConfigured()) {
                        foreach ($provider->searchSeries($imdbId) as $item) {
                            $all[] = $item;
                        }
                    }
                    if ($provider instanceof Subf2mProvider && $provider->isConfigured() && $title !== '') {
                        foreach ($provider->searchSeries($title, $year) as $item) {
                            $all[] = $item;
                        }
                    }
                }
            } elseif ($title !== '') {
                // Subf2m is title-based (not IMDb-based), so it cannot join the parallel
                // batch. Run it whenever configured so its releases always appear.
                foreach ($this->providers as $provider) {
                    if ($provider instanceof Subf2mProvider && $provider->isConfigured()) {
                        foreach ($provider->searchByTitle($title, $year) as $item) {
                            $all[] = $item;
                        }
                    }
                }
            }

            $priorityList = array_values(array_filter(array_map('trim', explode(',', $priorityRaw)), static fn(string $v): bool => $v !== ''));
            $priorityMap  = array_flip($priorityList);

            usort($all, static function (SubtitleItem $a, SubtitleItem $b) use ($priorityMap): int {
                if ($a->languageCode !== $b->languageCode) {
                    $rankA = $priorityMap[$a->languageCode] ?? 999;
                    $rankB = $priorityMap[$b->languageCode] ?? 999;
                    if ($rankA !== $rankB) {
                        return $rankA <=> $rankB;
                    }
                    return strcmp($a->languageLabel, $b->languageLabel);
                }
                if (($a->season ?? 0) !== ($b->season ?? 0)) {
                    return ($a->season ?? 0) <=> ($b->season ?? 0);
                }
                if (($a->episode ?? 0) !== ($b->episode ?? 0)) {
                    return ($a->episode ?? 0) <=> ($b->episode ?? 0);
                }
                return strcmp($a->provider, $b->provider);
            });

            $ttl = min(604800, max(0, (int) $this->settings->get('site.cache_ttl', 21600)));
            $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $all), !empty($all) ? $ttl : 300);

            return $all;
        } finally {
            $this->cache->unlock($cacheKey);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return SubtitleItem[]
     */
    private static function fromRows(array $rows): array
    {
        return array_map(static fn(array $row) => SubtitleItem::fromArray($row), $rows);
    }
}

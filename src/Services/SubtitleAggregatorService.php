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
use App\Providers\SubzoneProvider;
use App\Providers\SubkadeProvider;

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
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?SubtitleRepository $repository = null
    ) {
    }

    /**
     * Reads from the permanent MySQL subtitle archive first (<5ms).
     * On first visit, fetches from APIs + Subzone.ir (with Subf2m.co failover) and persists permanently.
     *
     * @return SubtitleItem[]
     */
    public function getSubtitlesForImdb(
        string $imdbId,
        string $mediaType = 'movie',
        string $title = '',
        string $year = '',
        ?int $season = null,
        bool $forceRefresh = false
    ): array {
        $imdbId = strtolower(trim($imdbId));
        $priorityRaw = strtolower(trim((string) $this->settings->get('providers.priority_languages', 'en,fa,ar,es,fr,tr')));

        // 1. Check Permanent MySQL Archive first (survives cache purges & setting changes)
        if (!$forceRefresh && $this->repository !== null) {
            $archived = $this->repository->getByImdbId($imdbId, $season);
            if ($archived !== []) {
                return $this->dedupeAndSort($archived, $priorityRaw);
            }
        }

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
        $seasonPart = $season !== null ? "_s{$season}" : '';
        $cacheKey = "subs_v12_{$imdbId}_{$mediaType}_{$priorityHash}_{$configHash}{$seasonPart}";

        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return self::fromRows($cached);
            }
        }

        $lockAcquired = $this->cache->lock($cacheKey, 25);
        if (!$lockAcquired) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(75000 * ($attempt + 1));
                if ($this->repository !== null) {
                    $retryDb = $this->repository->getByImdbId($imdbId, $season);
                    if ($retryDb !== []) {
                        return $this->dedupeAndSort($retryDb, $priorityRaw);
                    }
                }
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

            $responses = $this->http->getJsonBatch($batchRequests, 9);

            $all = [];
            foreach ($activeProviders as $idx => $provider) {
                foreach ($provider->parseSearchResponse($responses[$idx] ?? null) as $item) {
                    $all[] = $item;
                }
            }

            // Shared time budget so slow scrapers never exceed PHP/LiteSpeed limits
            @set_time_limit(60);
            $scrapeDeadline = microtime(true) + 35.0;
            $inBudget = static fn(): bool => microtime(true) < $scrapeDeadline;

            $subzone = null;
            $subf2m  = null;
            $subkade = null;
            $freeOs  = null;
            foreach ($this->providers as $provider) {
                if ($provider instanceof SubzoneProvider) {
                    $subzone = $provider;
                } elseif ($provider instanceof Subf2mProvider) {
                    $subf2m = $provider;
                } elseif ($provider instanceof SubkadeProvider) {
                    $subkade = $provider;
                } elseif ($provider instanceof FreeOpenSubtitlesProvider) {
                    $freeOs = $provider;
                }
            }

            if ($mediaType === 'tv') {
                if ($freeOs !== null && $freeOs->isConfigured()) {
                    foreach ($freeOs->searchSeries($imdbId, 180, $season) as $item) {
                        $all[] = $item;
                    }
                }
                // Primary mirror: Subzone.ir
                $szItems = [];
                if ($subzone !== null && $subzone->isConfigured() && $title !== '' && $inBudget()) {
                    $szItems = $subzone->searchSeries($title, $year, 25, $season);
                    foreach ($szItems as $item) {
                        $all[] = $item;
                    }
                }
                $szFaCount = count(array_filter($szItems, static fn(SubtitleItem $s): bool => $s->languageCode === 'fa'));
                // Failover to Subf2m.co if Subzone returned < 3 total OR < 2 Persian subtitles
                if ((count($szItems) < 3 || $szFaCount < 2) && $subf2m !== null && $subf2m->isConfigured() && $title !== '' && $inBudget()) {
                    foreach ($subf2m->searchSeries($title, $year, 25, $season) as $item) {
                        $all[] = $item;
                    }
                }
                if ($subkade !== null && $subkade->isConfigured() && $title !== '' && $inBudget()) {
                    foreach ($subkade->searchByTitle($title, $year, 'tv', $season) as $item) {
                        $all[] = $item;
                    }
                }
            } elseif ($title !== '') {
                // Primary mirror: Subzone.ir (always run on first fetch to populate complete multi-language + FA archive)
                $szItems = [];
                if ($subzone !== null && $subzone->isConfigured() && $inBudget()) {
                    $szItems = $subzone->searchByTitle($title, $year);
                    foreach ($szItems as $item) {
                        $all[] = $item;
                    }
                }
                $szFaCount = count(array_filter($szItems, static fn(SubtitleItem $s): bool => $s->languageCode === 'fa'));
                // Failover to Subf2m.co if Subzone returned < 3 total OR < 2 Persian subtitles
                if ((count($szItems) < 3 || $szFaCount < 2) && $subf2m !== null && $subf2m->isConfigured() && $inBudget()) {
                    foreach ($subf2m->searchByTitle($title, $year) as $item) {
                        $all[] = $item;
                    }
                }
                $faCount = count(array_filter($all, static fn(SubtitleItem $s): bool => $s->languageCode === 'fa'));
                if ($faCount < 3 && $subkade !== null && $subkade->isConfigured() && $inBudget()) {
                    foreach ($subkade->searchByTitle($title, $year, 'movie', null) as $item) {
                        $all[] = $item;
                    }
                }
            }

            $all = $this->dedupeAndSort($all, $priorityRaw);

            // Persist permanently in MySQL archive
            if ($this->repository !== null) {
                $this->repository->saveItems($imdbId, $all, false);
            }

            $ttl = min(604800, max(0, (int) $this->settings->get('site.cache_ttl', 21600)));
            if (!empty($all) && $ttl > 0) {
                $this->cache->set($cacheKey, array_map(static fn(SubtitleItem $s) => $s->toArray(), $all), $ttl);
            }

            return $all;
        } finally {
            $this->cache->unlock($cacheKey);
        }
    }

    /**
     * @param SubtitleItem[] $items
     * @return SubtitleItem[]
     */
    private function dedupeAndSort(array $items, string $priorityRaw): array
    {
        $deduped = [];
        $seenKeys = [];
        foreach ($items as $item) {
            $normRel = preg_replace('/[^a-z0-9]+/', '', strtolower($item->releaseName));
            $dKey = $item->languageCode . '|' . $normRel . '|' . ($item->season ?? 0) . '|' . ($item->episode ?? 0);
            if ($normRel !== '' && isset($seenKeys[$dKey])) {
                continue;
            }
            if ($normRel !== '') {
                $seenKeys[$dKey] = true;
            }
            $deduped[] = $item;
        }

        $priorityList = array_values(array_filter(array_map('trim', explode(',', $priorityRaw)), static fn(string $v): bool => $v !== ''));
        $priorityMap  = array_flip($priorityList);

        usort($deduped, static function (SubtitleItem $a, SubtitleItem $b) use ($priorityMap): int {
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

        return $deduped;
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

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\DTOs\MediaItem;

defined('APP_SECURE_BOOT') or exit;

/**
 * Fetches movie/TV metadata from TMDB (with Persian titles/overviews) when a key is provided,
 * and seamlessly falls back to the 100% free Stremio Cinemeta API (v3-cinemeta.strem.io)
 * when no TMDB key is set so the site works completely free with zero API keys.
 */
final class TmdbMetadataProvider
{
    private const BASE_URL     = 'https://api.themoviedb.org/3';
    private const IMG_BASE     = 'https://image.tmdb.org/t/p';
    private const CINEMETA_URL = 'https://v3-cinemeta.strem.io';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsManager $settings,
        private readonly Cache $cache
    ) {
    }

    public function isConfigured(): bool
    {
        return true; // Always operational via free Cinemeta fallback even without TMDB key
    }

    public function hasTmdbKey(): bool
    {
        return trim((string) $this->settings->get('apis.tmdb_api_key', '')) !== '';
    }

    /**
     * @return MediaItem[]
     */
    public function getTrending(string $type = 'movie', int $limit = 12): array
    {
        if (!$this->hasTmdbKey()) {
            return $this->getTrendingFromCinemeta($type, $limit);
        }

        $cacheKey = "tmdb_trending_v2_{$type}_{$limit}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return array_map(static fn(array $item) => MediaItem::fromArray($item), $cached);
        }

        $apiKey = trim((string) $this->settings->get('apis.tmdb_api_key'));
        $responses = $this->http->getJsonBatch([
            'en' => self::BASE_URL . "/trending/{$type}/week?api_key=" . urlencode($apiKey) . "&language=en-US",
            'fa' => self::BASE_URL . "/trending/{$type}/week?api_key=" . urlencode($apiKey) . "&language=fa-IR",
        ]);

        $faMap = $this->indexPersianRows($responses['fa']['results'] ?? []);
        $rawRows = array_slice($responses['en']['results'] ?? [], 0, $limit);
        $imdbMap = $this->resolveImdbIdsBatch($rawRows, $type);

        $results = [];
        foreach ($rawRows as $row) {
            $tmdbId = (int) ($row['id'] ?? 0);
            $imdbId = $imdbMap[$tmdbId] ?? '';
            if ($tmdbId === 0 || $imdbId === '') {
                continue;
            }
            $results[] = $this->mapRawItem($row, $type, $imdbId, $faMap[$tmdbId] ?? null);
        }

        $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
        if (!empty($results)) {
            $this->cache->set($cacheKey, array_map(static fn(MediaItem $m) => $m->toArray(), $results), $ttl);
        }

        return $results;
    }

    /**
     * @return MediaItem[]
     */
    public function searchMulti(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if (preg_match('/^tt\d{5,10}$/i', $query)) {
            $found = $this->findByImdbId(strtolower($query));
            return $found !== null ? [$found] : [];
        }

        if (!$this->hasTmdbKey()) {
            return $this->searchCinemeta($query, $limit);
        }

        $cacheKey = 'tmdb_search_v2_' . mb_strtolower($query) . "_{$limit}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return array_map(static fn(array $item) => MediaItem::fromArray($item), $cached);
        }

        $apiKey = trim((string) $this->settings->get('apis.tmdb_api_key'));
        $encodedQ = urlencode($query);

        $responses = $this->http->getJsonBatch([
            'en' => self::BASE_URL . "/search/multi?api_key=" . urlencode($apiKey) . "&language=en-US&include_adult=false&query={$encodedQ}",
            'fa' => self::BASE_URL . "/search/multi?api_key=" . urlencode($apiKey) . "&language=fa-IR&include_adult=false&query={$encodedQ}",
        ]);

        $faMap = $this->indexPersianRows($responses['fa']['results'] ?? []);
        $filteredRows = [];

        foreach ($responses['en']['results'] ?? [] as $row) {
            $mediaType = (string) ($row['media_type'] ?? '');
            if (!in_array($mediaType, ['movie', 'tv'], true) || empty($row['id'])) {
                continue;
            }
            $filteredRows[] = $row;
            if (count($filteredRows) >= $limit) {
                break;
            }
        }

        $imdbMap = $this->resolveImdbIdsBatch($filteredRows);
        $results = [];

        foreach ($filteredRows as $row) {
            $tmdbId = (int) $row['id'];
            $mediaType = (string) $row['media_type'];
            $imdbId = $imdbMap[$tmdbId] ?? '';
            if ($imdbId === '') {
                continue;
            }
            $results[] = $this->mapRawItem($row, $mediaType, $imdbId, $faMap[$tmdbId] ?? null);
        }

        $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
        if (!empty($results)) {
            $this->cache->set($cacheKey, array_map(static fn(MediaItem $m) => $m->toArray(), $results), min($ttl, 7200));
        }

        return $results;
    }

    public function findByImdbId(string $imdbId): ?MediaItem
    {
        $imdbId = strtolower(trim($imdbId));
        if (!$this->hasTmdbKey()) {
            return $this->findByImdbCinemeta($imdbId);
        }

        $cacheKey = "tmdb_imdb_v2_{$imdbId}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return MediaItem::fromArray($cached);
        }

        $apiKey = trim((string) $this->settings->get('apis.tmdb_api_key'));
        $encodedImdb = urlencode($imdbId);

        $responses = $this->http->getJsonBatch([
            'en' => self::BASE_URL . "/find/{$encodedImdb}?api_key=" . urlencode($apiKey) . "&external_source=imdb_id&language=en-US",
            'fa' => self::BASE_URL . "/find/{$encodedImdb}?api_key=" . urlencode($apiKey) . "&external_source=imdb_id&language=fa-IR",
        ]);

        $enData = $responses['en'] ?? [];
        $faData = $responses['fa'] ?? [];

        $movie = $enData['movie_results'][0] ?? null;
        $tv    = $enData['tv_results'][0] ?? null;

        if (is_array($movie)) {
            $faRow = is_array($faData['movie_results'][0] ?? null) ? $faData['movie_results'][0] : null;
            $item = $this->mapRawItem($movie, 'movie', $imdbId, $faRow);
        } elseif (is_array($tv)) {
            $faRow = is_array($faData['tv_results'][0] ?? null) ? $faData['tv_results'][0] : null;
            $item = $this->mapRawItem($tv, 'tv', $imdbId, $faRow);
        } else {
            return null;
        }

        $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
        $this->cache->set($cacheKey, $item->toArray(), $ttl);

        return $item;
    }

    /**
     * @return MediaItem[]
     */
    public function getSimilar(int $tmdbId, string $mediaType = 'movie', int $limit = 6): array
    {
        if (!$this->hasTmdbKey() || $tmdbId <= 0) {
            return $this->getTrendingFromCinemeta($mediaType, $limit);
        }

        $type = $mediaType === 'tv' ? 'tv' : 'movie';
        $cacheKey = "tmdb_similar_v2_{$type}_{$tmdbId}_{$limit}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return array_map(static fn(array $item) => MediaItem::fromArray($item), $cached);
        }

        $apiKey = trim((string) $this->settings->get('apis.tmdb_api_key'));
        $responses = $this->http->getJsonBatch([
            'en' => self::BASE_URL . "/{$type}/{$tmdbId}/recommendations?api_key=" . urlencode($apiKey) . "&language=en-US",
            'fa' => self::BASE_URL . "/{$type}/{$tmdbId}/recommendations?api_key=" . urlencode($apiKey) . "&language=fa-IR",
        ]);

        $rawRows = array_slice($responses['en']['results'] ?? [], 0, $limit);
        if (empty($rawRows)) {
            return [];
        }

        $faMap = $this->indexPersianRows($responses['fa']['results'] ?? []);
        $imdbMap = $this->resolveImdbIdsBatch($rawRows, $type);

        $results = [];
        foreach ($rawRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $imdbId = $imdbMap[$id] ?? '';
            if ($id === 0 || $imdbId === '') {
                continue;
            }
            $results[] = $this->mapRawItem($row, $type, $imdbId, $faMap[$id] ?? null);
        }

        $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
        if (!empty($results)) {
            $this->cache->set($cacheKey, array_map(static fn(MediaItem $m) => $m->toArray(), $results), $ttl);
        }

        return $results;
    }

    // --- Free Cinemeta Fallback Methods (Zero API Key) ---

    /**
     * @return MediaItem[]
     */
    private function getTrendingFromCinemeta(string $type, int $limit): array
    {
        $cType = $type === 'tv' ? 'series' : 'movie';
        $cacheKey = "cinemeta_top_{$cType}_{$limit}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return array_map(static fn(array $item) => MediaItem::fromArray($item), $cached);
        }

        $data = $this->http->getJson(self::CINEMETA_URL . "/catalog/{$cType}/top.json");
        $metas = array_slice($data['metas'] ?? [], 0, $limit);
        $results = [];
        foreach ($metas as $meta) {
            $item = $this->mapCinemetaItem($meta, $type);
            if ($item !== null) {
                $results[] = $item;
            }
        }

        $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
        if (!empty($results)) {
            $this->cache->set($cacheKey, array_map(static fn(MediaItem $m) => $m->toArray(), $results), $ttl);
        }
        return $results;
    }

    /**
     * @return MediaItem[]
     */
    private function searchCinemeta(string $query, int $limit): array
    {
        $cacheKey = 'cinemeta_search_' . mb_strtolower($query) . "_{$limit}";
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return array_map(static fn(array $item) => MediaItem::fromArray($item), $cached);
        }

        $enc = rawurlencode($query);
        $responses = $this->http->getJsonBatch([
            'movie'  => self::CINEMETA_URL . "/catalog/movie/top/search={$enc}.json",
            'series' => self::CINEMETA_URL . "/catalog/series/top/search={$enc}.json",
        ]);

        $results = [];
        foreach (['movie' => 'movie', 'series' => 'tv'] as $cType => $mType) {
            foreach ($responses[$cType]['metas'] ?? [] as $meta) {
                $item = $this->mapCinemetaItem($meta, $mType);
                if ($item !== null) {
                    $results[] = $item;
                }
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
        if (!empty($results)) {
            $this->cache->set($cacheKey, array_map(static fn(MediaItem $m) => $m->toArray(), $results), min($ttl, 7200));
        }
        return $results;
    }

    private function findByImdbCinemeta(string $imdbId): ?MediaItem
    {
        $cacheKey = "cinemeta_imdb_{$imdbId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached === ['_miss' => true]) {
            return null;
        }
        if (is_array($cached)) {
            return MediaItem::fromArray($cached);
        }

        $responses = $this->http->getJsonBatch([
            'movie'  => self::CINEMETA_URL . "/meta/movie/{$imdbId}.json",
            'series' => self::CINEMETA_URL . "/meta/series/{$imdbId}.json",
        ]);

        $item = null;
        if (is_array($responses['movie']['meta'] ?? null) && !empty($responses['movie']['meta']['name'])) {
            $item = $this->mapCinemetaItem($responses['movie']['meta'], 'movie');
        } elseif (is_array($responses['series']['meta'] ?? null) && !empty($responses['series']['meta']['name'])) {
            $item = $this->mapCinemetaItem($responses['series']['meta'], 'tv');
        }

        if ($item !== null) {
            $ttl = (int) $this->settings->get('site.cache_ttl', 21600);
            $this->cache->set($cacheKey, $item->toArray(), $ttl);
        } else {
            $this->cache->set($cacheKey, ['_miss' => true], 600);
        }
        return $item;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function mapCinemetaItem(array $meta, string $mediaType): ?MediaItem
    {
        $imdbId = strtolower(trim((string) ($meta['imdb_id'] ?? $meta['id'] ?? '')));
        if (!preg_match('/^tt\d{5,10}$/', $imdbId)) {
            return null;
        }
        $title = trim((string) ($meta['name'] ?? 'Untitled'));
        $yearRaw = (string) ($meta['releaseInfo'] ?? $meta['year'] ?? '');
        $year = preg_match('/\b(19\d{2}|20\d{2})\b/', $yearRaw, $ym) ? $ym[1] : '';
        $poster = trim((string) ($meta['poster'] ?? ''));
        if ($poster === '') {
            $poster = 'https://placehold.co/500x750/1e293b/94a3b8?text=No+Poster';
        }

        return new MediaItem(
            tmdbId: 0,
            imdbId: $imdbId,
            title: $title,
            originalTitle: $title,
            mediaType: $mediaType,
            year: $year,
            posterUrl: $poster,
            backdropUrl: (string) ($meta['background'] ?? ''),
            overview: (string) ($meta['description'] ?? ''),
            voteAverage: round((float) ($meta['imdbRating'] ?? 0.0), 1),
            voteCount: 100,
            slug: $this->slugify($title),
            persianTitle: '',
            persianOverview: ''
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexPersianRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $row;
            }
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function resolveImdbIdsBatch(array $rows, ?string $forcedType = null): array
    {
        $apiKey = trim((string) $this->settings->get('apis.tmdb_api_key'));
        $resolved = [];
        $urlsToFetch = [];
        $cacheKeys   = [];

        foreach ($rows as $row) {
            $tmdbId = (int) ($row['id'] ?? 0);
            $type   = $forcedType ?? (string) ($row['media_type'] ?? 'movie');
            if ($tmdbId === 0) {
                continue;
            }
            $cacheKeys["{$type}:{$tmdbId}"] = "tmdb_ext_{$type}_{$tmdbId}";
        }

        // One batched cache lookup instead of one SELECT per row.
        $cachedValues = $this->cache->getMany(array_values($cacheKeys));
        foreach ($cacheKeys as $compositeKey => $cacheKey) {
            [$type, $idStr] = explode(':', (string) $compositeKey, 2);
            $cached = $cachedValues[$cacheKey] ?? null;
            if (is_string($cached) && $cached !== '') {
                $resolved[(int) $idStr] = $cached;
            } else {
                $urlsToFetch[$compositeKey] = self::BASE_URL . "/{$type}/{$idStr}/external_ids?api_key=" . urlencode($apiKey);
            }
        }

        if (!empty($urlsToFetch)) {
            $batchResponses = $this->http->getJsonBatch($urlsToFetch);
            $toCache = [];
            foreach ($batchResponses as $compositeKey => $json) {
                [$type, $idStr] = explode(':', (string) $compositeKey, 2);
                $tmdbId = (int) $idStr;
                $imdbId = trim((string) ($json['imdb_id'] ?? ''));
                if ($imdbId !== '') {
                    $resolved[$tmdbId] = $imdbId;
                    $toCache["tmdb_ext_{$type}_{$tmdbId}"] = $imdbId;
                }
            }
            if ($toCache !== []) {
                $this->cache->setMany($toCache, 86400 * 7);
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $faRow
     */
    private function mapRawItem(array $row, string $mediaType, string $imdbId, ?array $faRow = null): MediaItem
    {
        $title = (string) ($row['title'] ?? $row['name'] ?? 'Untitled');
        $originalTitle = (string) ($row['original_title'] ?? $row['original_name'] ?? $title);
        $releaseDate = (string) ($row['release_date'] ?? $row['first_air_date'] ?? '');
        $year = strlen($releaseDate) >= 4 ? substr($releaseDate, 0, 4) : '';

        $faTitleRaw = is_array($faRow) ? trim((string) ($faRow['title'] ?? $faRow['name'] ?? '')) : '';
        $persianTitle = ($faTitleRaw !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $faTitleRaw))
            ? $faTitleRaw
            : '';

        $faOverviewRaw = is_array($faRow) ? trim((string) ($faRow['overview'] ?? '')) : '';
        $persianOverview = ($faOverviewRaw !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $faOverviewRaw))
            ? $faOverviewRaw
            : '';

        $posterPath = $row['poster_path'] ?? null;
        $backdropPath = $row['backdrop_path'] ?? null;

        return new MediaItem(
            tmdbId: (int) ($row['id'] ?? 0),
            imdbId: $imdbId,
            title: $title,
            originalTitle: $originalTitle,
            mediaType: $mediaType,
            year: $year,
            posterUrl: is_string($posterPath) && $posterPath !== ''
                ? self::IMG_BASE . '/w500' . $posterPath
                : 'https://placehold.co/500x750/1e293b/94a3b8?text=No+Poster',
            backdropUrl: is_string($backdropPath) && $backdropPath !== ''
                ? self::IMG_BASE . '/w1280' . $backdropPath
                : '',
            overview: (string) ($row['overview'] ?? ''),
            voteAverage: round((float) ($row['vote_average'] ?? 0.0), 1),
            voteCount: max(1, (int) ($row['vote_count'] ?? 1)),
            slug: $this->slugify($title),
            persianTitle: $persianTitle,
            persianOverview: $persianOverview
        );
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'title';
        $slug = trim($slug, '-');
        if (preg_match('/^season-\d+$/', $slug)) {
            $slug .= '-movie';
        }
        return $slug !== '' ? $slug : 'title';
    }
}

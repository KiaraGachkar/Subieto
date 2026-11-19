<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\RateLimiter;
use App\DTOs\MediaItem;
use App\Providers\TmdbMetadataProvider;
use App\Services\LocaleService;
use App\Services\SeoHelper;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class SearchController extends BaseController
{
    public function search(): void
    {
        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('search', 60, 60)) {
            http_response_code(429);
            header('Retry-After: 60');
            echo 'تعداد درخواست‌های جستجو بیش از حد مجاز است. لطفاً کمی صبر کنید.';
            return;
        }

        $this->metrics->recordPageView();

        $rawQ  = $_GET['q'] ?? '';
        $query = is_string($rawQ) ? mb_substr(trim($rawQ), 0, 120) : '';
        $results = $query !== '' ? $this->hybridSearch($query, 18) : [];

        foreach ($results as $item) {
            $this->metrics->indexMediaForSeo($item);
        }

        $locale    = LocaleService::resolvePublicLocale();
        $isFa      = $locale === 'fa';
        $pageTitle = $query !== ''
            ? ($isFa ? "جستجوی زیرنویس: {$query}" : "Subtitle search: {$query}")
            : ($isFa ? 'جستجوی زیرنویس فیلم و سریال' : 'Search movie and TV subtitles');

        $ogImage = '';
        foreach ($results as $item) {
            if ($item->posterUrl !== '') {
                $ogImage = $item->posterUrl;
                break;
            }
        }

        $searchCachePath = $query !== '' ? '/search?' . md5(mb_strtolower($query)) : '/search';
        $this->renderAndCachePage($searchCachePath, 'search', [
            'pageTitle'    => $pageTitle,
            'locale'       => $locale,
            'ogImage'      => $ogImage !== '' ? $ogImage : null,
            'robotsMeta'   => 'noindex, follow',
            'canonicalUrl' => SeoHelper::getBaseUrl() . '/search',
            'query'        => $query,
            'results'      => $results,
        ], 600);
    }

    /**
     * Combines ultra-fast local indexed_media search (supports Persian & English titles + year extraction)
     * with live TMDB / Cinemeta API results and ranks exact/prefix matches first.
     *
     * @return MediaItem[]
     */
    private function hybridSearch(string $rawQuery, int $limit): array
    {
        $cleanQuery = trim($rawQuery);
        $yearFilter = '';
        $titleQuery = $cleanQuery;

        // Extract optional trailing/embedded 4-digit year (e.g., "Dune 2024" or "Inception (2010)")
        if (preg_match('/^(.*?)\s*\(?((?:19|20)\d{2})\)?$/u', $cleanQuery, $ym) && trim($ym[1]) !== '') {
            $titleQuery = trim($ym[1]);
            $yearFilter = $ym[2];
        }

        $merged = [];

        // 1. Instant local DB lookup (finds previously viewed Persian & English titles in <2ms)
        try {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $titleQuery) . '%';
            $sql  = "SELECT `imdb_id`, `slug`, `title`, `persian_title`, `media_type`, `year`, `poster_url`
                     FROM `{$this->prefix}indexed_media`
                     WHERE (`title` LIKE :q1 OR `persian_title` LIKE :q2 OR `imdb_id` = :exact)";
            $params = [
                ':q1'    => $like,
                ':q2'    => $like,
                ':exact' => strtolower($cleanQuery),
            ];
            if ($yearFilter !== '') {
                $sql .= " AND `year` = :yr";
                $params[':yr'] = $yearFilter;
            }
            $sql .= " ORDER BY `updated_at` DESC LIMIT :lim";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (string) ($row['imdb_id'] ?? '');
                if ($id !== '') {
                    $merged[$id] = new MediaItem(
                        tmdbId: 0,
                        imdbId: $id,
                        title: (string) ($row['title'] ?? ''),
                        originalTitle: (string) ($row['title'] ?? ''),
                        mediaType: (string) ($row['media_type'] ?? 'movie'),
                        year: (string) ($row['year'] ?? ''),
                        posterUrl: (string) ($row['poster_url'] ?? ''),
                        backdropUrl: '',
                        overview: '',
                        voteAverage: 0.0,
                        voteCount: 0,
                        slug: (string) ($row['slug'] ?? 'title'),
                        persianTitle: (string) ($row['persian_title'] ?? ''),
                        persianOverview: ''
                    );
                }
            }
        } catch (Throwable) {
            // Continue gracefully to live API
        }

        // 2. Live TMDB / Cinemeta lookup
        $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        $apiResults = $tmdb->searchMulti($titleQuery, $limit);
        if (empty($apiResults) && $titleQuery !== $cleanQuery) {
            $apiResults = $tmdb->searchMulti($cleanQuery, $limit);
        }

        foreach ($apiResults as $item) {
            if ($yearFilter !== '' && $item->year !== '' && $item->year !== $yearFilter) {
                continue;
            }
            $merged[$item->imdbId] = $item;
        }

        // Fallback if year filter was too strict
        if (empty($merged) && !empty($apiResults)) {
            foreach ($apiResults as $item) {
                $merged[$item->imdbId] = $item;
            }
        }

        $list = array_values($merged);

        // Batch-fill missing poster URLs from cache/Cinemeta in parallel (max 4 items) instead of sequential N+1 HTTP calls
        $missingPosters = [];
        foreach ($list as $idx => $item) {
            if ($item->posterUrl === '' && preg_match('/^tt\d{5,10}$/', $item->imdbId)) {
                $cType = $item->mediaType === 'tv' ? 'series' : 'movie';
                $cachedMeta = $this->cache->get("cinemeta_imdb_{$item->imdbId}");
                if (is_array($cachedMeta) && !empty($cachedMeta['posterUrl'])) {
                    $item->posterUrl = (string) $cachedMeta['posterUrl'];
                } elseif (count($missingPosters) < 12) {
                    $missingPosters[$idx] = "https://v3-cinemeta.strem.io/meta/{$cType}/{$item->imdbId}.json";
                }
            }
        }
        if ($missingPosters !== []) {
            $batchMeta = $this->http->getJsonBatch($missingPosters, 4);
            foreach ($batchMeta as $idx => $metaJson) {
                $poster = trim((string) ($metaJson['meta']['poster'] ?? ''));
                if ($poster !== '' && isset($list[$idx])) {
                    $list[$idx]->posterUrl = $poster;
                }
            }
        }

        // Last-resort poster for local-DB hits that have no cached metadata (any metadata mode):
        // try the TMDB-mode cache entry, then the deterministic IMDb-keyed poster URL.
        foreach ($list as $item) {
            if ($item->posterUrl !== '' || !preg_match('/^tt\d{5,10}$/', $item->imdbId)) {
                continue;
            }
            $tmdbMeta = $this->cache->get("tmdb_imdb_v2_{$item->imdbId}");
            $item->posterUrl = (is_array($tmdbMeta) && !empty($tmdbMeta['posterUrl']))
                ? (string) $tmdbMeta['posterUrl']
                : 'https://live.metahub.space/poster/medium/' . $item->imdbId . '/img';
        }

        $needle = mb_strtolower($titleQuery);

        usort($list, static function (MediaItem $a, MediaItem $b) use ($needle, $yearFilter): int {
            $score = static function (MediaItem $m) use ($needle, $yearFilter): int {
                $t  = mb_strtolower($m->title);
                $fa = mb_strtolower($m->persianTitle);
                $s  = 0;
                if ($t === $needle || $fa === $needle) {
                    $s += 100;
                } elseif (str_starts_with($t, $needle) || ($fa !== '' && str_starts_with($fa, $needle))) {
                    $s += 60;
                } elseif (str_contains($t, $needle) || ($fa !== '' && str_contains($fa, $needle))) {
                    $s += 30;
                }
                if ($yearFilter !== '' && $m->year === $yearFilter) {
                    $s += 25;
                }
                if ($m->posterUrl !== '') {
                    $s += 5;
                }
                $s += (int) round($m->voteAverage);
                return $s;
            };
            return $score($b) <=> $score($a);
        });

        return array_slice($list, 0, $limit);
    }


    /**
     * Ultra-fast JSON autocomplete endpoint for the header search bar.
     * Prioritizes APCu/DB cache and local indexed_media (<5ms) before falling back to live API.
     */
    public function suggest(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');

        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('suggest', 120, 60)) {
            http_response_code(429);
            echo json_encode(['items' => []]);
            return;
        }

        $rawQ  = $_GET['q'] ?? '';
        $query = is_string($rawQ) ? mb_substr(trim($rawQ), 0, 80) : '';
        if (mb_strlen($query) < 2) {
            echo json_encode(['items' => []]);
            return;
        }

        $cacheKey = 'suggest_v1_' . md5(mb_strtolower($query));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            echo json_encode(['items' => $cached], JSON_UNESCAPED_UNICODE);
            return;
        }

        $items = [];
        $seen  = [];

        // 1. Instant local DB lookup (<2ms)
        try {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
            $stmt = $this->db->prepare(
                "SELECT `imdb_id`, `slug`, `title`, `media_type`, `year`, `poster_url`
                 FROM `{$this->prefix}indexed_media`
                 WHERE `title` LIKE :q1 OR `persian_title` LIKE :q2 OR `imdb_id` = :exact
                 ORDER BY (`title` LIKE :prefix) DESC, `updated_at` DESC
                 LIMIT 6"
            );
            $stmt->execute([
                ':q1'     => $like,
                ':q2'     => $like,
                ':exact'  => strtolower($query),
                ':prefix' => str_replace(['%', '_'], ['\%', '\_'], $query) . '%',
            ]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (string) ($row['imdb_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $poster = trim((string) ($row['poster_url'] ?? ''));
                if ($poster === '') {
                    $poster = 'https://live.metahub.space/poster/medium/' . $id . '/img';
                }
                $items[] = [
                    'id'     => $id,
                    'title'  => (string) ($row['title'] ?? ''),
                    'year'   => (string) ($row['year'] ?? ''),
                    'type'   => (string) ($row['media_type'] ?? 'movie'),
                    'poster' => $poster,
                    'url'    => '/subtitles/' . $id . '/' . (string) ($row['slug'] ?? 'title'),
                ];
            }
        } catch (Throwable) {
            // Fall through to live API
        }

        // 2. Only hit live Cinemeta/TMDB if local DB returned fewer than 5 results
        if (count($items) < 5) {
            $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
            foreach ($tmdb->searchMulti($query, 6) as $m) {
                if (isset($seen[$m->imdbId])) {
                    continue;
                }
                $seen[$m->imdbId] = true;
                $items[] = [
                    'id'     => $m->imdbId,
                    'title'  => $m->title,
                    'year'   => $m->year,
                    'type'   => $m->mediaType,
                    'poster' => $m->posterUrl !== '' ? $m->posterUrl : ('https://live.metahub.space/poster/medium/' . $m->imdbId . '/img'),
                    'url'    => '/subtitles/' . $m->imdbId . '/' . $m->slug,
                ];
                if (count($items) >= 6) {
                    break;
                }
            }
        }

        $items = array_slice($items, 0, 6);
        if ($items !== []) {
            $this->cache->set($cacheKey, $items, 1800);
        }

        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    }
}

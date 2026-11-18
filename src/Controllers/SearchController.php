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

        $this->renderAndCachePage('/search?' . md5(http_build_query($_GET)), 'search', [
            'pageTitle'    => $pageTitle,
            'locale'       => $locale,
            'ogImage'      => $ogImage !== '' ? $ogImage : null,
            'robotsMeta'   => 'noindex, follow',
            'canonicalUrl' => SeoHelper::getBaseUrl() . '/search',
            'query'        => $query,
            'results'      => $results,
        ], 600);
    }

    private function hybridSearch(string $rawQuery, int $limit): array
    {
        $cleanQuery = trim($rawQuery);
        $yearFilter = '';
        $titleQuery = $cleanQuery;

        if (preg_match('/^(.*?)\s*\(?((?:19|20)\d{2})\)?$/u', $cleanQuery, $ym) && trim($ym[1]) !== '') {
            $titleQuery = trim($ym[1]);
            $yearFilter = $ym[2];
        }

        $merged = [];

        try {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $titleQuery) . '%';
            $sql  = "SELECT `imdb_id`, `slug`, `title`, `persian_title`, `media_type`, `year`
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
                        posterUrl: '',
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
        }

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

        if (empty($merged) && !empty($apiResults)) {
            foreach ($apiResults as $item) {
                $merged[$item->imdbId] = $item;
            }
        }

        $list = array_values($merged);

        $missingPosters = [];
        foreach ($list as $idx => $item) {
            if ($item->posterUrl === '' && preg_match('/^tt\d{5,10}$/', $item->imdbId)) {
                $cType = $item->mediaType === 'tv' ? 'series' : 'movie';
                $cachedMeta = $this->cache->get("cinemeta_imdb_{$item->imdbId}");
                if (is_array($cachedMeta) && !empty($cachedMeta['posterUrl'])) {
                    $item->posterUrl = (string) $cachedMeta['posterUrl'];
                } elseif (count($missingPosters) < 4) {
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

        foreach ($list as $item) {
            if ($item->posterUrl !== '' || !preg_match('/^tt\d{5,10}$/', $item->imdbId)) {
                continue;
            }
            $tmdbMeta = $this->cache->get("tmdb_imdb_v2_{$item->imdbId}");
            $item->posterUrl = (is_array($tmdbMeta) && !empty($tmdbMeta['posterUrl']))
                ? (string) $tmdbMeta['posterUrl']
                : 'https://images.metahub.space/poster/small/' . $item->imdbId . '/img';
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
}
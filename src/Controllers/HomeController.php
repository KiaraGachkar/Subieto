<?php

declare(strict_types=1);

namespace App\Controllers;

use App\DTOs\MediaItem;
use App\Providers\TmdbMetadataProvider;
use App\Services\LocaleService;
use App\Services\SeoHelper;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class HomeController extends BaseController
{
    public function index(): void
    {
        $this->metrics->recordPageView();

        $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        $trendingMovies = $tmdb->getTrending('movie', 12);
        $trendingSeries = $tmdb->getTrending('tv', 12);

        if ($this->cache->get('home_seo_indexed_lock') === null && (!empty($trendingMovies) || !empty($trendingSeries))) {
            foreach (array_merge($trendingMovies, $trendingSeries) as $item) {
                $this->metrics->indexMediaForSeo($item);
            }
            $this->cache->set('home_seo_indexed_lock', true, 3600);
        }

        $latestSubtitles = [];
        try {
            $stmt = $this->db->query(
                "SELECT `imdb_id`, `slug`, `title`, `media_type`, `year`, `langs_json`
                 FROM `{$this->prefix}indexed_media`
                 ORDER BY `updated_at` DESC LIMIT 12"
            );
            $latestSubtitles = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            $latestSubtitles = [];
        }

        $topPicks = [];
        try {
            $stmt = $this->db->query(
                "SELECT `imdb_id`, COUNT(*) AS `dl_count`
                 FROM `{$this->prefix}download_stats`
                 WHERE `imdb_id` <> ''
                 GROUP BY `imdb_id`
                 ORDER BY `dl_count` DESC LIMIT 5"
            );
            $topRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            $topRows = [];
        }

        $trendingById = [];
        foreach (array_merge($trendingMovies, $trendingSeries) as $candidate) {
            $trendingById[strtolower($candidate->imdbId)] = $candidate;
        }

        $seenTop = [];
        foreach ($topRows as $row) {
            $id = strtolower(trim((string) ($row['imdb_id'] ?? '')));
            if ($id === '' || isset($seenTop[$id])) {
                continue;
            }
            if (isset($trendingById[$id])) {
                $seenTop[$id] = true;
                $topPicks[] = $trendingById[$id];
                continue;
            }
            try {
                $metaStmt = $this->db->prepare(
                    "SELECT `imdb_id`, `slug`, `title`, `media_type`, `year`
                     FROM `{$this->prefix}indexed_media` WHERE `imdb_id` = :id LIMIT 1"
                );
                $metaStmt->execute([':id' => $id]);
                $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                $meta = false;
            }
            if (is_array($meta) && (string) ($meta['title'] ?? '') !== '') {
                $seenTop[$id] = true;
                $topPicks[] = new MediaItem(
                    tmdbId: 0,
                    imdbId: (string) $meta['imdb_id'],
                    title: (string) $meta['title'],
                    originalTitle: (string) $meta['title'],
                    mediaType: (string) ($meta['media_type'] ?? 'movie'),
                    year: (string) ($meta['year'] ?? ''),
                    posterUrl: '',
                    backdropUrl: '',
                    overview: '',
                    voteAverage: 0.0,
                    voteCount: 0,
                    slug: (string) ($meta['slug'] ?? 'title'),
                    persianTitle: '',
                    persianOverview: ''
                );
            }
        }

        if (count($topPicks) < 5) {
            $fallback = array_merge($trendingMovies, $trendingSeries);
            usort($fallback, static fn(MediaItem $a, MediaItem $b): int => $b->voteAverage <=> $a->voteAverage);
            foreach ($fallback as $candidate) {
                if (count($topPicks) >= 5) {
                    break;
                }
                $key = strtolower($candidate->imdbId);
                if (isset($seenTop[$key])) {
                    continue;
                }
                $seenTop[$key] = true;
                $topPicks[] = $candidate;
            }
        }

        $canonicalUrl = SeoHelper::getBaseUrl() . '/';
        $locale       = LocaleService::resolvePublicLocale();

        $siteTitle = (string) $this->settings->get('site.title', 'Subieto');
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $siteTitle)) {
            $siteTitle = 'Subieto';
        }
        $pageTitle = $locale === 'fa'
            ? "{$siteTitle} — دانلود مستقیم زیرنویس فیلم و سریال"
            : "{$siteTitle} — Subtitles, in sync";

        $ogImage = '';
        foreach (array_merge($trendingMovies, $trendingSeries) as $item) {
            if ($item->posterUrl !== '') {
                $ogImage = $item->posterUrl;
                break;
            }
        }

        $this->renderAndCachePage('/', 'home', [
            'pageTitle'       => $pageTitle,
            'metaDescription' => (string) $this->settings->get('site.description'),
            'canonicalUrl'    => $canonicalUrl,
            'locale'          => $locale,
            'ogImage'         => $ogImage !== '' ? $ogImage : null,
            'jsonLd'          => SeoHelper::buildHomeJsonLd($this->settings, $locale),
            'trendingMovies'  => $trendingMovies,
            'trendingSeries'  => $trendingSeries,
            'topPicks'        => $topPicks,
            'latestSubtitles' => $latestSubtitles,
            'isConfigured'    => $tmdb->isConfigured(),
        ], 1800);
    }
}

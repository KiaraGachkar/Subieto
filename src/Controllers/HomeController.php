<?php

declare(strict_types=1);

namespace App\Controllers;

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
                "SELECT `imdb_id`, `slug`, `title`, `media_type`, `year`
                 FROM `{$this->prefix}indexed_media`
                 ORDER BY `updated_at` DESC LIMIT 12"
            );
            $latestSubtitles = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            $latestSubtitles = [];
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
            'latestSubtitles' => $latestSubtitles,
            'isConfigured'    => $tmdb->isConfigured(),
        ], 1800);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Providers\TmdbMetadataProvider;
use App\Core\StaticPageCache;
use App\Services\LanguageMap;
use App\Services\SeoHelper;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class SeoController extends BaseController
{
    private const URLS_PER_SITEMAP = 1000;

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $base = SeoHelper::getBaseUrl();

        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin\n";
        echo "Disallow: /download\n";
        echo "Disallow: /api/\n";
        echo "Sitemap: {$base}/sitemap.xml\n";
    }

    /**
     * Master Sitemap Index supporting hundreds of thousands of URLs across paginated sub-sitemaps.
     */
    public function sitemapIndex(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        StaticPageCache::emitCacheHeaders(300);
        $base = SeoHelper::getBaseUrl();

        // Sync trending items into indexed_media
        $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        if ($tmdb->isConfigured() && $this->cache->get('sitemap_trending_lock') === null) {
            foreach (array_merge($tmdb->getTrending('movie', 20), $tmdb->getTrending('tv', 20)) as $item) {
                $this->metrics->indexMediaForSeo($item);
            }
            $this->cache->set('sitemap_trending_lock', 1, 3600);
        }

        $totalUrls  = count($this->buildMediaUrls($base));
        $totalPages = max(1, (int) ceil($totalUrls / self::URLS_PER_SITEMAP));
        $lastmod    = $this->latestMediaDate();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        echo '  <sitemap><loc>' . $this->xmlUrl($base . '/sitemap-pages.xml') . "</loc><lastmod>{$lastmod}</lastmod></sitemap>\n";

        for ($p = 1; $p <= $totalPages; $p++) {
            echo '  <sitemap><loc>' . $this->xmlUrl($base . "/sitemap-media-{$p}.xml") . "</loc><lastmod>{$lastmod}</lastmod></sitemap>\n";
        }

        echo '</sitemapindex>';
    }

    public function sitemapPages(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        StaticPageCache::emitCacheHeaders(300);
        $base    = SeoHelper::getBaseUrl();
        $lastmod = $this->latestMediaDate();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        echo '  <url><loc>' . $this->xmlUrl($base . '/') . "</loc><lastmod>{$lastmod}</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
        foreach (['yts', 'psa', 'galaxyrg', 'rarbg', 'sparks', 'ntb', 'flux', 'web-dl', 'bluray'] as $grp) {
            echo '  <url><loc>' . $this->xmlUrl($base . "/subtitles/release/{$grp}") . "</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.80</priority></url>\n";
        }
        echo '</urlset>';
    }

    public function sitemapMedia(string $page): void
    {
        $pageNum = max(1, (int) $page);
        $offset  = ($pageNum - 1) * self::URLS_PER_SITEMAP;

        header('Content-Type: application/xml; charset=utf-8');
        StaticPageCache::emitCacheHeaders(300);
        $base = SeoHelper::getBaseUrl();

        $allUrls = $this->buildMediaUrls($base);
        $slice   = array_slice($allUrls, $offset, self::URLS_PER_SITEMAP);

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($slice as $entry) {
            $loc = $this->xmlUrl($entry['loc']);
            echo "  <url><loc>{$loc}</loc><lastmod>{$entry['lastmod']}</lastmod><changefreq>weekly</changefreq><priority>{$entry['priority']}</priority></url>\n";
        }
        echo '</urlset>';
    }

    private function xmlUrl(string $url): string
    {
        return htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sitemapDate(string $value): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp !== false ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }

    private function latestMediaDate(): string
    {
        try {
            $stmt  = $this->db->query("SELECT MAX(`updated_at`) FROM `{$this->prefix}indexed_media`");
            $value = $stmt !== false ? (string) $stmt->fetchColumn() : '';
        } catch (Throwable) {
            $value = '';
        }
        return $this->sitemapDate($value);
    }

    /**
     * Builds one entry per media URL that actually has content: the base title URL plus
     * language variants that have recorded subtitles and the seasons declared in indexed_media.
     *
     * @return list<array{loc: string, lastmod: string, priority: string}>
     */
    private function buildMediaUrls(string $base): array
    {
        $sCacheKey = 'sitemap_urls_v1_' . md5($base);
        $cachedUrls = $this->cache->get($sCacheKey);
        if (is_array($cachedUrls)) {
            return $cachedUrls;
        }

        try {
            $stmt = $this->db->query(
                "SELECT `imdb_id`, `slug`, `seasons_json`, `langs_json`, `updated_at`
                 FROM `{$this->prefix}indexed_media`
                 ORDER BY `updated_at` DESC"
            );
            $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            try {
                $stmt = $this->db->query(
                    "SELECT `imdb_id`, `slug`, `seasons_json`, '' AS `langs_json`, `updated_at`
                     FROM `{$this->prefix}indexed_media`
                     ORDER BY `updated_at` DESC"
                );
                $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (Throwable) {
                return [];
            }
        }

        $langLastmods = $this->buildLanguageLastmods($rows);
        $urls         = [];

        foreach ($rows as $row) {
            $imdbId = (string) ($row['imdb_id'] ?? '');
            $slug   = (string) ($row['slug'] ?? '');
            if ($imdbId === '' || $slug === '') {
                continue;
            }
            $mediaLastmod = $this->sitemapDate((string) ($row['updated_at'] ?? ''));

            $urls[] = [
                'loc'      => "{$base}/subtitles/{$imdbId}/{$slug}",
                'lastmod'  => $mediaLastmod,
                'priority' => '0.85',
            ];

            $combinedLangs = $langLastmods[$imdbId] ?? [];
            $langsRaw = (string) ($row['langs_json'] ?? '');
            if ($langsRaw !== '') {
                $decodedLangs = json_decode($langsRaw, true);
                if (is_array($decodedLangs)) {
                    foreach ($decodedLangs as $lCode) {
                        $resolved = LanguageMap::resolve((string) $lCode);
                        $lSlug = $resolved['slug'];
                        if (LanguageMap::fromUrlSlug($lSlug) !== null && !isset($combinedLangs[$lSlug])) {
                            $combinedLangs[$lSlug] = $mediaLastmod;
                        }
                    }
                }
            }

            foreach ($combinedLangs as $langSlug => $langLastmod) {
                $urls[] = [
                    'loc'      => "{$base}/subtitles/{$imdbId}/{$slug}/{$langSlug}",
                    'lastmod'  => $langLastmod,
                    'priority' => '0.75',
                ];
            }

            $seasonsRaw = (string) ($row['seasons_json'] ?? '');
            if ($seasonsRaw === '') {
                continue;
            }
            $seasons = json_decode($seasonsRaw, true);
            if (!is_array($seasons)) {
                continue;
            }
            foreach ($seasons as $season) {
                $sInt = (int) $season;
                if ($sInt <= 0) {
                    continue;
                }
                $urls[] = [
                    'loc'      => "{$base}/subtitles/{$imdbId}/{$slug}/season-{$sInt}",
                    'lastmod'  => $mediaLastmod,
                    'priority' => '0.75',
                ];
            }
        }

        $this->cache->set($sCacheKey, $urls, 300);
        return $urls;
    }

    /**
     * Maps each IMDb id to the URL slugs of languages with recorded subtitle downloads,
     * using the most recent download timestamp as a realistic lastmod.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, string>>
     */
    private function buildLanguageLastmods(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row['imdb_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT `imdb_id`, `language_code`, MAX(`downloaded_at`) AS `last_dl`
                    FROM `{$this->prefix}download_stats`
                    WHERE `imdb_id` IN ({$placeholders})
                    GROUP BY `imdb_id`, `language_code`";
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($chunk);
                $stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                continue;
            }

            foreach ($stats as $stat) {
                $id      = (string) ($stat['imdb_id'] ?? '');
                $resolved = LanguageMap::resolve((string) ($stat['language_code'] ?? ''));
                $langSlug = $resolved['slug'];
                if ($id === '' || LanguageMap::fromUrlSlug($langSlug) === null) {
                    continue;
                }
                $lastmod = $this->sitemapDate((string) ($stat['last_dl'] ?? ''));
                if (!isset($map[$id][$langSlug]) || strcmp($lastmod, $map[$id][$langSlug]) > 0) {
                    $map[$id][$langSlug] = $lastmod;
                }
            }
        }

        return $map;
    }
}

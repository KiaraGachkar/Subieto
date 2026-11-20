<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\RateLimiter;
use App\Providers\TmdbMetadataProvider;
use App\Services\LanguageMap;
use App\Services\LocaleService;
use App\Services\ProxyDownloadService;
use App\Services\SeoHelper;
use App\Services\SubtitleAggregatorService;
use App\Services\SubtitleRepository;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class MovieController extends BaseController
{
    public function show(string $imdbId, string $slug = ''): void
    {
        $this->renderMediaPage($imdbId, $slug, null, null);
    }

    public function showLanguage(string $imdbId, string $slug, string $langSlug): void
    {
        $langInfo = LanguageMap::fromUrlSlug($langSlug);
        if ($langInfo === null) {
            $this->redirect('/subtitles/' . strtolower(trim($imdbId)) . '/' . $slug, 301);
        }
        $this->renderMediaPage($imdbId, $slug, null, $langInfo);
    }

    public function showSeason(string $imdbId, string $slugOrSeason, ?string $seasonParam = null): void
    {
        if ($seasonParam === null) {
            $this->renderMediaPage($imdbId, '', (int) $slugOrSeason, null);
        } else {
            $this->renderMediaPage($imdbId, $slugOrSeason, (int) $seasonParam, null);
        }
    }

    /**
     * Programmatic SEO Landing Page for Release Groups / Encoders (e.g. /subtitles/release/yts, /psa, /galaxyrg).
     */
    public function showReleaseGroup(string $group): void
    {
        $cleanGroup = strtolower(trim($group));
        if (!preg_match('/^[a-z0-9-]{2,30}$/', $cleanGroup)) {
            http_response_code(404);
            echo 'Release group not found.';
            return;
        }

        $groupCatalog = [
            'yts'      => ['name' => 'YTS / YIFY', 'desc' => 'Verified SRT subtitles synchronized with YTS & YIFY 720p/1080p/2160p BluRay and WEB-DL movie releases.'],
            'psa'      => ['name' => 'PSA Rips', 'desc' => 'Synchronized subtitles for PSA x265 10bit WEB-DL and BluRay movies and TV episodes.'],
            'galaxyrg' => ['name' => 'GalaxyRG', 'desc' => 'Multi-language subtitles timed for GalaxyRG 1080p and 720p releases.'],
            'rarbg'    => ['name' => 'RARBG', 'desc' => 'Accurate UTF-8 subtitles compatible with RARBG H264/x265 BluRay and WEB-DL encodes.'],
            'sparks'   => ['name' => 'SPARKS', 'desc' => 'Scene-accurate subtitles synchronized with SPARKS BluRay releases.'],
            'ntb'      => ['name' => 'NTb', 'desc' => 'High-precision subtitles timed for NTb WEB-DL TV series and movies.'],
            'flux'     => ['name' => 'FLUX', 'desc' => 'Verified multi-language subtitles synchronized with FLUX WEB-DL releases.'],
            'web-dl'   => ['name' => 'WEB-DL', 'desc' => 'Universal subtitles compatible with 1080p and 4K WEB-DL streaming releases.'],
            'bluray'   => ['name' => 'BluRay', 'desc' => 'High-accuracy subtitles timed for 720p, 1080p, and 2160p BluRay discs.'],
        ];

        $meta = $groupCatalog[$cleanGroup] ?? [
            'name' => strtoupper($cleanGroup),
            'desc' => 'Verified multi-language SRT subtitles synchronized with ' . strtoupper($cleanGroup) . ' releases.',
        ];

        $this->metrics->recordPageView();

        // Fetch indexed titles + trending titles so the landing page is rich and crawlable
        $indexedRows = [];
        try {
            $stmt = $this->db->query(
                "SELECT `imdb_id`, `slug`, `title`, `media_type`, `year`
                 FROM `{$this->prefix}indexed_media`
                 ORDER BY `updated_at` DESC LIMIT 24"
            );
            $indexedRows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            $indexedRows = [];
        }

        $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        $trending = $tmdb->getTrending('movie', 12);

        $canonicalUrl = SeoHelper::getBaseUrl() . '/subtitles/release/' . $cleanGroup;
        $this->render('release-group', [
            'pageTitle'       => "{$meta['name']} Subtitles — Download Synchronized SRT Files | Subieto",
            'metaDescription' => $meta['desc'],
            'canonicalUrl'    => $canonicalUrl,
            'groupSlug'       => $cleanGroup,
            'groupName'       => $meta['name'],
            'groupDesc'       => $meta['desc'],
            'groupCatalog'    => $groupCatalog,
            'indexedRows'     => $indexedRows,
            'trending'        => $trending,
        ]);
    }

    /**
     * @param array{code: string, label: string, slug: string}|null $langInfo
     */
    private function renderMediaPage(string $imdbId, string $slug, ?int $season, ?array $langInfo): void
    {
        $imdbId = strtolower(trim($imdbId));
        if (!preg_match('/^tt\d{5,10}$/', $imdbId)) {
            http_response_code(404);
            echo 'Invalid IMDb ID.';
            return;
        }

        if ($season !== null && ($season < 1 || $season > 100)) {
            http_response_code(404);
            echo 'Invalid season number.';
            return;
        }

        $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        $media = $tmdb->findByImdbId($imdbId);

        if ($media === null) {
            http_response_code(404);
            echo 'Title not found.';
            return;
        }

        $baseCanonicalPath = '/subtitles/' . $media->imdbId . '/' . $media->slug;
        if ($langInfo !== null) {
            $canonicalPath = $baseCanonicalPath . '/' . $langInfo['slug'];
        } elseif ($season !== null) {
            $canonicalPath = $baseCanonicalPath . '/season-' . $season;
        } else {
            $canonicalPath = $baseCanonicalPath;
        }

        if ($slug !== $media->slug) {
            $this->redirect($canonicalPath, 301);
        }

        $this->metrics->recordPageView();

        $repository = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');
        $aggregator = new SubtitleAggregatorService($this->settings, $repository);
        $allSubtitles = $aggregator->getSubtitlesForImdb($media->imdbId, ($season !== null && $media->mediaType === 'tv') ? $season : null);

        $availableSeasons = [];
        if ($media->mediaType === 'tv') {
            $totalSeasons = $tmdb->getSeasonCount($media->tmdbId, $media->imdbId);
            for ($sIdx = 1; $sIdx <= min($totalSeasons, 50); $sIdx++) {
                $availableSeasons[$sIdx] = $sIdx;
            }
        }
        foreach ($allSubtitles as $sub) {
            if ($sub->season !== null && $sub->season > 0) {
                $availableSeasons[$sub->season] = $sub->season;
            }
        }
        $seasonCounts = $repository->getSeasonCounts($media->imdbId);
        foreach (array_keys($seasonCounts) as $sKey) {
            if ($sKey > 0) {
                $availableSeasons[$sKey] = $sKey;
            }
        }
        if ($season !== null && $season > 0) {
            $availableSeasons[$season] = $season;
        }
        if ($media->mediaType === 'tv' && $availableSeasons !== []) {
            $highestSeason = max(array_keys($availableSeasons));
            for ($fillS = 1; $fillS <= min($highestSeason, 50); $fillS++) {
                $availableSeasons[$fillS] = $fillS;
            }
        }
        ksort($availableSeasons);
        $seasonNumbers = array_values($availableSeasons);

        $langCodes = array_values(array_unique(array_map(static fn($s): string => $s->languageCode, $allSubtitles)));
        $this->metrics->indexMediaForSeo($media, $seasonNumbers, $langCodes);

        // User demand is recorded explicitly via the "Request Subtitle" button (/api/request-subtitle)

        $displayedSubtitles = $allSubtitles;
        if ($langInfo !== null) {
            $displayedSubtitles = array_values(array_filter(
                $displayedSubtitles,
                static fn($s) => $s->languageCode === $langInfo['code']
            ));
        }
        if ($season !== null) {
            $exactSeasonSubs = array_values(array_filter(
                $displayedSubtitles,
                static fn($s) => $s->season === $season
            ));
            $displayedSubtitles = $exactSeasonSubs !== []
                ? $exactSeasonSubs
                : array_values(array_filter($displayedSubtitles, static fn($s) => $s->season === null));
            // Refresh season counts in case this season was just fetched on-demand
            $seasonCounts = $repository->getSeasonCounts($media->imdbId);
        }
        foreach ($displayedSubtitles as $subObj) {
            if ($subObj->season !== null && $subObj->season <= 0) {
                $subObj->season = null;
            }
            if ($subObj->episode !== null && $subObj->episode <= 0) {
                $subObj->episode = null;
            }
        }
        // Attach community sync votes & boost positively-voted subtitles to top within each language
        $votesMap = $repository->getVotesMapForImdb($media->imdbId);
        if ($votesMap !== []) {
            foreach ($displayedSubtitles as $subObj) {
                $vHash = hash('sha256', $media->imdbId . '|' . $subObj->downloadUrl);
                if (isset($votesMap[$vHash])) {
                    $subObj->upvotes   = $votesMap[$vHash]['up'];
                    $subObj->downvotes = $votesMap[$vHash]['down'];
                }
            }
            // Group by language to preserve exact language priority order (avoids non-transitive usort)
            $byLang = [];
            foreach ($displayedSubtitles as $subObj) {
                $byLang[$subObj->languageCode][] = $subObj;
            }
            $sortedSubs = [];
            foreach ($byLang as $langGroup) {
                usort($langGroup, static fn($a, $b): int => ($b->upvotes - $b->downvotes) <=> ($a->upvotes - $a->downvotes));
                foreach ($langGroup as $item) {
                    $sortedSubs[] = $item;
                }
            }
            $displayedSubtitles = $sortedSubs;
        }
        if (count($displayedSubtitles) > 500) {
            $displayedSubtitles = array_slice($displayedSubtitles, 0, 500);
        }

        $similarMedia = $tmdb->getSimilar($media->tmdbId, $media->mediaType, 6);

        $locale          = LocaleService::resolvePublicLocale();
        $canonicalUrl    = SeoHelper::getBaseUrl() . $canonicalPath;
        $xDefaultUrl     = $canonicalUrl;
        $pageTitle       = SeoHelper::buildMovieTitle($this->settings, $media, $season, $langInfo, $locale);
        $metaDescription = SeoHelper::buildMovieMetaDescription($media, $displayedSubtitles, $season, $langInfo, $locale);
        $jsonLd          = SeoHelper::buildMovieJsonLd($this->settings, $media, $displayedSubtitles, $canonicalUrl, $season, $locale);

        $hreflang = '';

        $bindIp  = $this->settings->get('security.bind_ip_download', '0') === '1';
        $pageTtl = $bindIp ? 0 : min(3600, max(0, (int) $this->settings->get('site.cache_ttl', 21600)));

        $viewData = [
            'locale'           => $locale,
            'pageTitle'        => $pageTitle,
            'metaDescription'  => $metaDescription,
            'canonicalUrl'     => $canonicalUrl,
            'xDefaultUrl'      => $xDefaultUrl,
            'hreflangLinks'    => $hreflang,
            'ogImage'          => $media->posterUrl,
            'ogType'           => $media->mediaType === 'tv' ? 'video.tv_show' : 'video.movie',
            'jsonLd'           => $jsonLd,
            'media'            => $media,
            'subtitles'        => $displayedSubtitles,
            'totalSubsCount'   => $season !== null ? max(count($allSubtitles), array_sum($seasonCounts)) : count($allSubtitles),
            'activeSeason'     => $season,
            'activeLangInfo'   => $langInfo,
            'availableSeasons' => $seasonNumbers,
            'seasonCounts'     => $seasonCounts,
            'similarMedia'     => $similarMedia,
            'signer'           => $this->signer,
            'tokenTtl'         => 14400 + $pageTtl,
            'clientIp'         => $bindIp ? $this->getClientIp() : null,
        ];

        if ($pageTtl > 0) {
            $this->renderAndCachePage($canonicalPath, 'movie-detail', $viewData, $pageTtl);
        } else {
            $this->render('movie-detail', $viewData);
        }
    }

    public function download(): void
    {
        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('download', 30, 60)) {
            http_response_code(429);
            header('Retry-After: 60');
            echo 'Too many download requests. Please wait a minute.';
            return;
        }

        $token   = (string) ($_GET['token'] ?? '');
        $payload = $this->signer->verify($token, $this->getClientIp());

        if ($payload === null || empty($payload['url'])) {
            http_response_code(403);
            echo 'Invalid or expired download link.';
            return;
        }

        $meta = [
            'imdbId'   => $payload['imdb'] ?? '',
            'title'    => $payload['title'] ?? '',
            'provider' => $payload['provider'] ?? '',
            'lang'     => $payload['lang'] ?? 'fa',
        ];

        $downloader = new ProxyDownloadService($this->settings, $this->db, $this->prefix);
        $downloader->deliver($payload['url'], $payload['file'] ?? 'subtitle.srt', $meta);
    }

    /**
     * Records an explicit user subtitle request so `subieto_auto_sync.py` prioritizes it.
     */
    public function requestSubtitle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('sub_request', 20, 60)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate_limited']);
            return;
        }

        $raw = file_get_contents('php://input');
        $body = is_string($raw) ? json_decode($raw, true) : null;
        $imdbId = strtolower(trim((string) ($body['imdb'] ?? $_POST['imdb'] ?? '')));
        if (!preg_match('/^tt\d{5,10}$/', $imdbId)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_imdb']);
            return;
        }

        $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        $media = $tmdb->findByImdbId($imdbId);
        if ($media === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            return;
        }

        $this->metrics->recordMissingPersianSubtitle($media);
        $this->metrics->indexMediaForSeo($media);

        echo json_encode([
            'ok'      => true,
            'imdb_id' => $imdbId,
            'title'   => $media->title,
        ], JSON_UNESCAPED_UNICODE);
    }
}

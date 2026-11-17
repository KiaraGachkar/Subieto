<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\PasswordHasher;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Providers\FreeOpenSubtitlesProvider;
use App\Providers\PodnapisiProvider;
use App\Providers\Subf2mProvider;
use App\Providers\TmdbMetadataProvider;
use App\Providers\YifySubtitlesProvider;
use App\Services\LocaleService;
use PDO;

defined('APP_SECURE_BOOT') or exit;

final class AdminController extends BaseController
{
    public function loginForm(): void
    {
        if (Session::isAdmin()) {
            $this->redirect('/admin');
        }

        $limiter = new RateLimiter($this->db, $this->prefix);
        $lockSeconds = $limiter->getRemainingLockSeconds('admin_login');

        $this->render('admin/login', [
            'adminLocale' => LocaleService::resolveAdminLocale(),
            'pageTitle'   => 'Admin Sign In | Subieto',
            'error'       => Session::flash('error'),
            'lockSeconds' => $lockSeconds,
        ], null);
    }

    public function login(): void
    {
        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('admin_login_req', 15, 60, false)) {
            Session::flash('error', 'Too many login requests. Please wait one minute.');
            $this->redirect('/admin/login');
        }

        $lockSeconds = $limiter->getRemainingLockSeconds('admin_login');
        if ($lockSeconds > 0) {
            $mins = (int) ceil($lockSeconds / 60);
            Session::flash('error', "Too many failed attempts. Please wait {$mins} minute(s) before trying again.");
            $this->redirect('/admin/login');
        }

        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Invalid security token. Please refresh and try again.');
            $this->redirect('/admin/login');
        }

        $password = (string) ($_POST['password'] ?? '');
        $storedHash = (string) $this->settings->get('admin.password_hash', '');

        if (PasswordHasher::verify($password, $storedHash)) {
            if (PasswordHasher::needsRehash($storedHash)) {
                $this->settings->updateMany(['admin.password_hash' => PasswordHasher::hash($password)]);
            }
            $limiter->clear('admin_login');
            Session::setAdmin(true);
            $this->redirect('/admin');
        }

        $limiter->hit('admin_login');
        Session::flash('error', 'Incorrect administrator password.');
        $this->redirect('/admin/login');
    }

    public function logout(): void
    {
        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->redirect('/admin');
        }
        Session::destroy();
        $this->redirect('/admin/login');
    }

    public function dashboard(): void
    {
        $this->requireAdmin();

        $totalDownloads = (int) $this->db->query("SELECT COUNT(*) FROM `{$this->prefix}download_stats`")->fetchColumn();

        $langBreakdown = $this->db->query(
            "SELECT `language_code`, COUNT(*) AS `cnt`
             FROM `{$this->prefix}download_stats`
             GROUP BY `language_code`
             ORDER BY `cnt` DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $providerBreakdown = $this->db->query(
            "SELECT `provider`, COUNT(*) AS `cnt`
             FROM `{$this->prefix}download_stats`
             WHERE `provider` != ''
             GROUP BY `provider`
             ORDER BY `cnt` DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recentDownloads = $this->db->query(
            "SELECT `imdb_id`, `media_title`, `provider`, `language_code`, `file_name`, `downloaded_at`
             FROM `{$this->prefix}download_stats`
             ORDER BY `id` DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $topMedia = $this->db->query(
            "SELECT `imdb_id`, `media_title`, COUNT(*) AS `dl_count`
             FROM `{$this->prefix}download_stats`
             WHERE `media_title` != ''
             GROUP BY `imdb_id`, `media_title`
             ORDER BY `dl_count` DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dailyMetrics   = $this->metrics->getLast7Days();
        $indexedCount   = $this->metrics->getIndexedMediaCount();
        $adClicksMap    = $this->metrics->getAdClicksMap();
        $missingSubs    = $this->metrics->getTopMissingSubtitles(10);
        $staticPagesCnt = $this->pageCache?->countFiles() ?? 0;

        $this->render('admin/dashboard', [
            'adminLocale'       => LocaleService::resolveAdminLocale(),
            'pageTitle'         => 'Admin Studio | Subieto',
            'config'            => array_diff_key($this->settings->all(), ['admin.password_hash' => 1, 'app.secret_key' => 1]),
            'cacheCount'        => $this->cache->countItems(),
            'staticPagesCount'  => $staticPagesCnt,
            'indexedCount'      => $indexedCount,
            'totalDownloads'    => $totalDownloads,
            'langBreakdown'     => $langBreakdown,
            'providerBreakdown' => $providerBreakdown,
            'recentDownloads'   => $recentDownloads,
            'topMedia'          => $topMedia,
            'dailyMetrics'      => $dailyMetrics,
            'adClicksMap'       => $adClicksMap,
            'missingSubs'       => $missingSubs,
            'success'           => Session::flash('success'),
            'error'             => Session::flash('error'),
        ], null);
    }

    public function saveSettings(): void
    {
        $this->requireAdmin();

        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security session expired. Please submit your changes again.');
            $this->redirect('/admin');
        }

        $cacheTtl      = min(604800, max(0, (int) ($_POST['cache_ttl'] ?? 21600)));
        $adsEnabled    = isset($_POST['ads_enabled']) ? '1' : '0';
        $adblockNotice = isset($_POST['adblock_notice']) ? '1' : '0';

        $rawBaseUrl = rtrim(trim((string) ($_POST['site_base_url'] ?? '')), '/');
        if ($rawBaseUrl === '' || !preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $rawBaseUrl)) {
            Session::flash('error', 'Invalid Base URL. Please enter a full URL like https://example.com.');
            $this->redirect('/admin');
        }

        $priorityLangs = preg_replace('/[^a-zA-Z,]/', '', (string) ($_POST['priority_languages'] ?? 'en,fa,ar,es,fr,tr')) ?: 'en,fa';

        $validProxies = [];
        foreach (explode(',', (string) ($_POST['trusted_proxies'] ?? '')) as $tok) {
            $tok = trim($tok);
            if ($tok === '' || $tok === '0.0.0.0/0' || $tok === '::/0') {
                continue;
            }
            [$ip, $bits] = array_pad(explode('/', $tok, 2), 2, null);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($bits !== null && (!ctype_digit($bits) || (int) $bits > 128)) {
                continue;
            }
            $validProxies[] = $tok;
        }

        $updates = [
            'site.title'                       => trim((string) ($_POST['site_title'] ?? 'Subieto')),
            'site.base_url'                    => $rawBaseUrl,
            'site.description'                 => trim((string) ($_POST['site_description'] ?? '')),
            'site.default_theme'               => (($_POST['default_theme'] ?? 'light') === 'dark' ? 'dark' : 'light'),
            'site.cache_ttl'                   => (string) $cacheTtl,
            'apis.tmdb_api_key'                => trim((string) ($_POST['tmdb_api_key'] ?? '')),
            'apis.subdl_api_key'               => trim((string) ($_POST['subdl_api_key'] ?? '')),
            'apis.opensubtitles_api_key'       => trim((string) ($_POST['opensubtitles_api_key'] ?? '')),
            'providers.free_opensub_enabled'   => isset($_POST['free_opensub_enabled']) ? '1' : '0',
            'providers.podnapisi_enabled'      => isset($_POST['podnapisi_enabled']) ? '1' : '0',
            'providers.yify_enabled'           => isset($_POST['yify_enabled']) ? '1' : '0',
            'providers.subf2m_enabled'         => isset($_POST['subf2m_enabled']) ? '1' : '0',
            'providers.priority_languages'     => strtolower(trim($priorityLangs, ',')),
            'seo.movie_title_tpl'              => trim((string) ($_POST['seo_movie_title_tpl'] ?? '')),
            'seo.google_verification'          => trim((string) ($_POST['seo_google_verification'] ?? '')),
            'seo.keywords'                     => trim((string) ($_POST['seo_keywords'] ?? '')),
            'security.bind_ip_download'        => isset($_POST['bind_ip_download']) ? '1' : '0',
            'security.trusted_proxies'         => implode(',', $validProxies),
            'ads.enabled'                      => $adsEnabled,
            'ads.sample_preview'               => isset($_POST['ads_sample_preview']) ? '1' : '0',
            'ads.adblock_notice'               => $adblockNotice,
            'ads.adblock_message'              => trim((string) ($_POST['adblock_message'] ?? '')),
            'ads.head_scripts'                 => (string) ($_POST['ad_head_scripts'] ?? ''),
            'ads.head_scripts_ir'              => (string) ($_POST['ad_head_scripts_ir'] ?? ''),
            'ads.header_banner'                => (string) ($_POST['ad_header_banner'] ?? ''),
            'ads.header_banner_mobile'         => (string) ($_POST['ad_header_banner_mobile'] ?? ''),
            'ads.header_banner_ir'             => (string) ($_POST['ad_header_banner_ir'] ?? ''),
            'ads.header_banner_expires'        => trim((string) ($_POST['ad_header_banner_expires'] ?? '')),
            'ads.home_below_search'            => (string) ($_POST['ad_home_below_search'] ?? ''),
            'ads.home_below_search_mobile'     => (string) ($_POST['ad_home_below_search_mobile'] ?? ''),
            'ads.home_below_search_ir'         => (string) ($_POST['ad_home_below_search_ir'] ?? ''),
            'ads.home_below_search_expires'    => trim((string) ($_POST['ad_home_below_search_expires'] ?? '')),
            'ads.home_between'                 => (string) ($_POST['ad_home_between'] ?? ''),
            'ads.home_between_mobile'          => (string) ($_POST['ad_home_between_mobile'] ?? ''),
            'ads.home_between_ir'              => (string) ($_POST['ad_home_between_ir'] ?? ''),
            'ads.home_between_expires'         => trim((string) ($_POST['ad_home_between_expires'] ?? '')),
            'ads.home_footer_banner'           => (string) ($_POST['ad_home_footer_banner'] ?? ''),
            'ads.home_footer_banner_mobile'    => (string) ($_POST['ad_home_footer_banner_mobile'] ?? ''),
            'ads.home_footer_banner_ir'        => (string) ($_POST['ad_home_footer_banner_ir'] ?? ''),
            'ads.home_footer_banner_expires'   => trim((string) ($_POST['ad_home_footer_banner_expires'] ?? '')),
            'ads.search_top_banner'            => (string) ($_POST['ad_search_top_banner'] ?? ''),
            'ads.search_top_banner_mobile'     => (string) ($_POST['ad_search_top_banner_mobile'] ?? ''),
            'ads.search_top_banner_ir'         => (string) ($_POST['ad_search_top_banner_ir'] ?? ''),
            'ads.search_top_banner_expires'    => trim((string) ($_POST['ad_search_top_banner_expires'] ?? '')),
            'ads.movie_sidebar'                => (string) ($_POST['ad_movie_sidebar'] ?? ''),
            'ads.movie_sidebar_mobile'         => (string) ($_POST['ad_movie_sidebar_mobile'] ?? ''),
            'ads.movie_sidebar_ir'             => (string) ($_POST['ad_movie_sidebar_ir'] ?? ''),
            'ads.movie_sidebar_expires'        => trim((string) ($_POST['ad_movie_sidebar_expires'] ?? '')),
            'ads.movie_above_table'            => (string) ($_POST['ad_movie_above_table'] ?? ''),
            'ads.movie_above_table_mobile'     => (string) ($_POST['ad_movie_above_table_mobile'] ?? ''),
            'ads.movie_above_table_ir'         => (string) ($_POST['ad_movie_above_table_ir'] ?? ''),
            'ads.movie_above_table_expires'    => trim((string) ($_POST['ad_movie_above_table_expires'] ?? '')),
            'ads.movie_in_feed'                => (string) ($_POST['ad_movie_in_feed'] ?? ''),
            'ads.movie_in_feed_mobile'         => (string) ($_POST['ad_movie_in_feed_mobile'] ?? ''),
            'ads.movie_in_feed_ir'             => (string) ($_POST['ad_movie_in_feed_ir'] ?? ''),
            'ads.movie_in_feed_expires'        => trim((string) ($_POST['ad_movie_in_feed_expires'] ?? '')),
            'ads.movie_below_table'            => (string) ($_POST['ad_movie_below_table'] ?? ''),
            'ads.movie_below_table_mobile'     => (string) ($_POST['ad_movie_below_table_mobile'] ?? ''),
            'ads.movie_below_table_ir'         => (string) ($_POST['ad_movie_below_table_ir'] ?? ''),
            'ads.movie_below_table_expires'    => trim((string) ($_POST['ad_movie_below_table_expires'] ?? '')),
            'ads.sticky_footer_mobile'         => (string) ($_POST['ad_sticky_footer_mobile'] ?? ''),
            'ads.sticky_footer_mobile_ir'      => (string) ($_POST['ad_sticky_footer_mobile_ir'] ?? ''),
            'ads.sticky_footer_mobile_expires' => trim((string) ($_POST['ad_sticky_footer_mobile_expires'] ?? '')),
        ];

        $newPassword     = trim((string) ($_POST['new_password'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        if ($newPassword !== '') {
            $storedHash = (string) $this->settings->get('admin.password_hash', '');
            if ($currentPassword === '' || !PasswordHasher::verify($currentPassword, $storedHash)) {
                Session::flash('error', 'Current password is required and must match to set a new password.');
                $this->redirect('/admin');
            }
            if (strlen($newPassword) < 8) {
                Session::flash('error', 'New admin password must be at least 8 characters.');
                $this->redirect('/admin');
            }
            $updates['admin.password_hash'] = PasswordHasher::hash($newPassword);
        }

        if (isset($_POST['rotate_secret_key'])) {
            $updates['app.secret_key'] = bin2hex(random_bytes(32));
        }

        $this->settings->updateMany($updates);
        $this->pageCache?->clearAll();
        $this->cache->clear();
        $this->cache->pruneExpired();
        Session::flash('success', 'All settings saved; static pages and API cache refreshed.');
        $this->redirect('/admin');
    }

    public function clearCache(): void
    {
        $this->requireAdmin();

        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Invalid security token.');
            $this->redirect('/admin');
        }

        $mode = (string) ($_POST['mode'] ?? 'all');
        $limiter = new RateLimiter($this->db, $this->prefix);
        $limiter->cleanupOldWindows();

        if ($mode === 'expired') {
            $deleted = $this->cache->clearExpired();
            Session::flash('success', "Removed {$deleted} expired cache entries.");
        } elseif ($mode === 'pages') {
            $pagesDeleted = $this->pageCache?->clearAll() ?? 0;
            Session::flash('success', "Cleared {$pagesDeleted} cached HTML pages.");
        } elseif ($mode === 'demand') {
            $this->db->exec("TRUNCATE TABLE `{$this->prefix}missing_subtitles`");
            Session::flash('success', 'Cleared missing subtitle demand log.');
        } else {
            $deleted = $this->cache->clear();
            $pagesDeleted = $this->pageCache?->clearAll() ?? 0;
            Session::flash('success', "Cleared {$deleted} API records and {$pagesDeleted} static HTML pages.");
        }

        $this->redirect('/admin');
    }

    public function testApis(): void
    {
        $this->requireAdmin();
        header('Content-Type: application/json; charset=utf-8');

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
        if (!Session::verifyCsrf(is_string($token) ? $token : null)) {
            http_response_code(403);
            echo json_encode(['error' => 'csrf']);
            return;
        }

        $tmdb      = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
        $freeOs    = new FreeOpenSubtitlesProvider($this->http, $this->settings);
        $podnapisi = new PodnapisiProvider($this->http, $this->settings);
        $yify      = new YifySubtitlesProvider($this->http, $this->settings);
        $subf2m    = new Subf2mProvider($this->http, $this->settings, $this->cache);

        $t0 = microtime(true);
        $metaItem = $tmdb->findByImdbId('tt1375666');
        $metaMs   = (int) round((microtime(true) - $t0) * 1000);

        $osItems   = $freeOs->searchByImdbId('tt1375666', 'movie');
        $podItems  = $podnapisi->searchByImdbId('tt1375666', 'movie');
        $yifyItems = $yify->searchByImdbId('tt1375666', 'movie');
        $s2mItems  = $subf2m->searchByTitle('Inception', '2010');

        echo json_encode([
            'metadata'      => ['ok' => $metaItem !== null, 'ms' => $metaMs, 'source' => $tmdb->hasTmdbKey() ? 'TMDB' : 'Cinemeta (Free)'],
            'opensub_free'  => ['ok' => count($osItems) > 0, 'count' => count($osItems)],
            'podnapisi'     => ['ok' => count($podItems) > 0, 'count' => count($podItems)],
            'yify'          => ['ok' => count($yifyItems) > 0, 'count' => count($yifyItems)],
            'subf2m'        => ['ok' => count($s2mItems) > 0, 'count' => count($s2mItems)],
        ]);
    }

    private function requireAdmin(): void
    {
        if (!Session::isAdmin()) {
            $this->redirect('/admin/login');
        }
    }
}

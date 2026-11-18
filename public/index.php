<?php

declare(strict_types=1);

define('APP_SECURE_BOOT', true);

$GLOBALS['SB_T0']    = microtime(true);
$GLOBALS['SB_TIMES'] = [];
header_register_callback(static function (): void {
    $times = $GLOBALS['SB_TIMES'] ?? [];
    $times['total'] = microtime(true) - ($GLOBALS['SB_T0'] ?? microtime(true));
    $parts = [];
    foreach ($times as $name => $seconds) {
        $parts[] = $name . ';dur=' . round($seconds * 1000, 1);
    }
    header('Server-Timing: ' . implode(', ', $parts));
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && strpos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
    @ob_start('ob_gzhandler');
}

// Security HTTP Headers
define('CSP_NONCE', base64_encode(random_bytes(16)));
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . CSP_NONCE . "' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; connect-src 'self' https:; frame-src https:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");

set_exception_handler(static function (Throwable $e): void {
    error_log('[fatal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo 'Internal Server Error';
});

$basePath = is_dir(dirname(__DIR__) . '/subieto/src')
    ? dirname(__DIR__) . '/subieto'
    : dirname(__DIR__);
$composerAutoload = $basePath . '/vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($basePath): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relativeClass = substr($class, strlen($prefix));
        $file = $basePath . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use App\Core\Cache;
use App\Core\ClientIp;
use App\Core\Database;
use App\Core\Installer;
use App\Core\Router;
use App\Core\Session;
use App\Core\SettingsManager;
use App\Core\StaticPageCache;
use App\Services\LocaleService;
use App\Services\MetricsService;
use App\Services\SeoHelper;

$installer = new Installer($basePath);
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$rawPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
if ($rawPath !== '/' && str_ends_with($rawPath, '/')) {
    $query = (string) (parse_url($requestUri, PHP_URL_QUERY) ?? '');
    header('Location: ' . rtrim($rawPath, '/') . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}
$requestPath = $rawPath;

$isAdminOrInstall = str_starts_with($requestPath, '/admin') || str_starts_with($requestPath, '/install');
$installRequested = str_starts_with($requestPath, '/install');
$installAllowed = $installer->isInstallAllowed();

if (!$installer->isInstalled() && !$installAllowed) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

if (!$installer->isInstalled() && !$installRequested) {
    header('Location: /install', true, 302);
    exit;
}

if ($installRequested && ($installer->isInstalled() || !$installAllowed)) {
    header('Location: /', true, 302);
    exit;
}

$pageCache = new StaticPageCache($basePath);
$cacheableRoute = $requestPath === '/'
    || $requestPath === '/search'
    || $requestPath === '/robots.txt'
    || str_starts_with($requestPath, '/subtitles/')
    || str_starts_with($requestPath, '/sitemap');

if ($installer->isInstalled() && in_array($requestMethod, ['GET', 'HEAD'], true) && $cacheableRoute) {
    $bootMetaFile = $basePath . '/storage/cache/boot_meta.json';
    $bootMeta = is_file($bootMetaFile) ? json_decode((string) @file_get_contents($bootMetaFile), true) : null;
    if (is_array($bootMeta)) {
        ClientIp::configureTrustedProxies((string) ($bootMeta['trusted_proxies'] ?? ''));
        SeoHelper::configure((string) ($bootMeta['base_url'] ?? ''));
        if (ClientIp::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        if (SeoHelper::isTrustedHost()) {
            $locale = LocaleService::resolvePublicLocale();
            $geo    = LocaleService::isIranVisitor() ? 'ir' : 'gl';
            $queryParams = [];
            parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?? ''), $queryParams);
            $queryPart = $queryParams !== [] ? '?' . md5(http_build_query($queryParams)) : '';
            $hostKey  = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
            $cacheKey = $requestPath . $queryPart . ':' . $locale . ':' . $geo . ':' . $hostKey;
            $GLOBALS['SB_TIMES']['cache'] = microtime(true) - $GLOBALS['SB_T0'];
            if ($pageCache->serveIfValid($cacheKey, 7200)) {
                MetricsService::recordCachedViewFast($basePath . '/storage');
                exit;
            }
        }
    }
}

$db = null;
$settings = null;
$cache = null;
$prefix = 'sh_';

if ($installer->isInstalled()) {
    $dbConfig = require $basePath . '/config/database.php';
    $db       = Database::connect($dbConfig);
    $prefix   = (string) ($dbConfig['prefix'] ?? 'sh_');
    $settings = new SettingsManager($db, $prefix);
    $cache    = new Cache($db, $prefix, $basePath . '/storage/cache');
    if (!is_file($basePath . '/storage/cache/boot_meta.json')) {
        $settings->writeBootCache();
    }

    ClientIp::configureTrustedProxies((string) $settings->get('security.trusted_proxies', ''));
    SeoHelper::configure((string) $settings->get('site.base_url', ''));

    if (ClientIp::isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

$GLOBALS['SB_TIMES']['db'] = microtime(true) - $GLOBALS['SB_T0'];

if ($isAdminOrInstall) {
    Session::start();
}

$router = new Router();

if (!$installer->isInstalled()) {
    $router->get('/install', 'App\Controllers\InstallController@index');
    $router->post('/install/run', 'App\Controllers\InstallController@run');

    $router->dispatch($requestMethod, $requestUri, [
        'basePath'  => $basePath,
        'installer' => $installer,
    ]);
    exit;
}

// Public & SEO Routes
$router->get('/', 'App\Controllers\HomeController@index');
$router->get('/search', 'App\Controllers\SearchController@search');
$router->post('/api/ad-click', 'App\Controllers\AdTrackController@click');
$router->get('/api/ad-slots', 'App\Controllers\AdTrackController@slots');
$router->get('/subtitles/release/{group}', 'App\Controllers\MovieController@showReleaseGroup');
$router->get('/subtitles/{imdbId}/{slug}/season-{season}', 'App\Controllers\MovieController@showSeason');
$router->get('/subtitles/{imdbId}/season-{season}', 'App\Controllers\MovieController@showSeason');
$router->get('/subtitles/{imdbId}/{slug}/{langSlug}', 'App\Controllers\MovieController@showLanguage');
$router->get('/subtitles/{imdbId}/{slug}', 'App\Controllers\MovieController@show');
$router->get('/subtitles/{imdbId}', 'App\Controllers\MovieController@show');
$router->get('/download', 'App\Controllers\MovieController@download');
$router->get('/cron/sync', 'App\Controllers\CronController@sync');
$router->get('/sitemap.xml', 'App\Controllers\SeoController@sitemapIndex');
$router->get('/sitemap-pages.xml', 'App\Controllers\SeoController@sitemapPages');
$router->get('/sitemap-media-{page}.xml', 'App\Controllers\SeoController@sitemapMedia');
$router->get('/robots.txt', 'App\Controllers\SeoController@robots');

// Admin Routes (POST + CSRF on logout)
$router->get('/admin/login', 'App\Controllers\AdminController@loginForm');
$router->post('/admin/login', 'App\Controllers\AdminController@login');
$router->post('/admin/logout', 'App\Controllers\AdminController@logout');
$router->get('/admin', 'App\Controllers\AdminController@dashboard');
$router->post('/admin/settings', 'App\Controllers\AdminController@saveSettings');
$router->post('/admin/clear-cache', 'App\Controllers\AdminController@clearCache');
$router->post('/admin/test-apis', 'App\Controllers\AdminController@testApis');
$router->post('/admin/sync-imdb', 'App\Controllers\AdminController@syncImdbNow');

$router->dispatch($requestMethod, $requestUri, [
    'basePath'  => $basePath,
    'db'        => $db,
    'prefix'    => $prefix,
    'settings'  => $settings,
    'cache'     => $cache,
    'pageCache' => $pageCache,
]);

$GLOBALS['SB_TIMES']['app'] = microtime(true) - $GLOBALS['SB_T0'];

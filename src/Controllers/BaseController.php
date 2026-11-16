<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Cache;
use App\Core\ClientIp;
use App\Core\HttpClient;
use App\Core\SettingsManager;
use App\Core\StaticPageCache;
use App\Core\UrlSigner;
use App\Core\View;
use App\Services\LocaleService;
use App\Services\MetricsService;
use App\Services\SeoHelper;
use PDO;

defined('APP_SECURE_BOOT') or exit;

abstract class BaseController
{
    protected SettingsManager $settings;
    protected Cache $cache;
    protected ?StaticPageCache $pageCache;
    protected PDO $db;
    protected string $prefix;
    protected HttpClient $http;
    protected View $view;
    protected UrlSigner $signer;
    protected MetricsService $metrics;
    protected string $basePath;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(array $context)
    {
        $this->basePath  = $context['basePath'];
        $this->settings  = $context['settings'];
        $this->cache     = $context['cache'];
        $this->pageCache = $context['pageCache'] ?? null;
        $this->db        = $context['db'];
        $this->prefix    = $context['prefix'] ?? 'sh_';
        $this->http      = new HttpClient();
        $this->view      = new View($this->basePath . '/views');
        $this->metrics   = new MetricsService($this->db, $this->prefix, $this->basePath . '/storage');

        $secret = trim((string) $this->settings->get('app.secret_key', ''));
        if ($secret === '') {
            throw new \RuntimeException('Critical configuration missing: app.secret_key is empty.');
        }
        $this->signer = new UrlSigner($secret);
    }

    protected function getClientIp(): string
    {
        return ClientIp::get();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], ?string $layout = 'layouts/main'): void
    {
        $data['settings'] = $this->settings;
        $data['locale']   = $data['locale'] ?? LocaleService::resolvePublicLocale();
        $this->view->render($template, $data, $layout);
    }

    /**
     * Renders a view, captures its HTML, and stores it partitioned by locale and visitor geo (Iran vs Global).
     *
     * @param array<string, mixed> $data
     */
    protected function renderAndCachePage(string $cachePath, string $template, array $data = [], int $ttl = 7200): void
    {
        $locale = $data['locale'] ?? LocaleService::resolvePublicLocale();
        $geo    = LocaleService::isIranVisitor() ? 'ir' : 'gl';
        $data['settings'] = $this->settings;
        $data['locale']   = $locale;

        ob_start();
        $this->view->render($template, $data, 'layouts/main');
        $html = (string) ob_get_clean();
        if (preg_match('/\b(?:Warning|Notice|Deprecated|Fatal error|Stack trace):/', $html)) {
            echo $html;
            return;
        }

        $partitionedKey = "{$cachePath}:{$locale}:{$geo}";
        if ($this->pageCache !== null && $ttl > 0 && SeoHelper::isTrustedHost()) {
            $this->pageCache->store($partitionedKey, $html, $ttl);
            StaticPageCache::emitCacheHeaders(min($ttl, 1800));
            header('X-Page-Cache: MISS');
        }

        echo $html;
    }

    protected function redirect(string $url, int $code = 302): void
    {
        header('Location: ' . $url, true, $code);
        exit;
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Cache;
use App\Core\HttpClient;
use App\Core\SettingsManager;

defined('APP_SECURE_BOOT') or exit;

/**
 * Subzone.ir is a mirror of Subf2m (same pages, same URL scheme), so it reuses the
 * Subf2m scraper with its own base URL. Downloads are routed as "subzone://<detail url>".
 */
final class SubzoneProvider extends Subf2mProvider
{
    public function __construct(HttpClient $http, SettingsManager $settings, ?Cache $cache = null)
    {
        parent::__construct($http, $settings, $cache, 'https://subzone.ir', 'subzone', 'Subzone', 'providers.subzone_enabled');
    }
}

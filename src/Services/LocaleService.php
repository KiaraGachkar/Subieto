<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ClientIp;

defined('APP_SECURE_BOOT') or exit;

final class LocaleService
{
    /** @var array<string, bool> Request-scoped memoization of the geo check. */
    private static array $iranVisitorCache = [];

    public static function resolvePublicLocale(): string
    {
        if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fa'], true)) {
            $chosen = (string) $_GET['lang'];
            if (!headers_sent()) {
                setcookie('subieto_lang', $chosen, [
                    'expires'  => time() + 86400 * 30,
                    'path'     => '/',
                    'secure'   => ClientIp::isHttps(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
            return $chosen;
        }

        if (isset($_COOKIE['subieto_lang']) && in_array($_COOKIE['subieto_lang'], ['en', 'fa'], true)) {
            return (string) $_COOKIE['subieto_lang'];
        }

        if (self::prefersPersianLanguage()) {
            return 'fa';
        }

        return 'en';
    }

    public static function resolveAdminLocale(): string
    {
        if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'fa'], true)) {
            $chosen = (string) $_GET['lang'];
            if (!headers_sent()) {
                setcookie('subieto_admin_lang', $chosen, [
                    'expires'  => time() + 86400 * 30,
                    'path'     => '/',
                    'secure'   => ClientIp::isHttps(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
            return $chosen;
        }

        if (isset($_COOKIE['subieto_admin_lang']) && in_array($_COOKIE['subieto_admin_lang'], ['en', 'fa'], true)) {
            return (string) $_COOKIE['subieto_admin_lang'];
        }

        return 'fa';
    }

    /**
     * Checks browser Accept-Language OR Iran IP for UI localization (keeps UI Persian even when VPN is active).
     */
    public static function prefersPersianLanguage(): bool
    {
        $acceptLang = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
        if (str_starts_with($acceptLang, 'fa') || str_contains($acceptLang, 'fa-ir')) {
            return true;
        }

        return self::isIranVisitor();
    }

    /**
     * Strict IP / Geo check ONLY (ignores browser Accept-Language so Iranian users on VPN receive high-CPM USD ads).
     */
    public static function isIranVisitor(): bool
    {
        $cfPeer      = ClientIp::isCloudflarePeer();
        $trustedPeer = ClientIp::isTrustedPeer();
        $ip          = ClientIp::get();
        $cfCountryRaw = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        $xCountryRaw  = strtoupper(trim((string) ($_SERVER['HTTP_X_COUNTRY_CODE'] ?? '')));

        // Key on every input that affects the verdict so repeated calls (e.g.
        // SettingsManager::getAd() for each slot) are effectively free.
        $cacheKey = hash('sha256', $ip . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')
            . '|' . ($cfPeer ? 'cf' : '-') . '|' . ($trustedPeer ? 'tr' : '-')
            . '|' . $cfCountryRaw . '|' . $xCountryRaw);
        if (array_key_exists($cacheKey, self::$iranVisitorCache)) {
            return self::$iranVisitorCache[$cacheKey];
        }

        $result = self::computeIsIranVisitor($cfPeer, $trustedPeer, $ip, $cfCountryRaw, $xCountryRaw);
        self::$iranVisitorCache[$cacheKey] = $result;
        return $result;
    }

    private static function computeIsIranVisitor(
        bool $cfPeer,
        bool $trustedPeer,
        string $ip,
        string $cfCountryRaw,
        string $xCountryRaw
    ): bool {
        $cfCountry = $cfPeer ? $cfCountryRaw : ($trustedPeer ? $xCountryRaw : '');
        if ($cfCountry === 'IR') {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $irPrefixes = [
                '2.144.', '2.176.', '2.177.', '2.178.', '2.179.', '2.180.', '2.181.', '2.182.', '2.183.', '2.184.', '2.185.', '2.186.', '2.187.', '2.188.', '2.189.',
                '5.22.', '5.23.', '5.52.', '5.53.', '5.74.', '5.75.', '5.78.', '5.106.', '5.112.', '5.113.', '5.114.', '5.115.', '5.116.', '5.117.', '5.118.', '5.119.', '5.120.', '5.121.', '5.122.', '5.123.', '5.124.', '5.125.', '5.126.', '5.127.', '5.134.', '5.160.', '5.200.', '5.201.', '5.202.', '5.208.', '5.209.', '5.210.', '5.211.', '5.212.', '5.213.', '5.232.', '5.233.', '5.234.', '5.235.', '5.236.', '5.237.', '5.238.', '5.239.', '5.250.',
                '31.2.', '31.7.', '31.14.', '31.24.', '31.40.', '31.47.', '31.56.', '31.57.', '31.58.', '31.59.', '31.70.', '31.184.',
                '37.32.', '37.98.', '37.129.', '37.137.', '37.156.', '37.202.', '37.254.', '37.255.',
                '46.32.', '46.36.', '46.41.', '46.51.', '46.100.', '46.143.', '46.164.', '46.182.', '46.209.', '46.224.', '46.225.', '46.245.',
                '77.42.', '77.81.', '77.104.', '77.237.', '77.238.',
                '78.38.', '78.39.', '78.109.', '78.111.', '78.157.',
                '79.127.', '79.132.', '79.175.',
                '80.191.', '80.210.', '80.242.', '80.249.', '80.253.',
                '81.12.', '81.16.', '81.28.', '81.31.', '81.90.', '81.91.', '81.163.',
                '82.99.', '82.115.',
                '83.120.', '83.121.', '83.122.', '83.123.', '83.147.',
                '84.47.', '84.241.',
                '85.9.', '85.15.', '85.133.', '85.185.', '85.198.',
                '86.55.', '86.57.', '86.104.', '86.107.',
                '87.107.', '87.247.', '87.248.',
                '89.32.', '89.34.', '89.35.', '89.36.', '89.37.', '89.38.', '89.39.', '89.40.', '89.41.', '89.42.', '89.43.', '89.44.', '89.45.', '89.46.', '89.47.', '89.144.', '89.165.', '89.196.', '89.198.', '89.199.', '89.219.', '89.221.', '89.235.',
                '91.92.', '91.98.', '91.99.', '91.106.', '91.107.', '91.108.', '91.109.', '91.133.', '91.184.', '91.185.', '91.216.', '91.224.', '91.225.', '91.229.', '91.232.', '91.234.', '91.236.', '91.240.', '91.242.', '91.243.', '91.245.', '91.246.', '91.247.', '91.251.',
                '92.42.', '92.50.', '92.61.', '92.114.', '92.119.', '92.242.',
                '93.88.', '93.110.', '93.113.', '93.114.', '93.115.', '93.117.', '93.118.', '93.119.', '93.126.',
                '94.74.', '94.101.', '94.139.', '94.182.', '94.183.', '94.184.', '94.232.', '94.241.',
                '95.38.', '95.64.', '95.80.', '95.81.', '95.82.',
                '109.108.', '109.109.', '109.110.', '109.122.', '109.125.', '109.162.', '109.201.', '109.203.', '109.225.', '109.230.',
                '130.185.', '141.11.', '151.232.', '151.233.', '151.234.', '151.235.', '151.236.', '151.237.', '151.238.', '151.239.', '151.240.', '151.241.', '151.242.', '151.243.', '151.244.', '151.245.', '151.246.', '151.247.',
                '164.138.', '164.215.', '176.97.', '176.102.', '176.221.',
                '178.22.', '178.131.', '178.162.', '178.173.', '178.216.', '178.252.', '178.253.',
                '185.4.', '185.8.', '185.10.', '185.12.', '185.13.', '185.37.', '185.47.', '185.49.', '185.51.', '185.53.', '185.55.', '185.57.', '185.73.', '185.78.', '185.81.', '185.83.', '185.88.', '185.94.', '185.97.', '185.98.', '185.105.', '185.110.', '185.112.', '185.116.', '185.118.', '185.120.', '185.126.', '185.128.', '185.129.', '185.130.', '185.135.', '185.137.', '185.141.', '185.142.', '185.143.', '185.147.', '185.153.', '185.159.', '185.161.', '185.162.', '185.164.', '185.165.', '185.166.', '185.173.', '185.175.', '185.177.', '185.179.', '185.181.', '185.186.', '185.190.', '185.191.', '185.192.', '185.204.', '185.206.', '185.208.', '185.211.', '185.213.', '185.215.', '185.217.', '185.226.', '185.229.', '185.231.', '185.235.', '185.236.', '185.239.', '185.252.', '185.255.',
                '188.75.', '188.118.', '188.121.', '188.136.', '188.158.', '188.159.', '188.208.', '188.209.', '188.210.', '188.211.', '188.212.', '188.213.', '188.214.', '188.215.', '188.229.', '188.245.', '188.253.',
                '192.15.', '193.151.', '193.176.', '193.189.', '194.5.', '194.33.', '194.60.', '194.147.', '194.225.', '195.146.', '195.181.', '195.248.',
                '213.108.', '213.176.', '213.207.', '213.217.', '213.233.',
                '217.24.', '217.25.', '217.60.', '217.66.', '217.130.', '217.146.', '217.218.', '217.219.'
            ];
            foreach ($irPrefixes as $pfx) {
                if (str_starts_with($ip, $pfx)) {
                    return true;
                }
            }
        }

        return false;
    }
}

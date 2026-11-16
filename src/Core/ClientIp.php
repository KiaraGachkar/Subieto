<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

final class ClientIp
{
    /** @var string[] */
    private static array $trustedProxies = [];

    /** @var string[] Official Cloudflare IPv4/IPv6 edge ranges */
    private const CLOUDFLARE_CIDRS = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function configureTrustedProxies(string $csvList): void
    {
        $items = array_filter(array_map('trim', explode(',', $csvList)), static fn(string $v): bool => $v !== '');
        self::$trustedProxies = array_values($items);
    }

    public static function isTrustedPeer(): bool
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return $remote !== '' && !empty(self::$trustedProxies) && self::isTrustedProxy($remote);
    }

    public static function isCloudflarePeer(): bool
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return $remote !== '' && !empty(self::$trustedProxies) && self::isTrustedProxy($remote) && self::isCloudflareEdge($remote);
    }

    public static function get(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        if (empty(self::$trustedProxies) || !self::isTrustedProxy($remote)) {
            return $remote;
        }

        // Only honor CF-Connecting-IP when the direct peer is an authentic Cloudflare edge
        if (self::isCloudflareEdge($remote)) {
            $cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP) && !self::isTrustedProxy($cfIp)) {
                return $cfIp;
            }
        }

        // Walk X-Forwarded-For RIGHT-TO-LEFT and return the first non-trusted IP
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($parts as $candidate) {
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) && !self::isTrustedProxy($candidate)) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote !== '' && !empty(self::$trustedProxies) && self::isTrustedProxy($remote)) {
            return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }

        return false;
    }

    private static function isCloudflareEdge(string $ip): bool
    {
        foreach (self::CLOUDFLARE_CIDRS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function isTrustedProxy(string $ip): bool
    {
        foreach (self::$trustedProxies as $proxy) {
            if ($proxy === $ip) {
                return true;
            }
            if (str_contains($proxy, '/') && self::ipInCidr($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsStr] = explode('/', $cidr, 2);
        $bits = (int) $bitsStr;
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
            return false;
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}

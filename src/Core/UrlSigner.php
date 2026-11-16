<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

/**
 * Generates and verifies HMAC-SHA256 signed tokens for download URLs,
 * optionally bound to the visitor's IP address to prevent external hotlinking.
 */
final class UrlSigner
{
    public function __construct(private readonly string $secretKey)
    {
    }

    /**
     * @param array<string, string> $payload
     */
    public function sign(array $payload, int $ttlSeconds = 14400, ?string $clientIp = null): string
    {
        $payload['exp'] = (string) (time() + $ttlSeconds);
        if ($clientIp !== null && $clientIp !== '') {
            $payload['iph'] = substr(hash('sha256', $clientIp . '|' . $this->secretKey), 0, 24);
        }
        ksort($payload);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b64  = $this->base64UrlEncode($json ?: '{}');
        $sig  = hash_hmac('sha256', $b64, $this->secretKey);

        return $b64 . '.' . $sig;
    }

    /**
     * Callers must always pass the real client IP (\App\Core\ClientIp::get()),
     * even when the bind_ip_download setting is off: any token carrying an
     * "iph" claim is rejected when no client IP is supplied, so a bound token
     * can never be silently accepted from an unverified context.
     *
     * @return array<string, string>|null
     */
    public function verify(string $token, ?string $clientIp = null): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$b64, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64, $this->secretKey);

        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        $json = $this->base64UrlDecode($b64);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $exp = (int) ($data['exp'] ?? 0);
        if ($exp < time()) {
            return null;
        }

        if (!empty($data['iph'])) {
            if ($clientIp === null || $clientIp === '') {
                return null;
            }
            $expectedIpHash = substr(hash('sha256', $clientIp . '|' . $this->secretKey), 0, 24);
            if (!hash_equals((string) $data['iph'], $expectedIpHash)) {
                return null;
            }
        }

        $result = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $result[$k] = (string) $v;
            }
        }

        return $result;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $b64): ?string
    {
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($b64, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}

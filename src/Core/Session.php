<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @ini_set('session.gc_maxlifetime', '7200');
            session_start([
                'cookie_lifetime'  => 7200,
                'cookie_httponly'  => true,
                'cookie_secure'    => ClientIp::isHttps(),
                'cookie_samesite'  => 'Lax',
                'use_strict_mode'  => true,
                'use_only_cookies' => true,
            ]);
        }
    }

    public static function isAdmin(): bool
    {
        if (($_SESSION['is_admin'] ?? false) !== true) {
            return false;
        }
        if (time() - (int) ($_SESSION['admin_since'] ?? 0) > 7200) {
            self::destroy();
            return false;
        }
        return true;
    }

    public static function setAdmin(bool $status): void
    {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = $status;
        $_SESSION['admin_since'] = time();
        unset($_SESSION['csrf_token']);
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $p['path'],
                    $p['domain'],
                    (bool) $p['secure'],
                    (bool) $p['httponly']
                );
            }
            session_destroy();
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['flash'][$key] = $message;
            return null;
        }

        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
}

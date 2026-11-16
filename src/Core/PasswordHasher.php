<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

final class PasswordHasher
{
    public static function hash(string $plainPassword): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plainPassword, $algo);
    }

    public static function verify(string $plainPassword, string $hash): bool
    {
        return $hash !== '' && password_verify($plainPassword, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }

    public static function activeAlgorithmName(): string
    {
        return defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'Bcrypt';
    }
}

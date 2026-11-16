<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

defined('APP_SECURE_BOOT') or exit;

final class Database
{
    /**
     * @param array<string, mixed> $config
     */
    public static function connect(array $config): PDO
    {
        $host    = (string) ($config['host'] ?? '127.0.0.1');
        $port    = (int) ($config['port'] ?? 3306);
        $dbname  = (string) ($config['database'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            $options[PDO::MYSQL_ATTR_LOCAL_INFILE] = false;
        }

        return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
    }

    public static function connectServer(string $host, int $port, string $username, string $password): PDO
    {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            $options[PDO::MYSQL_ATTR_LOCAL_INFILE] = false;
        }

        return new PDO($dsn, $username, $password, $options);
    }
}

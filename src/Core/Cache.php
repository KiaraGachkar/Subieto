<?php

declare(strict_types=1);

namespace App\Core;

use APCUIterator;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

/**
 * Hybrid Cache layer: checks APCu in-memory cache first if available,
 * then falls back to MySQL api_cache table.
 */
final class Cache
{
    private bool $hasApcu;

    /** @var array<string, array{type: string, value?: string, fp?: resource}> */
    private array $heldLocks = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_',
        private readonly string $fallbackDir = '',
        private bool $persistToDatabase = true
    ) {
        $this->hasApcu = function_exists('apcu_enabled') && apcu_enabled();
        if ($this->fallbackDir !== '' && !is_dir($this->fallbackDir)) {
            @mkdir($this->fallbackDir, 0755, true);
        }
    }

    /**
     * Enable/disable dual-writing cache entries to the database at runtime.
     * Kept as a runtime setter so it can be toggled from the admin UI.
     */
    public function setDatabasePersistence(bool $enabled): void
    {
        $this->persistToDatabase = $enabled;
    }

    public function isDatabasePersistenceEnabled(): bool
    {
        return $this->persistToDatabase;
    }

    /**
     * Acquire a short-lived mutual-exclusion lock for $key.
     *
     * Uses APCu when available; otherwise falls back to a file lock whose name
     * is a SHA-256 hash of the key (so caller-supplied keys can never turn into
     * a path-traversal filename). Always pair with unlock() in a finally block.
     */
    public function lock(string $key, int $ttl = 30): bool
    {
        $token = $this->prefix . 'lk_' . hash('sha256', $key);
        if (isset($this->heldLocks[$token])) {
            return true;
        }

        if ($this->hasApcu) {
            $marker = bin2hex(random_bytes(8));
            if (@apcu_add($token, $marker, max(1, $ttl))) {
                $this->heldLocks[$token] = ['type' => 'apcu', 'value' => $marker];
                return true;
            }
            return false;
        }

        $dir = ($this->fallbackDir !== '' ? $this->fallbackDir : sys_get_temp_dir()) . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return false;
        }

        $fp = @fopen($dir . '/' . hash('sha256', $key) . '.lock', 'c');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        $this->heldLocks[$token] = ['type' => 'file', 'fp' => $fp];
        return true;
    }

    /**
     * Release a lock previously acquired by this instance. Safe to call even
     * when acquisition failed (it is then a no-op and never touches another
     * process's lock).
     */
    public function unlock(string $key): void
    {
        $token = $this->prefix . 'lk_' . hash('sha256', $key);
        $held = $this->heldLocks[$token] ?? null;
        if ($held === null) {
            return;
        }
        unset($this->heldLocks[$token]);

        try {
            if ($held['type'] === 'file' && is_resource($held['fp'])) {
                flock($held['fp'], LOCK_UN);
                fclose($held['fp']);
            } elseif ($held['type'] === 'apcu') {
                $current = apcu_fetch($token);
                if ($current === $held['value']) {
                    @apcu_delete($token);
                }
            }
        } catch (Throwable) {
            // Never let lock cleanup disrupt a request
        }
    }

    public function get(string $key): mixed
    {
        $apcuKey = $this->prefix . 'cc_' . hash('sha256', $key);

        if ($this->hasApcu) {
            $success = false;
            $val = apcu_fetch($apcuKey, $success);
            if ($success !== false) {
                return $val;
            }
        }

        $hash = hash('sha256', $key);
        try {
            $stmt = $this->pdo->prepare("SELECT `payload`, `expires_at` FROM `{$this->prefix}api_cache` WHERE `cache_key` = :k LIMIT 1");
            $stmt->execute([':k' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return null;
            }

            if ((int) $row['expires_at'] < time()) {
                $del = $this->pdo->prepare("DELETE FROM `{$this->prefix}api_cache` WHERE `cache_key` = :k");
                $del->execute([':k' => $hash]);
                return null;
            }

            $data = json_decode((string) $row['payload'], true);
            if ($this->hasApcu) {
                $ttlLeft = (int) $row['expires_at'] - time();
                if ($ttlLeft > 0) {
                    @apcu_store($apcuKey, $data, $ttlLeft);
                }
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batched multi-get: one DB SELECT for all keys that missed APCu.
     * Falls back to the DB with the same JSON encode/decode semantics as get().
     *
     * @param array<int|string, string> $keys
     * @return array<string, mixed> map of original key => cached value (only hits)
     */
    public function getMany(array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn(mixed $k): bool => is_string($k) && $k !== ''));
        if ($keys === []) {
            return [];
        }

        $out     = [];
        $missing = [];
        foreach ($keys as $key) {
            if ($this->hasApcu) {
                $success = false;
                $val = apcu_fetch($this->prefix . 'cc_' . hash('sha256', $key), $success);
                if ($success !== false) {
                    $out[$key] = $val;
                    continue;
                }
            }
            $missing[hash('sha256', $key)] = $key;
        }

        if ($missing === []) {
            return $out;
        }

        try {
            $hashes = array_keys($missing);
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT `cache_key`, `payload`, `expires_at` FROM `{$this->prefix}api_cache`
                 WHERE `cache_key` IN ({$placeholders})"
            );
            $stmt->execute($hashes);
            $now = time();

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hash = (string) $row['cache_key'];
                $key  = $missing[$hash] ?? null;
                if ($key === null || (int) $row['expires_at'] < $now) {
                    continue;
                }

                $data = json_decode((string) $row['payload'], true);
                $out[$key] = $data;
                if ($this->hasApcu) {
                    $ttlLeft = (int) $row['expires_at'] - $now;
                    if ($ttlLeft > 0) {
                        @apcu_store($this->prefix . 'cc_' . $hash, $data, $ttlLeft);
                    }
                }
            }
        } catch (Throwable) {
            // Ignore; APCu hits (if any) are still returned
        }

        return $out;
    }

    /**
     * Batched multi-set: one batched upsert into the DB plus APCu stores.
     * Honours the same database-persistence flag as set().
     *
     * @param array<string, mixed> $values map of key => value
     */
    public function setMany(array $values, int $ttl = 21600): void
    {
        if ($ttl <= 0 || $values === []) {
            return;
        }

        $rows = [];
        foreach ($values as $key => $data) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                continue;
            }
            if ($this->hasApcu) {
                @apcu_store($this->prefix . 'cc_' . hash('sha256', $key), $data, $ttl);
            }
            $rows[] = [hash('sha256', $key), $json, time() + $ttl];
        }

        if ($rows === [] || !$this->persistToDatabase) {
            return;
        }

        try {
            $placeholders = [];
            $bindings     = [];
            foreach ($rows as $row) {
                $placeholders[] = '(?, ?, ?)';
                $bindings[]     = $row[0];
                $bindings[]     = $row[1];
                $bindings[]     = $row[2];
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}api_cache` (`cache_key`, `payload`, `expires_at`) VALUES "
                . implode(',', $placeholders)
                . " ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `expires_at` = VALUES(`expires_at`)"
            );
            $stmt->execute($bindings);
        } catch (Throwable) {
            // Ignore
        }

        if (random_int(1, 2000) === 1) {
            $this->pruneExpired();
        }
    }

    public function set(string $key, mixed $data, int $ttl = 21600): void
    {
        if ($ttl <= 0) {
            return;
        }

        $apcuKey = $this->prefix . 'cc_' . hash('sha256', $key);
        if ($this->hasApcu) {
            @apcu_store($apcuKey, $data, $ttl);
        }

        if (!$this->persistToDatabase) {
            return;
        }

        $hash = hash('sha256', $key);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        if (random_int(1, 2000) === 1) {
            $this->pruneExpired();
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}api_cache` (`cache_key`, `payload`, `expires_at`)
                 VALUES (:k, :p, :e)
                 ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `expires_at` = VALUES(`expires_at`)"
            );
            $stmt->execute([
                ':k' => $hash,
                ':p' => $json,
                ':e' => time() + $ttl,
            ]);
        } catch (Throwable) {
            // Ignore
        }
    }

    public function clear(): int
    {
        $count = $this->countItems();
        $this->pdo->exec("TRUNCATE TABLE `{$this->prefix}api_cache`");

        if ($this->hasApcu && class_exists(APCUIterator::class)) {
            @apcu_delete(new APCUIterator('/^' . preg_quote($this->prefix, '/') . 'cc_/'));
        }

        return $count;
    }

    /**
     * Explicit, safe expiry cleanup for the DB-backed cache. Previously this
     * ran inline on ~0.5% of set() calls; callers may now schedule it directly.
     */
    public function pruneExpired(): int
    {
        try {
            return $this->clearExpired();
        } catch (Throwable) {
            return 0;
        }
    }

    public function clearExpired(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}api_cache` WHERE `expires_at` < :now");
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }

    public function countItems(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}api_cache`");
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}

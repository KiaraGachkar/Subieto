<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class RateLimiter
{
    private const MAX_ATTEMPTS        = 5;
    private const GLOBAL_MAX_ATTEMPTS = 100;
    private const LOCKOUT_WINDOW      = 900; // 15 minutes

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_'
    ) {
    }

    public function allow(string $action, int $maxHits, int $windowSeconds = 60, bool $failOpen = true): bool
    {
        $safeWindow = max(1, $windowSeconds);
        $win = (intdiv(time(), $safeWindow) + 1) * $safeWindow;
        $key = hash('sha256', ClientIp::get() . '|' . $action . '|' . $win);

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $apcuKey = $this->prefix . 'rl_' . $key;
            if (apcu_add($apcuKey, 1, $windowSeconds + 5)) {
                return 1 <= $maxHits;
            }
            $count = apcu_inc($apcuKey);
            if ($count !== false) {
                return $count <= $maxHits;
            }
        }

        if (random_int(1, 150) === 1) {
            $this->cleanupOldWindows();
        }

        return $this->hitDatabaseWindow($key, $win, $maxHits, true, $failOpen);
    }

    private function hitDatabaseWindow(string $key, int $win, int $maxHits, bool $allowAutoCreate, bool $failOpen = true): bool
    {
        $table = $this->prefix . 'rate_hits';
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$table}` (`k`, `win`, `hits`) VALUES (:k, :w, 1)
                 ON DUPLICATE KEY UPDATE `hits` = `hits` + 1"
            );
            $stmt->execute([':k' => $key, ':w' => $win]);

            $sel = $this->pdo->prepare("SELECT `hits` FROM `{$table}` WHERE `k` = :k AND `win` = :w LIMIT 1");
            $sel->execute([':k' => $key, ':w' => $win]);
            $hits = (int) $sel->fetchColumn();

            return $hits <= $maxHits;
        } catch (PDOException $e) {
            if ($allowAutoCreate && ($e->getCode() === '42S02' || str_contains($e->getMessage(), '42S02'))) {
                $this->ensureRateHitsTable();
                return $this->hitDatabaseWindow($key, $win, $maxHits, false, $failOpen);
            }
            return $failOpen;
        } catch (Throwable) {
            return $failOpen;
        }
    }

    public function ensureRateHitsTable(): void
    {
        try {
            $table = $this->prefix . 'rate_hits';
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$table}` (
                    `k` CHAR(64) NOT NULL,
                    `win` INT UNSIGNED NOT NULL,
                    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
                    PRIMARY KEY (`k`, `win`),
                    INDEX `idx_win` (`win`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable) {
            // Ignore
        }
    }

    public function cleanupOldWindows(): void
    {
        try {
            $cutoff = time();
            $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}rate_hits` WHERE `win` < :cutoff");
            $stmt->execute([':cutoff' => $cutoff]);
        } catch (Throwable) {
            // Ignore
        }
    }

    /**
     * Remaining lockout seconds for $action. Only the per-IP bucket is checked
     * by default. The optional global bucket is kept for non-login actions;
     * login must remain per-IP so remote IPs cannot lock out all admins.
     */
    public function getRemainingLockSeconds(string $action = 'admin_login', bool $includeGlobal = false): int
    {
        $ipLock = $this->checkBucketLock(hash('sha256', ClientIp::get() . '|' . $action), self::MAX_ATTEMPTS);
        if (!$includeGlobal) {
            return $ipLock;
        }
        $globalLock = $this->checkBucketLock(hash('sha256', 'GLOBAL|' . $action), self::GLOBAL_MAX_ATTEMPTS);
        return max($ipLock, $globalLock);
    }

    public function hit(string $action = 'admin_login', bool $includeGlobal = false): void
    {
        $this->hitBucket(hash('sha256', ClientIp::get() . '|' . $action));
        if ($includeGlobal) {
            $this->hitBucket(hash('sha256', 'GLOBAL|' . $action));
        }
    }

    public function clear(string $action = 'admin_login', bool $includeGlobal = false): void
    {
        $this->clearBucket(hash('sha256', ClientIp::get() . '|' . $action));
        if ($includeGlobal) {
            $this->clearBucket(hash('sha256', 'GLOBAL|' . $action));
        }
    }

    private function checkBucketLock(string $bucketHash, int $threshold): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `attempts`, `last_attempt_at` FROM `{$this->prefix}login_attempts` WHERE `ip_hash` = :h LIMIT 1"
            );
            $stmt->execute([':h' => $bucketHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return 0;
            }

            $attempts = (int) $row['attempts'];
            $lastTime = (int) $row['last_attempt_at'];
            $elapsed  = time() - $lastTime;

            if ($elapsed > self::LOCKOUT_WINDOW) {
                $this->clearBucket($bucketHash);
                return 0;
            }

            if ($attempts >= $threshold) {
                return max(0, self::LOCKOUT_WINDOW - $elapsed);
            }

            return 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function hitBucket(string $bucketHash): void
    {
        $now = time();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}login_attempts` (`ip_hash`, `attempts`, `last_attempt_at`)
                 VALUES (:h, 1, :t)
                 ON DUPLICATE KEY UPDATE
                    `attempts` = IF(:t2 - `last_attempt_at` > :win, 1, `attempts` + 1),
                    `last_attempt_at` = IF(:t3 - `last_attempt_at` > :win2, :t4, `last_attempt_at`)"
            );
            $stmt->execute([
                ':h'    => $bucketHash,
                ':t'    => $now,
                ':t2'   => $now,
                ':win'  => self::LOCKOUT_WINDOW,
                ':t3'   => $now,
                ':win2' => self::LOCKOUT_WINDOW,
                ':t4'   => $now,
            ]);
        } catch (Throwable) {
            // Ignore
        }
    }

    private function clearBucket(string $bucketHash): void
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}login_attempts` WHERE `ip_hash` = :h");
            $stmt->execute([':h' => $bucketHash]);
        } catch (Throwable) {
            // Ignore
        }
    }
}

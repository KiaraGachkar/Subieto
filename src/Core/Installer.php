<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class Installer
{
    private const ALLOW_INSTALL_ENV = 'SUBIETO_ALLOW_INSTALL';

    public function __construct(private readonly string $basePath)
    {
    }

    public function isInstalled(): bool
    {
        return $this->lockExists() && $this->configExists();
    }

    /**
     * Installation is an explicit opt-in and only valid on a completely fresh
     * setup: SUBIETO_ALLOW_INSTALL=1 must be set AND neither the lock file nor
     * the DB config may exist. Off by default so /install cannot take over a
     * live deployment when installed.lock is missing or removed.
     */
    public function isInstallAllowed(): bool
    {
        if (getenv(self::ALLOW_INSTALL_ENV) !== '1') {
            return false;
        }
        if ($this->isInstalled()) {
            return false;
        }
        return !$this->lockExists() && !$this->configExists();
    }

    private function lockExists(): bool
    {
        return file_exists($this->basePath . '/storage/installed.lock');
    }

    private function configExists(): bool
    {
        return file_exists($this->basePath . '/config/database.php');
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    public function checkEnvironment(): array
    {
        $storageDir = $this->basePath . '/storage';
        $configDir  = $this->basePath . '/config';

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }

        return [
            [
                'label'  => 'نسخه PHP (حداقل 8.1)',
                'ok'     => version_compare(PHP_VERSION, '8.1.0', '>='),
                'detail' => 'نسخه فعلی: ' . PHP_VERSION,
            ],
            [
                'label'  => 'افزونه PDO & MySQL (pdo_mysql)',
                'ok'     => extension_loaded('pdo') && extension_loaded('pdo_mysql'),
                'detail' => extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال',
            ],
            [
                'label'  => 'افزونه cURL (ارتباط موازی با APIها)',
                'ok'     => extension_loaded('curl'),
                'detail' => extension_loaded('curl') ? 'فعال' : 'غیرفعال',
            ],
            [
                'label'  => 'افزونه mbstring (تبدیل خودکار UTF-8)',
                'ok'     => extension_loaded('mbstring'),
                'detail' => extension_loaded('mbstring') ? 'فعال' : 'غیرفعال',
            ],
            [
                'label'  => 'دسترسی نوشتن در پوشه storage/',
                'ok'     => is_writable($storageDir),
                'detail' => is_writable($storageDir) ? 'قابل نوشتن' : 'نیاز به دسترسی 755 یا 775',
            ],
            [
                'label'  => 'دسترسی نوشتن در پوشه config/',
                'ok'     => is_writable($configDir),
                'detail' => is_writable($configDir) ? 'قابل نوشتن' : 'نیاز به دسترسی 755 یا 775',
            ],
        ];
    }

    /**
     * @param array<string, string> $input
     * @return array{success: bool, message: string}
     */
    public function install(array $input): array
    {
        if ($this->isInstalled()) {
            return ['success' => false, 'message' => 'Already installed.'];
        }
        if (!$this->isInstallAllowed()) {
            return [
                'success' => false,
                'message' => 'Installation is not allowed. Set SUBIETO_ALLOW_INSTALL=1 on a fresh setup to enable the installer.',
            ];
        }
        foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix', 'admin_password', 'site_title', 'site_base_url'] as $k) {
            if (isset($input[$k]) && !is_string($input[$k])) {
                return ['success' => false, 'message' => 'Invalid input format.'];
            }
        }
        $host     = trim((string) ($input['db_host'] ?? '127.0.0.1'));
        if (!preg_match('/^[a-zA-Z0-9.-]{1,255}$/', $host)) {
            return ['success' => false, 'message' => 'Invalid database host.'];
        }
        $port     = max(1, min(65535, (int) ($input['db_port'] ?? 3306)));
        $dbName   = trim($input['db_name'] ?? '');
        $dbUser   = trim($input['db_user'] ?? '');
        $dbPass   = $input['db_pass'] ?? '';
        $prefix   = trim($input['db_prefix'] ?? 'sh_');

        $siteTitle = trim($input['site_title'] ?? 'Subieto');
        $rawBase   = rtrim(trim($input['site_base_url'] ?? ''), '/');
        $adminPass = trim($input['admin_password'] ?? '');
        $tmdbKey   = trim($input['tmdb_api_key'] ?? '');
        $subdlKey  = trim($input['subdl_api_key'] ?? '');
        $osKey     = trim($input['opensubtitles_api_key'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
            return ['success' => false, 'message' => 'نام دیتابیس فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد.'];
        }

        if ($dbUser === '') {
            return ['success' => false, 'message' => 'نام کاربری دیتابیس الزامی است.'];
        }

        if (strlen($adminPass) < 8) {
            return ['success' => false, 'message' => 'برای امنیت بیشتر، رمز عبور مدیریت باید حداقل ۸ کاراکتر باشد.'];
        }

        if ($prefix === '') {
            $prefix = 'sh_';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{1,20}$/', $prefix)) {
            return ['success' => false, 'message' => 'پیشوند جداول نامعتبر است.'];
        } elseif (!str_ends_with($prefix, '_')) {
            $prefix .= '_';
        }

        try {
            $serverPdo = Database::connectServer($host, $port, $dbUser, $dbPass);
            $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $pdo = Database::connect([
                'host'     => $host,
                'port'     => $port,
                'database' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
                'charset'  => 'utf8mb4',
            ]);

            $this->runMigrations($pdo, $prefix);

            $hmacSecret = bin2hex(random_bytes(32));

            $this->seedSettings($pdo, $prefix, [
                'site.title'                  => $siteTitle !== '' ? $siteTitle : 'Subieto',
                'site.base_url'               => (preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $rawBase) ? $rawBase : \App\Services\SeoHelper::getBaseUrl()),
                'site.description'            => 'Fast direct movie and TV series subtitle downloads in clean UTF-8 SRT format.',
                'site.download_mode'          => 'stream',
                'site.default_theme'          => 'light',
                'site.cache_ttl'              => '21600',
                'app.secret_key'              => $hmacSecret,
                'security.bind_ip_download'   => '0',
                'security.trusted_proxies'    => '',
                'apis.tmdb_api_key'           => $tmdbKey,
                'apis.subdl_api_key'          => $subdlKey,
                'apis.opensubtitles_api_key'  => $osKey,
                'providers.free_opensub_enabled' => '1',
                'providers.podnapisi_enabled'    => '1',
                'providers.yify_enabled'         => '1',
                'providers.subf2m_enabled'       => '1',
                'providers.subzone_enabled'      => '1',
                'providers.subkade_enabled'      => '1',
                'providers.priority_languages'   => 'en,fa,ar,es,fr,tr',
                'seo.movie_title_tpl'         => 'دانلود زیرنویس فارسی {title} {fa_title} ({year}) | مستقیم و رایگان',
                'seo.google_verification'     => '',
                'seo.keywords'                => 'دانلود زیرنویس فارسی, زیرنویس فیلم, زیرنویس سریال, زیرنویس هماهنگ',
                'ads.enabled'                      => '1',
                'ads.sample_preview'               => '0',
                'ads.head_scripts_ir'              => '',
                'ads.adblock_notice'               => '0',
                'ads.adblock_message'              => 'لطفاً برای حمایت از ما و ادامه فعالیت رایگان سایت، افزونه مسدودکننده تبلیغات (AdBlock) را غیرفعال کنید.',
                'ads.head_scripts'                 => '',
                'ads.header_banner'                => '',
                'ads.header_banner_mobile'         => '',
                'ads.header_banner_expires'        => '',
                'ads.home_between'                 => '',
                'ads.home_between_mobile'          => '',
                'ads.home_between_expires'         => '',
                'ads.movie_sidebar'                => '',
                'ads.movie_sidebar_mobile'         => '',
                'ads.movie_sidebar_expires'        => '',
                'ads.movie_above_table'            => '',
                'ads.movie_above_table_mobile'     => '',
                'ads.movie_above_table_expires'    => '',
                'ads.movie_below_table'            => '',
                'ads.movie_below_table_mobile'     => '',
                'ads.movie_below_table_expires'    => '',
                'ads.sticky_footer_mobile'         => '',
                'ads.sticky_footer_mobile_expires' => '',
                'admin.password_hash'         => PasswordHasher::hash($adminPass),
            ]);

            $configExport = var_export([
                'host'     => $host,
                'port'     => $port,
                'database' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
                'prefix'   => $prefix,
                'charset'  => 'utf8mb4',
            ], true);

            $configContent = "<?php\n\ndeclare(strict_types=1);\n\ndefined('APP_SECURE_BOOT') or exit;\n\nreturn {$configExport};\n";

            if (file_put_contents($this->basePath . '/config/database.php', $configContent, LOCK_EX) === false) {
                return ['success' => false, 'message' => 'خطا در نوشتن فایل config/database.php. دسترسی پوشه config را بررسی کنید.'];
            }

            @mkdir($this->basePath . '/storage/cache', 0755, true);
            @mkdir($this->basePath . '/storage/pages', 0755, true);
            @mkdir($this->basePath . '/storage/logs', 0755, true);
            file_put_contents(
                $this->basePath . '/storage/installed.lock',
                'Installed at ' . date('Y-m-d H:i:s'),
                LOCK_EX
            );

            return ['success' => true, 'message' => 'نصب با موفقیت کامل انجام شد!'];
        } catch (Throwable $e) {
            error_log('[installer] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در اتصال به دیتابیس یا ساخت جداول. لطفاً اطلاعات هاست، نام دیتابیس و دسترسی‌ها را بررسی کنید.',
            ];
        }
    }

    private function runMigrations(PDO $pdo, string $prefix): void
    {
        $sqlStatements = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
                `key_name` VARCHAR(100) NOT NULL,
                `key_value` MEDIUMTEXT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`key_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}api_cache` (
                `cache_key` CHAR(64) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`cache_key`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}download_stats` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `imdb_id` VARCHAR(20) NOT NULL DEFAULT '',
                `media_title` VARCHAR(255) NOT NULL DEFAULT '',
                `provider` VARCHAR(50) NOT NULL DEFAULT '',
                `language_code` VARCHAR(10) NOT NULL DEFAULT 'fa',
                `file_name` VARCHAR(255) NOT NULL DEFAULT '',
                `downloaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_imdb` (`imdb_id`),
                INDEX `idx_imdb_title` (`imdb_id`, `media_title`(100)),
                INDEX `idx_provider` (`provider`),
                INDEX `idx_language` (`language_code`),
                INDEX `idx_downloaded_at` (`downloaded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}rate_hits` (
                `k` CHAR(64) NOT NULL,
                `win` INT UNSIGNED NOT NULL,
                `hits` INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`k`, `win`),
                INDEX `idx_win` (`win`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}login_attempts` (
                `ip_hash` CHAR(64) NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
                `last_attempt_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`ip_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}daily_metrics` (
                `metric_date` DATE NOT NULL,
                `page_views` INT UNSIGNED NOT NULL DEFAULT 0,
                `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`metric_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}indexed_media` (
                `imdb_id` VARCHAR(20) NOT NULL,
                `slug` VARCHAR(200) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `persian_title` VARCHAR(255) NOT NULL DEFAULT '',
                `media_type` VARCHAR(10) NOT NULL DEFAULT 'movie',
                `year` VARCHAR(10) NOT NULL DEFAULT '',
                `seasons_json` VARCHAR(500) NOT NULL DEFAULT '',
                `langs_json` VARCHAR(500) NOT NULL DEFAULT '',
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`imdb_id`),
                INDEX `idx_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}ad_clicks` (
                `slot_name` VARCHAR(60) NOT NULL,
                `clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`slot_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}missing_subtitles` (
                `imdb_id` VARCHAR(20) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `persian_title` VARCHAR(255) NOT NULL DEFAULT '',
                `media_type` VARCHAR(10) NOT NULL DEFAULT 'movie',
                `year` VARCHAR(10) NOT NULL DEFAULT '',
                `requests_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `last_requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`imdb_id`),
                INDEX `idx_requests` (`requests_count`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];

        foreach ($sqlStatements as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * @param array<string, string> $defaults
     */
    private function seedSettings(PDO $pdo, string $prefix, array $defaults): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO `{$prefix}settings` (`key_name`, `key_value`)
             VALUES (:key_name, :key_value)
             ON DUPLICATE KEY UPDATE `key_value` = VALUES(`key_value`)"
        );

        foreach ($defaults as $key => $val) {
            $stmt->execute([
                ':key_name'  => $key,
                ':key_value' => $val,
            ]);
        }
    }
}

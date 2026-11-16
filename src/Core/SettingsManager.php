<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\LocaleService;
use PDO;

defined('APP_SECURE_BOOT') or exit;

final class SettingsManager
{
    /** @var array<string, string> */
    private array $flat = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'sh_'
    ) {
        $this->load();
    }

    private function load(): void
    {
        $stmt = $this->pdo->query("SELECT `key_name`, `key_value` FROM `{$this->prefix}settings`");
        if ($stmt === false) {
            return;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->flat[(string) $row['key_name']] = (string) ($row['key_value'] ?? '');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->flat) ? $this->flat[$key] : $default;
    }

    public function getAd(string $slotKey): string
    {
        if (($this->flat['ads.enabled'] ?? '1') !== '1') {
            return '';
        }

        $expires = trim((string) ($this->flat['ads.' . $slotKey . '_expires'] ?? ''));
        if ($expires !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            if ($expires < date('Y-m-d')) {
                return '';
            }
        }

        $isIran = LocaleService::isIranVisitor();
        $irCode = trim((string) ($this->flat['ads.' . $slotKey . '_ir'] ?? ''));

        // If visitor is from Iran and an Iran-specific ad code is configured for this slot, serve it
        if ($isIran && $irCode !== '') {
            return $irCode;
        }

        $desktopCode = trim((string) ($this->flat['ads.' . $slotKey] ?? ''));
        $mobileCode  = trim((string) ($this->flat['ads.' . $slotKey . '_mobile'] ?? ''));

        if ($desktopCode === '' && $mobileCode === '' && $irCode === '') {
            if (($this->flat['ads.sample_preview'] ?? '0') === '1' && $slotKey !== 'head_scripts') {
                return $this->renderSampleAd($slotKey, $isIran);
            }
            return '';
        }

        if ($slotKey === 'head_scripts' || $slotKey === 'sticky_footer_mobile' || $mobileCode === '') {
            return $desktopCode;
        }

        if ($desktopCode === '') {
            return '<div class="md:hidden w-full flex justify-center">' . $mobileCode . '</div>';
        }

        return '<div class="hidden md:flex w-full justify-center">' . $desktopCode . '</div>'
             . '<div class="flex md:hidden w-full justify-center">' . $mobileCode . '</div>';
    }

    private function renderSampleAd(string $slotKey, bool $isIran = false): string
    {
        $specs = [
            'header_banner'        => ['title' => 'Header Leaderboard', 'size' => '728 × 90 • 320 × 50', 'h' => 'min-h-[76px]', 'icon' => 'web_asset'],
            'home_below_search'    => ['title' => 'Hero Search Strip', 'size' => '728 × 90 Responsive', 'h' => 'min-h-[76px]', 'icon' => 'campaign'],
            'home_between'         => ['title' => 'Home Mid-Feed Banner', 'size' => '970 × 90 • 728 × 90', 'h' => 'min-h-[84px]', 'icon' => 'view_agenda'],
            'home_footer_banner'   => ['title' => 'Home Bottom Native Grid', 'size' => 'Responsive Native / 728 × 90', 'h' => 'min-h-[92px]', 'icon' => 'grid_view'],
            'search_top_banner'    => ['title' => 'Search Intent Banner', 'size' => '728 × 90 • High Intent', 'h' => 'min-h-[76px]', 'icon' => 'ads_click'],
            'movie_sidebar'        => ['title' => 'Media Box Rectangle', 'size' => '300 × 250 MPU', 'h' => 'min-h-[185px]', 'icon' => 'crop_square'],
            'movie_above_table'    => ['title' => 'Pre-Download Banner (Top CTR)', 'size' => '728 × 90 • 320 × 100', 'h' => 'min-h-[84px]', 'icon' => 'bolt'],
            'movie_in_feed'        => ['title' => 'In-Feed Sponsored Row', 'size' => 'Native Row • Blends with List', 'h' => 'min-h-[64px]', 'icon' => 'view_list'],
            'movie_below_table'    => ['title' => 'Post-List Native Recommendations', 'size' => 'Multi-Card Native Widget', 'h' => 'min-h-[104px]', 'icon' => 'dashboard'],
            'sticky_footer_mobile' => ['title' => 'Mobile Sticky Anchor', 'size' => '320 × 50 Fixed', 'h' => 'min-h-[50px]', 'icon' => 'smartphone'],
        ];
        $info = $specs[$slotKey] ?? ['title' => 'Ad Slot', 'size' => 'Responsive', 'h' => 'min-h-[72px]', 'icon' => 'ad_units'];
        $geoTag = $isIran ? 'GEO: IRAN (IRR)' : 'GEO: GLOBAL (USD)';

        return '<div class="w-full ' . $info['h'] . ' rounded-2xl bg-gradient-to-r from-[#faf7f2] via-white to-[#f4efe6] dark:from-[#191b20] dark:via-[#1e2127] dark:to-[#17191e] border border-[#e5dfd3] dark:border-[#2c303a] px-4 py-3 flex items-center justify-between gap-3 select-none relative overflow-hidden">'
             . '<div class="flex items-center gap-3 min-w-0">'
             . '<div class="w-9 h-9 rounded-xl bg-[#2e6f40]/10 dark:bg-emerald-500/15 text-[#2e6f40] dark:text-emerald-400 flex items-center justify-center shrink-0">'
             . '<span class="material-symbols-outlined text-[19px]">' . $info['icon'] . '</span>'
             . '</div>'
             . '<div class="min-w-0 text-left" dir="ltr">'
             . '<div class="text-xs font-bold text-terra-ink dark:text-night-ink truncate">' . htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8') . '</div>'
             . '<div class="text-[10px] font-mono text-terra-muted dark:text-night-muted truncate">' . htmlspecialchars($info['size'], ENT_QUOTES, 'UTF-8') . '</div>'
             . '</div>'
             . '</div>'
             . '<span class="shrink-0 px-2.5 py-1 rounded-full bg-[#efeae1] dark:bg-[#252830] text-[9px] font-mono font-bold uppercase tracking-widest text-terra-muted dark:text-night-muted">' . $geoTag . '</span>'
             . '</div>';
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->flat;
    }

    /**
     * @param array<string, string> $pairs
     */
    public function updateMany(array $pairs): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->prefix}settings` (`key_name`, `key_value`)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `key_value` = VALUES(`key_value`)"
        );

        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
            $this->flat[$k] = $v;
        }
    }
}

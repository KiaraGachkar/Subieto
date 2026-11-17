<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\Session;
use App\Core\View;
/** @var array<string, string> $config */
/** @var int $cacheCount */
/** @var int $staticPagesCount */
/** @var int $indexedCount */
/** @var int $totalDownloads */
/** @var array<int, array<string, mixed>> $langBreakdown */
/** @var array<int, array<string, mixed>> $providerBreakdown */
/** @var array<int, array<string, string>> $recentDownloads */
/** @var array<int, array<string, mixed>> $topMedia */
/** @var array<int, array<string, mixed>> $dailyMetrics */
/** @var array<string, int> $adClicksMap */
/** @var array<int, array<string, mixed>> $missingSubs */
/** @var string|null $success */
/** @var string|null $error */
/** @var string|null $adminLocale */

$isFa = (($adminLocale ?? 'fa') === 'fa');
$t = static fn(string $fa, string $en): string => $isFa ? $fa : $en;

$todayViews = (int) (($dailyMetrics[count($dailyMetrics) - 1]['page_views'] ?? 0));
$todayDls   = (int) (($dailyMetrics[count($dailyMetrics) - 1]['downloads'] ?? 0));
$convRate   = $todayViews > 0 ? round(($todayDls / $todayViews) * 100, 1) : 0.0;
$totalAdClicks = array_sum($adClicksMap);

$maxChartVal = 1;
foreach ($dailyMetrics as $dm) {
    $maxChartVal = max($maxChartVal, (int) $dm['page_views'], (int) $dm['downloads']);
}

$adSlotsMeta = [
    'header_banner'        => ['label' => $t('۱. بنر بالای سایت (Header Leaderboard)', '1. Header Leaderboard Banner'), 'badge' => $t('سراسری', 'Global'), 'dual' => true, 'hint' => '728x90 Desktop / 320x50 Mobile'],
    'home_below_search'    => ['label' => $t('۲. بنر زیر کادر جستجوی صفحه اصلی', '2. Home Below-Search Strip'), 'badge' => $t('صفحه اصلی', 'Home Hero'), 'dual' => true, 'hint' => $t('بلافاصله زیر کادر جستجوی اصلی نمایش داده می‌شود', 'Directly beneath the main search bar')],
    'home_between'         => ['label' => $t('۳. بنر میانی صفحه اصلی', '3. Home Mid-Page Banner'), 'badge' => $t('صفحه اصلی', 'Home'), 'dual' => true, 'hint' => $t('بین فیلم‌ها و سریال‌های پرطرفدار نمایش داده می‌شود', 'Shown between Trending Movies & TV Shows')],
    'home_footer_banner'   => ['label' => $t('۴. بنر انتهای صفحه اصلی', '4. Home Bottom Native Banner'), 'badge' => $t('صفحه اصلی', 'Home Footer'), 'dual' => true, 'hint' => $t('پایین بخش سریال‌های پرطرفدار قبل از فوتر', 'Below Trending TV Shows before footer')],
    'search_top_banner'    => ['label' => $t('۵. بنر بالای نتایج جستجو', '5. Search Results Top Banner'), 'badge' => $t('صفحه جستجو', 'Search'), 'dual' => true, 'hint' => $t('بالای کارت‌های نتیجه جستجوی کاربران', 'Shown at the top of search results')],
    'movie_sidebar'        => ['label' => $t('۶. باکس کنار پوستر فیلم (300x250)', '6. Movie Card Side Box (300x250)'), 'badge' => $t('صفحه فیلم', 'Movie Page'), 'dual' => true, 'hint' => '300x250 Medium Rectangle'],
    'movie_above_table'    => ['label' => $t('۷. بنر بالای لیست زیرنویس‌ها (بالاترین کلیک)', '7. Above Subtitles List (Highest CTR)'), 'badge' => 'High CTR', 'dual' => true, 'hint' => $t('دقیقاً بالای دکمه‌های دانلود قرار می‌گیرد', 'Placed right above the Download buttons')],
    'movie_in_feed'        => ['label' => $t('۸. تبلیغ همسان میان ردیف‌های زیرنویس (In-Feed)', '8. In-Feed Native Subtitle Row'), 'badge' => 'In-Feed', 'dual' => true, 'hint' => $t('بعد از ردیف سوم زیرنویس با ظاهر هماهنگ نمایش داده می‌شود', 'Blends naturally after the 3rd subtitle row')],
    'movie_below_table'    => ['label' => $t('۹. بنر همسان پایین لیست زیرنویس‌ها', '9. Below Subtitles Native Grid'), 'badge' => $t('همسان', 'Native'), 'dual' => true, 'hint' => $t('مناسب ویجت‌های همسان یکتانت، Adsterra و Monetag', 'Ideal for multi-card native recommendation widgets')],
    'sticky_footer_mobile' => ['label' => $t('۱۰. بنر چسبان پایین موبایل', '10. Mobile Sticky Bottom Banner'), 'badge' => $t('موبایل', 'Mobile Only'), 'dual' => false, 'hint' => '320x50 Fixed Mobile Bar'],
];
?>
<!DOCTYPE html>
<html lang="<?= $isFa ? 'fa' : 'en' ?>" dir="<?= $isFa ? 'rtl' : 'ltr' ?>" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t('پنل مدیریت | Subieto', 'Admin Studio | Subieto') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&family=Nunito:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400..500,0..1,0" rel="stylesheet">
    <style>
        html { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        body { font-family: 'Vazirmatn', system-ui, sans-serif; background-color: #faf7f2; color: #2b302d; }
        .font-display { font-family: 'Vazirmatn', 'Outfit', sans-serif; letter-spacing: -0.015em; }
        html:not([lang="fa"]) body { font-family: 'Nunito', system-ui, sans-serif; }
        html:not([lang="fa"]) .font-display { font-family: 'Outfit', 'Nunito', sans-serif; }

        .admin-compact .p-6 { padding: 1rem !important; }
        .admin-compact .p-5 { padding: .875rem !important; }
        .admin-compact .p-4 { padding: .75rem !important; }
        .admin-compact .p-3\.5, .admin-compact .p-3 { padding: .625rem !important; }
        .admin-compact .px-6 { padding-inline: 1rem !important; }
        .admin-compact .px-4 { padding-inline: .75rem !important; }
        .admin-compact .px-3\.5, .admin-compact .px-2\.5 { padding-inline: .625rem !important; }
        .admin-compact .py-7 { padding-block: 1rem !important; }
        .admin-compact .py-3\.5, .admin-compact .py-2\.5, .admin-compact .py-2 { padding-block: .375rem !important; }
        .admin-compact .gap-4 { gap: .625rem !important; }
        .admin-compact .gap-3\.5, .admin-compact .gap-3, .admin-compact .gap-2\.5 { gap: .5rem !important; }
        .admin-compact .space-y-6 > :not([hidden]) ~ :not([hidden]),
        .admin-compact .space-y-5 > :not([hidden]) ~ :not([hidden]) { margin-top: .75rem !important; }
        .admin-compact .space-y-4 > :not([hidden]) ~ :not([hidden]) { margin-top: .625rem !important; }
        .admin-compact .h-16 { height: 3.25rem !important; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        [x-cloak] { display: none !important; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="admin-compact min-h-screen flex flex-col justify-between" x-data="adminStudio()">

    <!-- Top Light Header -->
    <header class="w-full sticky top-0 z-40 bg-[#faf7f2]/90 backdrop-blur-xl border-b border-[#efeae1]">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="/" target="_blank" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-2xl bg-[#d6efe0] text-[#2e6f40] flex items-center justify-center group-hover:scale-105 transition-transform">
                        <span class="material-symbols-outlined text-[20px]">closed_caption</span>
                    </div>
                    <span class="font-display font-bold text-xl text-[#2b302d]">Subieto</span>
                </a>
                <span class="px-2.5 py-0.5 rounded-full bg-[#f0ece4] text-[#6c655a] text-[11px] font-bold"><?= $t('مدیریت', 'Studio') ?></span>
            </div>

            <div class="flex items-center gap-2">
                <a href="/admin?lang=<?= $isFa ? 'en' : 'fa' ?>" class="px-2.5 py-1.5 rounded-full bg-white border border-[#efeae1] hover:border-[#2e6f40]/40 text-xs font-bold text-[#6c655a] transition-colors" aria-label="<?= $isFa ? 'English' : 'فارسی' ?>">
                    <?= $isFa ? 'EN' : 'فا' ?>
                </a>

                <a href="/" target="_blank" class="px-3.5 py-1.5 rounded-full bg-white border border-[#efeae1] hover:border-[#2e6f40]/40 text-xs font-bold text-[#2b302d] flex items-center gap-1 transition-colors">
                    <span><?= $t('مشاهده سایت', 'Open Site') ?></span>
                    <span class="material-symbols-outlined text-[14px]">open_in_new</span>
                </a>

                <form action="/admin/logout" method="POST" class="inline">
                    <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                    <button type="submit" class="px-3.5 py-1.5 rounded-full bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold transition-colors">
                        <?= $t('خروج', 'Sign Out') ?>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="flex-1 max-w-5xl mx-auto w-full px-4 sm:px-6 py-7 space-y-6">

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold rounded-2xl px-4 py-3.5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px] text-emerald-600">check_circle</span>
                <span><?= View::e($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold rounded-2xl px-4 py-3.5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px] text-rose-600">error</span>
                <span><?= View::e($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- 4 Soft KPI Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
            <div class="bg-white border border-[#efeae1] rounded-3xl p-5 shadow-[0_2px_12px_rgba(43,48,45,0.03)]">
                <div class="flex items-center justify-between text-xs font-semibold text-[#6c655a]">
                    <span><?= $t('کل دانلودها', 'Total Downloads') ?></span>
                    <span class="material-symbols-outlined text-[18px] text-[#2e6f40]">download</span>
                </div>
                <div class="font-display text-2xl font-bold text-[#2b302d] mt-1.5"><?= number_format($totalDownloads) ?></div>
                <div class="text-[11px] text-[#2e6f40] font-semibold mt-1">+<?= number_format($todayDls) ?> <?= $t('امروز', 'today') ?></div>
            </div>

            <div class="bg-white border border-[#efeae1] rounded-3xl p-5 shadow-[0_2px_12px_rgba(43,48,45,0.03)]">
                <div class="flex items-center justify-between text-xs font-semibold text-[#6c655a]">
                    <span><?= $t('بازدید واقعی امروز', 'Human Views Today') ?></span>
                    <span class="material-symbols-outlined text-[18px] text-sky-600">visibility</span>
                </div>
                <div class="font-display text-2xl font-bold text-[#2b302d] mt-1.5"><?= number_format($todayViews) ?></div>
                <div class="text-[11px] text-[#6c655a] mt-1"><?= $t('نرخ تبدیل:', 'Conversion:') ?> <strong><?= $convRate ?>%</strong></div>
            </div>

            <div class="bg-white border border-[#efeae1] rounded-3xl p-5 shadow-[0_2px_12px_rgba(43,48,45,0.03)]">
                <div class="flex items-center justify-between text-xs font-semibold text-[#6c655a]">
                    <span><?= $t('کلیک بنرهای تبلیغاتی', 'Ad Slot Clicks') ?></span>
                    <span class="material-symbols-outlined text-[18px] text-amber-600">ads_click</span>
                </div>
                <div class="font-display text-2xl font-bold text-[#2b302d] mt-1.5"><?= number_format($totalAdClicks) ?></div>
                <div class="text-[11px] text-[#6c655a] mt-1"><?= $t('صفحات سئو:', 'Indexed URLs:') ?> <strong><?= number_format($indexedCount) ?></strong></div>
            </div>

            <div class="bg-white border border-[#efeae1] rounded-3xl p-5 shadow-[0_2px_12px_rgba(43,48,45,0.03)]">
                <div class="flex items-center justify-between text-xs font-semibold text-[#6c655a]">
                    <span><?= $t('وضعیت کش', 'Cache Health') ?></span>
                    <span class="material-symbols-outlined text-[18px] text-indigo-600">bolt</span>
                </div>
                <div class="font-display text-2xl font-bold text-[#2b302d] mt-1.5"><?= number_format($staticPagesCount) ?> <span class="text-xs font-normal text-[#6c655a]"><?= $t('صفحه', 'pages') ?></span></div>
                <div class="text-[11px] text-[#6c655a] mt-1"><?= $t('کش API:', 'API cache:') ?> <strong><?= number_format($cacheCount) ?></strong></div>
            </div>
        </div>

        <!-- Clean Pill Tab Navigation -->
        <nav class="flex items-center gap-2 overflow-x-auto no-scrollbar py-1">
            <button type="button" @click="tab = 'overview'" :class="tab === 'overview' ? 'bg-[#2b302d] text-white font-bold shadow-sm' : 'bg-white border border-[#efeae1] text-[#6c655a] hover:text-[#2b302d]'" class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">bar_chart</span>
                <span><?= $t('آمار و تقاضا', 'Analytics & Demand') ?></span>
            </button>
            <button type="button" @click="tab = 'sources'" :class="tab === 'sources' ? 'bg-[#2e6f40] text-white font-bold shadow-sm' : 'bg-white border border-[#efeae1] text-[#6c655a] hover:text-[#2b302d]'" class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">language</span>
                <span><?= $t('منابع و اولویت زبان‌ها', 'Sources & Languages') ?></span>
            </button>
            <button type="button" @click="tab = 'ads'" :class="tab === 'ads' ? 'bg-amber-600 text-white font-bold shadow-sm' : 'bg-white border border-[#efeae1] text-[#6c655a] hover:text-[#2b302d]'" class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">payments</span>
                <span><?= $t('تبلیغات و پیش‌نمایش بنر', 'Ads & Banner Preview') ?></span>
            </button>
            <button type="button" @click="tab = 'seo'" :class="tab === 'seo' ? 'bg-sky-700 text-white font-bold shadow-sm' : 'bg-white border border-[#efeae1] text-[#6c655a] hover:text-[#2b302d]'" class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">travel_explore</span>
                <span><?= $t('تنظیمات عمومی و سئو', 'SEO & General') ?></span>
            </button>
            <button type="button" @click="tab = 'security'" :class="tab === 'security' ? 'bg-rose-600 text-white font-bold shadow-sm' : 'bg-white border border-[#efeae1] text-[#6c655a] hover:text-[#2b302d]'" class="shrink-0 px-4 py-2 rounded-full text-xs font-semibold transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">shield</span>
                <span><?= $t('امنیت و کش', 'Security & Cache') ?></span>
            </button>
        </nav>

        <!-- TAB 1: ANALYTICS -->
        <div x-show="tab === 'overview'" class="space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div class="lg:col-span-2 bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
                        <div>
                            <h2 class="font-display text-base font-bold text-[#2b302d]"><?= $t('آمار ۷ روز اخیر', '7-Day Traffic & Downloads') ?></h2>
                            <p class="text-xs text-[#6c655a]"><?= $t('مقایسه بازدیدهای واقعی (بدون ربات) و دانلودهای مستقیم', 'Deduplicated human page views vs. direct SRT downloads') ?></p>
                        </div>
                        <div class="flex items-center gap-4 text-xs font-semibold">
                            <span class="flex items-center gap-1.5 text-sky-700"><span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span> <?= $t('بازدید', 'Views') ?></span>
                            <span class="flex items-center gap-1.5 text-[#2e6f40]"><span class="w-2.5 h-2.5 rounded-full bg-[#2e6f40]"></span> <?= $t('دانلود', 'Downloads') ?></span>
                        </div>
                    </div>

                    <?php if (empty($dailyMetrics)): ?>
                        <div class="py-12 text-center text-xs text-[#6c655a]"><?= $t('هنوز آماری ثبت نشده است.', 'No traffic data recorded yet.') ?></div>
                    <?php else: ?>
                        <div class="grid grid-cols-7 gap-3 items-end h-44 pt-4 border-b border-[#efeae1] pb-2">
                            <?php foreach ($dailyMetrics as $dm): ?>
                                <?php
                                    $pv = (int) $dm['page_views'];
                                    $dl = (int) $dm['downloads'];
                                    $pvH = max(8, (int) round(($pv / $maxChartVal) * 100));
                                    $dlH = max(8, (int) round(($dl / $maxChartVal) * 100));
                                    $shortDate = substr((string) $dm['metric_date'], 5);
                                ?>
                                <div class="flex flex-col items-center gap-2 h-full justify-end">
                                    <div class="flex items-end gap-1.5 w-full justify-center h-32">
                                        <div style="height: <?= $pvH ?>%" title="Views: <?= $pv ?>" class="w-3.5 bg-sky-500/85 rounded-t-md"></div>
                                        <div style="height: <?= $dlH ?>%" title="Downloads: <?= $dl ?>" class="w-3.5 bg-[#2e6f40] rounded-t-md"></div>
                                    </div>
                                    <span class="text-[10px] font-mono text-[#6c655a]"><?= View::e($shortDate) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm flex flex-col justify-between gap-4">
                    <div>
                        <h2 class="font-display text-base font-bold text-[#2b302d] mb-3"><?= $t('دانلود بر اساس زبان', 'Downloads by Language') ?></h2>
                        <?php if (empty($langBreakdown)): ?>
                            <p class="text-xs text-[#6c655a]"><?= $t('هنوز دانلودی ثبت نشده است.', 'No downloads yet.') ?></p>
                        <?php else: ?>
                            <div class="space-y-2.5 text-xs">
                                <?php foreach ($langBreakdown as $lb): ?>
                                    <?php
                                        $lCode = strtoupper((string) ($lb['language_code'] ?? 'EN'));
                                        $lCnt  = (int) ($lb['cnt'] ?? 0);
                                        $lPct  = $totalDownloads > 0 ? round(($lCnt / $totalDownloads) * 100) : 0;
                                    ?>
                                    <div class="space-y-1">
                                        <div class="flex justify-between font-semibold">
                                            <span class="font-mono"><?= View::e($lCode) ?></span>
                                            <span class="text-[#6c655a]"><?= number_format($lCnt) ?> (<?= $lPct ?>%)</span>
                                        </div>
                                        <div class="w-full h-2 rounded-full bg-[#f5f1ea] overflow-hidden">
                                            <div class="h-full bg-[#2e6f40] rounded-full" style="width: <?= max(4, $lPct) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($providerBreakdown)): ?>
                        <div class="pt-3 border-t border-[#efeae1]">
                            <div class="text-[11px] font-bold text-[#6c655a] mb-2"><?= $t('منابع برتر', 'Top Sources') ?></div>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($providerBreakdown as $pb): ?>
                                    <span class="px-2.5 py-1 rounded-full bg-[#f5f1ea] text-[11px] font-semibold text-[#2b302d]">
                                        <?= View::e((string) $pb['provider']) ?>: <strong><?= (int) $pb['cnt'] ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm">
                    <h2 class="font-display text-base font-bold text-[#2b302d] mb-4"><?= $t('پرطرفدارترین عناوین', 'Most Downloaded Titles') ?></h2>
                    <?php if (empty($topMedia)): ?>
                        <p class="text-xs text-[#6c655a]"><?= $t('هنوز دانلودی ثبت نشده است.', 'No downloads recorded yet.') ?></p>
                    <?php else: ?>
                        <div class="space-y-2 text-xs">
                            <?php foreach ($topMedia as $tm): ?>
                                <div class="flex items-center justify-between bg-[#faf7f2] px-4 py-2.5 rounded-2xl border border-[#efeae1]">
                                    <a href="/subtitles/<?= View::e((string) $tm['imdb_id']) ?>" target="_blank" class="font-bold text-[#2b302d] hover:text-[#2e6f40] truncate" dir="ltr">
                                        <?= View::e((string) $tm['media_title']) ?>
                                    </a>
                                    <span class="px-2.5 py-0.5 rounded-full bg-[#d6efe0] text-[#2e6f40] font-bold shrink-0">
                                        <?= number_format((int) $tm['dl_count']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm">
                    <h2 class="font-display text-base font-bold text-[#2b302d] mb-4"><?= $t('آخرین دانلودها', 'Recent Downloads') ?></h2>
                    <?php if (empty($recentDownloads)): ?>
                        <p class="text-xs text-[#6c655a]"><?= $t('هنوز دانلودی ثبت نشده است.', 'No downloads recorded yet.') ?></p>
                    <?php else: ?>
                        <div class="space-y-2 text-xs">
                            <?php foreach ($recentDownloads as $dl): ?>
                                <div class="flex items-center justify-between bg-[#faf7f2] px-4 py-2.5 rounded-2xl border border-[#efeae1] gap-2">
                                    <div class="min-w-0">
                                        <div class="font-bold text-[#2b302d] truncate" dir="ltr"><?= View::e($dl['media_title'] ?: $dl['file_name']) ?></div>
                                        <div class="text-[10px] text-[#6c655a] font-mono truncate" dir="ltr"><?= View::e($dl['file_name']) ?></div>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <span class="px-2 py-0.5 rounded-md bg-sky-50 text-sky-700 font-mono text-[10px] font-bold uppercase"><?= View::e($dl['language_code']) ?></span>
                                        <span class="px-2 py-0.5 rounded-md bg-[#efeae1] text-[#6c655a] text-[10px] font-semibold"><?= View::e($dl['provider']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- UNIFIED SETTINGS FORM -->
        <form action="/admin/settings" method="POST" x-show="tab !== 'overview'" x-cloak class="space-y-6">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

            <!-- TAB 2: SOURCES & LANGUAGES -->
            <div x-show="tab === 'sources'" class="space-y-5">
                <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#efeae1] pb-4">
                        <div>
                            <h2 class="font-display text-base font-bold text-[#2b302d]"><?= $t('منابع رایگان زیرنویس (بدون نیاز به API Key)', 'Free Subtitle Providers (Zero API Key)') ?></h2>
                            <p class="text-xs text-[#6c655a]"><?= $t('تمامی ۴ منبع رایگان، زیرنویس‌های همه زبان‌های دنیا را دریافت می‌کنند.', 'All 4 free providers fetch every available language globally.') ?></p>
                        </div>
                        <button type="button" @click="runLiveTest()" :disabled="testing" class="px-4 py-2 rounded-full bg-[#2e6f40] hover:bg-[#245832] text-white text-xs font-bold flex items-center gap-1.5 transition">
                            <span class="material-symbols-outlined text-[16px]">speed</span>
                            <span x-text="testing ? '<?= $t('در حال تست...', 'Testing...') ?>' : '<?= $t('تست زنده منابع', 'Run Live Diagnostics') ?>'"></span>
                        </button>
                    </div>

                    <div x-show="testData !== null" x-cloak class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 text-xs bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-4">
                        <div class="bg-white rounded-xl p-3 border border-[#efeae1]">
                            <div class="text-[11px] text-[#6c655a]">Metadata</div>
                            <div class="font-bold mt-0.5" :class="testData?.metadata?.ok ? 'text-emerald-700' : 'text-rose-600'" x-text="testData?.metadata?.ok ? ('OK (' + testData.metadata.ms + 'ms)') : 'Failed'"></div>
                        </div>
                        <div class="bg-white rounded-xl p-3 border border-[#efeae1]">
                            <div class="text-[11px] text-[#6c655a]">OpenSub Free</div>
                            <div class="font-bold mt-0.5" :class="testData?.opensub_free?.ok ? 'text-emerald-700' : 'text-rose-600'" x-text="testData?.opensub_free?.ok ? (testData.opensub_free.count + ' subs') : ('Failed · ' + (testData?.opensub_free?.bytes ?? '?') + 'B · json=' + (testData?.opensub_free?.json_error ?? '?') + ' · raw=' + (testData?.opensub_free?.raw_subs ?? '?'))"></div>
                        </div>
                        <div class="bg-white rounded-xl p-3 border border-[#efeae1]">
                            <div class="text-[11px] text-[#6c655a]">Podnapisi</div>
                            <div class="font-bold mt-0.5" :class="testData?.podnapisi?.ok ? 'text-emerald-700' : 'text-rose-600'" x-text="testData?.podnapisi?.ok ? (testData.podnapisi.count + ' subs') : 'Failed'"></div>
                        </div>
                        <div class="bg-white rounded-xl p-3 border border-[#efeae1]">
                            <div class="text-[11px] text-[#6c655a]">YIFY</div>
                            <div class="font-bold mt-0.5" :class="testData?.yify?.ok ? 'text-emerald-700' : 'text-rose-600'" x-text="testData?.yify?.ok ? (testData.yify.count + ' subs') : 'Failed'"></div>
                        </div>
                        <div class="bg-white rounded-xl p-3 border border-[#efeae1]">
                            <div class="text-[11px] text-[#6c655a]">Subf2m</div>
                            <div class="font-bold mt-0.5" :class="testData?.subf2m?.ok ? 'text-emerald-700' : 'text-amber-700'" x-text="testData?.subf2m?.ok ? (testData.subf2m.count + ' subs') : ('Blocked · ' + (testData?.subf2m?.bytes ?? '?') + 'B · search=' + (testData?.subf2m?.has_search ?? '?') + ' item=' + (testData?.subf2m?.has_item ?? '?') + ' dl=' + (testData?.subf2m?.has_download ?? '?'))"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                        <label class="flex items-center justify-between bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-3.5 cursor-pointer">
                            <span class="font-bold">OpenSubtitles Free</span>
                            <input type="checkbox" name="free_opensub_enabled" value="1" <?= ($config['providers.free_opensub_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                        </label>
                        <label class="flex items-center justify-between bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-3.5 cursor-pointer">
                            <span class="font-bold">Podnapisi XML</span>
                            <input type="checkbox" name="podnapisi_enabled" value="1" <?= ($config['providers.podnapisi_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                        </label>
                        <label class="flex items-center justify-between bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-3.5 cursor-pointer">
                            <span class="font-bold">YIFY Subtitles</span>
                            <input type="checkbox" name="yify_enabled" value="1" <?= ($config['providers.yify_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                        </label>
                        <label class="flex items-center justify-between bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-3.5 cursor-pointer">
                            <span class="font-bold">Subf2m Scraper</span>
                            <input type="checkbox" name="subf2m_enabled" value="1" <?= ($config['providers.subf2m_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                        </label>
                    </div>

                    <div class="pt-2">
                        <label class="block text-xs font-bold text-[#2b302d] mb-1"><?= $t('ترتیب اولویت نمایش زبان‌ها (کد دوحرفی ISO)', 'Priority Languages Order (ISO 2-letter codes)') ?></label>
                        <input type="text" name="priority_languages" dir="ltr" x-model="priorityLangs" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 text-xs font-mono text-[#2b302d]">
                        <div class="flex flex-wrap items-center gap-1.5 mt-2 text-[11px]">
                            <span class="text-[#6c655a]"><?= $t('پیش‌تنظیم سریع:', 'Quick presets:') ?></span>
                            <button type="button" @click="priorityLangs = 'en,fa,ar,es,fr,tr'" class="px-2.5 py-0.5 rounded-full bg-[#f5f1ea] hover:bg-[#efeae1] font-semibold">Global + MENA</button>
                            <button type="button" @click="priorityLangs = 'en,es,pt,fr,de,it'" class="px-2.5 py-0.5 rounded-full bg-[#f5f1ea] hover:bg-[#efeae1] font-semibold">US / Europe</button>
                            <button type="button" @click="priorityLangs = 'fa,en,ar,tr'" class="px-2.5 py-0.5 rounded-full bg-[#f5f1ea] hover:bg-[#efeae1] font-semibold">Persian First</button>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-4">
                    <div>
                        <h3 class="font-display text-sm font-bold text-[#2b302d]"><?= $t('کلیدهای API اختیاری', 'Optional External API Keys') ?></h3>
                        <p class="text-xs text-[#6c655a]"><?= $t('در صورت خالی بودن، سایت به صورت ۱۰۰٪ رایگان کار می‌کند.', 'Leave blank to use 100% free Cinemeta metadata and free subtitle endpoints.') ?></p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5 text-xs">
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1">TMDB API Key</label>
                            <input type="text" name="tmdb_api_key" dir="ltr" value="<?= View::e($config['apis.tmdb_api_key'] ?? '') ?>" placeholder="Optional" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2.5 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1">SubDL API Key</label>
                            <input type="text" name="subdl_api_key" dir="ltr" value="<?= View::e($config['apis.subdl_api_key'] ?? '') ?>" placeholder="Optional" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2.5 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1">OpenSubtitles API Key</label>
                            <input type="text" name="opensubtitles_api_key" dir="ltr" value="<?= View::e($config['apis.opensubtitles_api_key'] ?? '') ?>" placeholder="Optional" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2.5 font-mono">
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: ADS & SAMPLE BANNER PREVIEW -->
            <div x-show="tab === 'ads'" class="space-y-5">
                <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#efeae1] pb-4">
                        <div>
                            <h2 class="font-display text-base font-bold text-[#2b302d]"><?= $t('مدیریت بنرهای تبلیغاتی و پیش‌نمایش نمونه', 'Monetization & Sample Ad Preview') ?></h2>
                            <p class="text-xs text-[#6c655a]"><?= $t('پشتیبانی از AdSense، Adsterra، Monetag، یکتانت و بنرهای مستقیم', 'Supports AdSense, Adsterra, Monetag, PropellerAds, and direct banners.') ?></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Sample Banner Preview Toggle -->
                            <label class="inline-flex items-center gap-2 cursor-pointer bg-amber-50 border border-amber-200 px-4 py-2 rounded-full text-xs font-bold text-amber-900">
                                <input type="checkbox" name="ads_sample_preview" value="1" <?= ($config['ads.sample_preview'] ?? '0') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-amber-600">
                                <span><?= $t('نمایش بنرهای نمونه در سایت (Preview)', 'Show Sample Banners on Site') ?></span>
                            </label>

                            <label class="inline-flex items-center gap-2 cursor-pointer bg-[#faf7f2] border border-[#efeae1] px-4 py-2 rounded-full text-xs font-bold">
                                <input type="checkbox" name="ads_enabled" value="1" <?= ($config['ads.enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                                <span><?= $t('تبلیغات فعال باشد', 'Enable Ads') ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1"><?= $t('هشدار مسدودکننده تبلیغات (AdBlock)', 'AdBlock Gentle Notice') ?></label>
                            <label class="flex items-center gap-2 bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 cursor-pointer">
                                <input type="checkbox" name="adblock_notice" value="1" <?= ($config['ads.adblock_notice'] ?? '0') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                                <span><?= $t('نمایش پیام دوستانه در صورت روشن بودن AdBlock', 'Show non-intrusive notice if AdBlock is detected') ?></span>
                            </label>
                        </div>
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1"><?= $t('متن پیام AdBlock', 'AdBlock Notice Text') ?></label>
                            <input type="text" name="adblock_message" value="<?= View::e($config['ads.adblock_message'] ?? 'Please disable your ad blocker to keep our subtitle archive free.') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5 text-xs">
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1"><?= $t('اسکریپت <head> بین‌المللی (Adsterra / Monetag / AdSense)', 'Global <head> Scripts (USD Networks)') ?></label>
                            <textarea name="ad_head_scripts" dir="ltr" rows="3" placeholder="<script>...</script>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs"><?= View::e($config['ads.head_scripts'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block font-bold text-emerald-700 mb-1"><?= $t('اسکریپت <head> اختصاصی ایران (یکتانت / صباویژن)', 'Iran Geo-Targeted <head> Scripts (IRR)') ?></label>
                            <textarea name="ad_head_scripts_ir" dir="ltr" rows="3" placeholder="<script>...</script>" class="w-full bg-emerald-50/50 border border-emerald-200 rounded-2xl px-4 py-2.5 font-mono text-xs"><?= View::e($config['ads.head_scripts_ir'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <?php foreach ($adSlotsMeta as $slotKey => $slotMeta): ?>
                    <?php
                        $expVal = $config['ads.' . $slotKey . '_expires'] ?? '';
                        $isExpired = ($expVal !== '' && $expVal < date('Y-m-d'));
                        $clicks = $adClicksMap[$slotKey] ?? 0;
                    ?>
                    <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#efeae1] pb-3">
                            <div class="flex items-center gap-2">
                                <h3 class="font-display text-sm font-bold text-[#2b302d]"><?= View::e($slotMeta['label']) ?></h3>
                                <span class="px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[10px] font-bold"><?= View::e($slotMeta['badge']) ?></span>
                                <span class="px-2.5 py-0.5 rounded-full bg-[#f5f1ea] text-[#6c655a] text-[10px] font-mono"><?= number_format($clicks) ?> clicks</span>
                                <?php if ($isExpired): ?>
                                    <span class="px-2.5 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[10px] font-bold"><?= $t('منقضی‌شده', 'Expired') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="text-[#6c655a]"><?= $t('انقضای خودکار:', 'Auto-expire:') ?></span>
                                <input type="date" dir="ltr" name="ad_<?= $slotKey ?>_expires" value="<?= View::e($expVal) ?>" class="bg-[#f5f1ea] border border-[#d8d3c9] rounded-xl px-2.5 py-1 text-xs">
                            </div>
                        </div>

                        <p class="text-[11px] text-[#6c655a]"><?= View::e($slotMeta['hint']) ?></p>

                        <?php if ($slotMeta['dual']): ?>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                                <div>
                                    <label class="block font-semibold text-[#6c655a] mb-1"><?= $t('دسکتاپ بین‌المللی (USD)', 'Global Desktop (USD)') ?></label>
                                    <textarea name="ad_<?= $slotKey ?>" dir="ltr" rows="2" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2 font-mono"><?= View::e($config['ads.' . $slotKey] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="block font-semibold text-sky-700 mb-1"><?= $t('موبایل بین‌المللی (USD)', 'Global Mobile (USD)') ?></label>
                                    <textarea name="ad_<?= $slotKey ?>_mobile" dir="ltr" rows="2" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2 font-mono"><?= View::e($config['ads.' . $slotKey . '_mobile'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="block font-semibold text-emerald-700 mb-1"><?= $t('اختصاصی کاربران ایران (IRR)', 'Iran Visitors Only (IRR)') ?></label>
                                    <textarea name="ad_<?= $slotKey ?>_ir" dir="ltr" rows="2" placeholder="Yektanet / Direct IR" class="w-full bg-emerald-50/50 border border-emerald-200 rounded-2xl px-3.5 py-2 font-mono"><?= View::e($config['ads.' . $slotKey . '_ir'] ?? '') ?></textarea>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                <div>
                                    <label class="block font-semibold text-[#6c655a] mb-1"><?= $t('موبایل بین‌المللی (USD)', 'Global Mobile (USD)') ?></label>
                                    <textarea name="ad_<?= $slotKey ?>" dir="ltr" rows="2" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2 font-mono"><?= View::e($config['ads.' . $slotKey] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="block font-semibold text-emerald-700 mb-1"><?= $t('موبایل کاربران ایران (IRR)', 'Iran Mobile Only (IRR)') ?></label>
                                    <textarea name="ad_<?= $slotKey ?>_ir" dir="ltr" rows="2" class="w-full bg-emerald-50/50 border border-emerald-200 rounded-2xl px-3.5 py-2 font-mono"><?= View::e($config['ads.' . $slotKey . '_ir'] ?? '') ?></textarea>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- TAB 4: SEO & GENERAL -->
            <div x-show="tab === 'seo'" class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                <div class="border-b border-[#efeae1] pb-3">
                    <h2 class="font-display text-base font-bold text-[#2b302d]"><?= $t('تنظیمات برند، ظاهر و سئو', 'Brand, Appearance & SEO') ?></h2>
                    <p class="text-[#6c655a]"><?= $t('نام سایت، آدرس دامنه اصلی و تم پیش‌فرض را تنظیم کنید.', 'Configure your domain canonical URL, default theme, and search metadata.') ?></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('نام برند سایت', 'Site Brand Name') ?></label>
                        <input type="text" name="site_title" dir="ltr" value="<?= View::e($config['site.title'] ?? 'Subieto') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('آدرس دامنه اصلی (Base URL)', 'Canonical Base URL') ?></label>
                        <input type="url" name="site_base_url" dir="ltr" value="<?= View::e($config['site.base_url'] ?? '') ?>" placeholder="https://subieto.com" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('تم پیش‌فرض سایت', 'Default Theme') ?></label>
                        <?php $defTheme = $config['site.default_theme'] ?? 'light'; ?>
                        <select name="default_theme" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2.5">
                            <option value="light" <?= $defTheme === 'light' ? 'selected' : '' ?>><?= $t('روشن کرم گرم (Light)', 'Warm Cream (Light)') ?></option>
                            <option value="dark" <?= $defTheme === 'dark' ? 'selected' : '' ?>><?= $t('تاریک سینمایی (Dark)', 'Midnight Glow (Dark)') ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('حالت تحویل فایل', 'Delivery Engine') ?></label>
                        <div class="bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2.5 text-xs text-[#6c655a]">
                            <?= $t('دانلود مستقیم از طریق سرور همراه با تبدیل UTF-8', 'Always streamed directly through the server (UTF-8)') ?>
                        </div>
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('مدت کش (ثانیه)', 'Cache TTL (seconds)') ?></label>
                        <input type="number" name="cache_ttl" dir="ltr" value="<?= (int) ($config['site.cache_ttl'] ?? 21600) ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3.5 py-2.5 font-mono">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-[#6c655a] mb-1"><?= $t('کد تایید گوگل سرچ کنسول', 'Google Search Console Verification Code') ?></label>
                    <input type="text" name="seo_google_verification" dir="ltr" value="<?= View::e($config['seo.google_verification'] ?? '') ?>" placeholder="google-site-verification token" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono">
                </div>

                <div>
                    <label class="block font-bold text-[#6c655a] mb-1"><?= $t('توضیحات متا (Meta Description)', 'Default Meta Description') ?></label>
                    <textarea name="site_description" rows="2" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5"><?= View::e($config['site.description'] ?? '') ?></textarea>
                </div>

                <input type="hidden" name="seo_movie_title_tpl" value="<?= View::e($config['seo.movie_title_tpl'] ?? '') ?>">
                <input type="hidden" name="seo_keywords" value="<?= View::e($config['seo.keywords'] ?? '') ?>">

                <div class="bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span><?= $t('نقشه سایت XML برای گوگل', 'XML Sitemap Index') ?> (<strong><?= number_format($indexedCount) ?></strong> URLs)</span>
                    <a href="/sitemap.xml" target="_blank" dir="ltr" class="text-[#2e6f40] font-bold hover:underline">/sitemap.xml ↗</a>
                </div>
            </div>

            <!-- TAB 5: SECURITY & PASSWORD -->
            <div x-show="tab === 'security'" class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                <div class="border-b border-[#efeae1] pb-3">
                    <h2 class="font-display text-base font-bold text-[#2b302d]"><?= $t('امنیت و تغییر رمز عبور', 'Security & Access Control') ?></h2>
                    <p class="text-[#6c655a]"><?= $t('محافظت از لینک‌های دانلود و تغییر رمز ورود به پنل', 'Harden hotlink protection, trusted proxies, and admin credentials.') ?></p>
                </div>

                <label class="flex items-center gap-2.5 bg-[#faf7f2] border border-[#efeae1] rounded-2xl p-4 cursor-pointer">
                    <input type="checkbox" name="bind_ip_download" value="1" <?= ($config['security.bind_ip_download'] ?? '0') === '1' ? 'checked' : '' ?> class="w-4 h-4 accent-[#2e6f40]">
                    <div>
                        <div class="font-bold text-[#2b302d]"><?= $t('قفل کردن لینک دانلود روی IP کاربر (Anti-Hotlink)', 'Bind Download Tokens to Visitor IP (Anti-Hotlink)') ?></div>
                        <div class="text-[11px] text-[#6c655a]"><?= $t('از سوءاستفاده سایت‌های دیگر از لینک‌های دانلود شما جلوگیری می‌کند.', 'Prevents external sites from hotlinking your signed download URLs.') ?></div>
                    </div>
                </label>

                <div>
                    <label class="block font-bold text-[#6c655a] mb-1"><?= $t('IPهای پروکسی / CDN مورد اعتماد (اختیاری)', 'Trusted Reverse Proxy / CDN IPs (Optional)') ?></label>
                    <input type="text" name="trusted_proxies" dir="ltr" value="<?= View::e($config['security.trusted_proxies'] ?? '') ?>" placeholder="127.0.0.1, 10.0.0.1" class="w-full md:w-2/3 bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('رمز عبور فعلی (فقط هنگام تغییر رمز)', 'Current Password (Required to change)') ?></label>
                        <input type="password" name="current_password" dir="ltr" placeholder="••••••••" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1"><?= $t('رمز عبور جدید (حداقل ۸ کاراکتر)', 'New Admin Password (Min 8 chars)') ?></label>
                        <input type="password" name="new_password" dir="ltr" minlength="8" placeholder="••••••••" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5">
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 cursor-pointer text-rose-700 font-semibold">
                    <input type="checkbox" name="rotate_secret_key" value="1" class="w-4 h-4 accent-rose-600">
                    <span><?= $t('بازتولید کلید امنیتی HMAC (لینک‌های دانلود قبلی باطل می‌شوند)', 'Rotate HMAC signing secret (invalidates old download links)') ?></span>
                </label>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-8 py-3 rounded-full bg-[#2e6f40] hover:bg-[#245832] text-white font-bold text-xs shadow-sm transition active:scale-95">
                    <?= $t('ذخیره تنظیمات', 'Save Changes') ?>
                </button>
            </div>
        </form>

        <!-- Cache Maintenance Card -->
        <div x-show="tab === 'security'" x-cloak class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm flex flex-wrap items-center justify-between gap-4">
            <div>
                <h3 class="font-display text-sm font-bold text-[#2b302d]"><?= $t('مدیریت و پاک‌سازی کش', 'Cache Maintenance') ?></h3>
                <p class="text-xs text-[#6c655a] mt-0.5"><?= number_format($staticPagesCount) ?> <?= $t('صفحه کش‌شده', 'cached HTML pages') ?> • <?= number_format($cacheCount) ?> <?= $t('رکورد API', 'API records') ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form action="/admin/clear-cache" method="POST">
                    <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                    <input type="hidden" name="mode" value="pages">
                    <button type="submit" class="px-4 py-2 rounded-full bg-[#f5f1ea] hover:bg-[#efeae1] text-xs font-bold text-[#2b302d] transition">
                        <?= $t('پاک‌سازی کش صفحات', 'Purge HTML Pages') ?>
                    </button>
                </form>
                <form action="/admin/clear-cache" method="POST">
                    <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                    <input type="hidden" name="mode" value="expired">
                    <button type="submit" class="px-4 py-2 rounded-full bg-[#f5f1ea] hover:bg-[#efeae1] text-xs font-bold text-[#2b302d] transition">
                        <?= $t('حذف منقضی‌شده‌ها', 'Purge Expired') ?>
                    </button>
                </form>
                <form action="/admin/clear-cache" method="POST">
                    <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                    <input type="hidden" name="mode" value="all">
                    <button type="submit" class="px-4 py-2 rounded-full bg-amber-100 hover:bg-amber-200 text-xs font-bold text-amber-900 transition">
                        <?= $t('پاک‌سازی کل کش', 'Purge All Cache') ?>
                    </button>
                </form>
            </div>
        </div>

    </main>

    <footer class="w-full border-t border-[#efeae1] py-5 mt-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 text-center text-xs text-[#6c655a]">
            &copy; <?= date('Y') ?> Subieto Studio
        </div>
    </footer>

    <script nonce="<?= CSP_NONCE ?>">
        function adminStudio() {
            return {
                tab: 'overview',
                testing: false,
                testData: null,
                priorityLangs: <?= json_encode($config['providers.priority_languages'] ?? 'en,fa,ar,es,fr,tr') ?>,
                async runLiveTest() {
                    this.testing = true;
                    this.testData = null;
                    try {
                        const res = await fetch('/admin/test-apis', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': '<?= View::e(Session::csrfToken()) ?>' }
                        });
                        this.testData = await res.json();
                    } catch (e) {
                        this.testData = null;
                    }
                    this.testing = false;
                }
            };
        }
    </script>
</body>
</html>

<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\View;
use App\Services\SeoHelper;
/** @var \App\Core\SettingsManager $settings */
/** @var string $content */
/** @var string $pageTitle */
/** @var string|null $metaDescription */
/** @var string|null $canonicalUrl */
/** @var string|null $hreflangLinks */
/** @var string|null $xDefaultUrl */
/** @var string|null $robotsMeta */
/** @var string|null $ogImage */
/** @var string|null $ogType */
/** @var string|null $jsonLd */
/** @var string|null $locale */

$lang           = ($locale ?? 'en') === 'fa' ? 'fa' : 'en';
$isFa           = ($lang === 'fa');
$dir            = $isFa ? 'rtl' : 'ltr';

$siteTitle      = (string) $settings->get('site.title', 'Subieto');
if (preg_match('/[\x{0600}-\x{06FF}]/u', $siteTitle)) {
    $siteTitle = 'Subieto';
}
$finalTitle     = $pageTitle ?? ($isFa ? "{$siteTitle} — دانلود مستقیم زیرنویس فیلم و سریال" : "{$siteTitle} — Subtitles, in sync");
$finalDesc      = $metaDescription ?? ($isFa ? 'دانلود مستقیم زیرنویس هماهنگ فیلم و سریال به تمام زبان‌ها.' : 'Multi-language SRT subtitles matched to your release.');
$finalCanonical = $canonicalUrl ?? SeoHelper::getBaseUrl() . '/';
$finalRobots    = $robotsMeta ?? 'index, follow, max-image-preview:large';
$finalOgType    = $ogType ?? 'website';
$googleVerify   = (string) $settings->get('seo.google_verification', '');
$defaultTheme   = (string) $settings->get('site.default_theme', 'light');

$headScripts    = $settings->getAd('head_scripts');
$headerBanner   = $settings->getAd('header_banner');
$stickyMobileAd = $settings->getAd('sticky_footer_mobile');
$adblockEnabled = $settings->get('ads.adblock_notice', '0') === '1' && $settings->get('ads.enabled', '1') === '1';
$adblockMessage = trim((string) $settings->get('ads.adblock_message', ''));
if ($adblockMessage === '') {
    $adblockMessage = $isFa
        ? 'لطفاً برای حمایت از ما و رایگان ماندن سایت، افزونه مسدودکننده تبلیغات (AdBlock) را غیرفعال کنید.'
        : 'Please disable your ad blocker to keep our subtitle archive free.';
}

$reqUri   = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$urlParts = parse_url($reqUri);
$baseP    = $urlParts['path'] ?? '/';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-theme="<?= $defaultTheme === 'dark' ? 'dark' : 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= View::e($finalTitle) ?></title>
    <meta name="description" content="<?= View::e($finalDesc) ?>">
    <meta name="robots" content="<?= View::e($finalRobots) ?>">
    <link rel="canonical" href="<?= View::e($finalCanonical) ?>"><?= (string) ($hreflangLinks ?? '') ?><?php if (!empty($xDefaultUrl)): ?><link rel="alternate" hreflang="x-default" href="<?= View::e($xDefaultUrl) ?>"><?php endif; ?>

    <?php if ($googleVerify !== ''): ?>
        <meta name="google-site-verification" content="<?= View::e($googleVerify) ?>">
    <?php endif; ?>

    <meta property="og:locale" content="<?= $isFa ? 'fa_IR' : 'en_US' ?>">
    <meta property="og:type" content="<?= View::e($finalOgType) ?>">
    <meta property="og:site_name" content="<?= View::e($siteTitle) ?>">
    <meta property="og:title" content="<?= View::e($finalTitle) ?>">
    <meta property="og:description" content="<?= View::e($finalDesc) ?>">
    <meta property="og:url" content="<?= View::e($finalCanonical) ?>">
    <?php if (!empty($ogImage)): ?>
        <meta property="og:image" content="<?= View::e($ogImage) ?>">
    <?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= View::e($finalTitle) ?>">
    <meta name="twitter:description" content="<?= View::e($finalDesc) ?>">
    <?php if (!empty($ogImage)): ?>
        <meta name="twitter:image" content="<?= View::e($ogImage) ?>">
    <?php endif; ?>

    <?php if (!empty($jsonLd)): ?>
        <script type="application/ld+json"><?= $jsonLd ?></script>
    <?php endif; ?>

    <script nonce="<?= CSP_NONCE ?>">
        (function(){
            var saved = localStorage.getItem('sb_theme');
            var defTheme = <?= json_encode($defaultTheme) ?>;
            document.documentElement.dataset.theme = saved || defTheme || 'light';
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/subieto.css?v=46">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>
    <?= $headScripts ?>
</head>
<body x-data="appShell()">
    <?php if ($adblockEnabled && $adblockMessage !== ''): ?>
        <div id="ab-notice" hidden style="background:var(--amber-wash);color:var(--amber);font-size:12px;font-weight:600;padding:8px 16px;text-align:center;border-bottom:1px solid var(--line);">
            <?= View::e($adblockMessage) ?>
        </div>
    <?php endif; ?>

    <header class="bar">
        <div class="col bar__in">
            <a href="/" class="mark" aria-label="<?= View::e($siteTitle) ?>">
                <b><?= View::e($siteTitle) ?></b>
            </a>

                <div class="bar__search" x-data="liveSearch(true)">
                    <form action="/search" method="GET" role="search" @submit="saveHistory(query)">
                        <div class="bar__pill">
                            <svg viewBox="0 0 22 22" fill="none" aria-hidden="true">
                                <circle cx="9.6" cy="9.6" r="7" stroke="currentColor" stroke-width="1.7"/>
                                <path d="M14.8 14.8l4.6 4.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                            </svg>
                            <input
                                x-ref="searchInput"
                                type="text"
                                name="q"
                                x-model="query"
                                @input.debounce.220ms="fetchResults()"
                                @focus="onFocus()"
                                @keydown="onKeydown($event)"
                                @click.away="open = false"
                                autocomplete="off"
                                aria-label="<?= $isFa ? 'جستجوی فیلم، سریال یا کد IMDb' : 'Search films, series, or IMDb ID' ?>"
                                placeholder="<?= $isFa ? 'جستجوی فیلم، سریال یا کد IMDb...' : 'Films, series, or IMDb ID…' ?>"
                            >
                            <button type="button" class="sp-clear" x-show="query.length > 0" x-cloak @click="clearQuery()" aria-label="Clear">✕</button>
                            <span class="sp-kbd" x-show="query.length === 0">/</span>
                            <button type="submit" class="sp-go-mini" aria-label="<?= $isFa ? 'جستجو' : 'Search' ?>">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.6 8h10M8.8 4.2L12.6 8l-3.8 3.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </form>

                    <div class="ac" x-show="open && (results.length > 0 || recent.length > 0)" x-cloak style="min-width:min(320px,calc(100vw - 32px))">
                        <template x-if="query.trim().length < 2 && recent.length > 0">
                            <div>
                                <div class="ac__sec">
                                    <span><?= $isFa ? 'جستجوهای اخیر' : 'Recent searches' ?></span>
                                    <button type="button" style="font-size:11px;color:var(--muted);text-decoration:underline" @click="clearHistory()"><?= $isFa ? 'پاک کردن' : 'Clear' ?></button>
                                </div>
                                <div class="ac__recent">
                                    <template x-for="term in recent" :key="term">
                                        <button type="button" class="ac__rchip" @click="pickExample(term)" x-text="term"></button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="results.length > 0">
                            <div class="ac__list">
                                <div class="ac__sec" x-show="query.trim().length < 2">
                                    <span><?= $isFa ? 'پیشنهادهای پرطرفدار' : 'Popular titles' ?></span>
                                </div>
                                <template x-for="(item, idx) in results" :key="item.imdbId">
                                    <a :href="item.url" class="ac__item" :data-active="String(activeIdx === idx)" @mouseenter="activeIdx = idx" @click="saveHistory(item.title)">
                                        <span class="ac__thumb"><img :src="item.posterUrl" alt="" onerror="this.style.display='none'"></span>
                                        <div style="min-width:0">
                                            <b dir="ltr" x-text="item.title"></b>
                                            <small x-text="(item.year ? item.year + ' · ' : '') + (<?= $isFa ? 'true' : 'false' ?> ? item.mediaType : item.mediaTypeEn)"></small>
                                        </div>
                                        <span class="ac__n" dir="ltr" x-text="item.imdbId"></span>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

            <div class="bar__r">
                <button type="button" class="ghost" @click="toggleTheme()" aria-label="Toggle theme">
                    <svg x-show="!isDark" width="17" height="17" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3.1" stroke="currentColor" stroke-width="1.4"/><path d="M8 1.2v1.5M8 13.3v1.5M14.8 8h-1.5M2.7 8H1.2M12.8 3.2l-1 1M4.2 11.8l-1 1M12.8 12.8l-1-1M4.2 4.2l-1-1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <svg x-show="isDark" x-cloak width="17" height="17" viewBox="0 0 16 16" fill="none"><path d="M13.2 9.4A5.7 5.7 0 0 1 6.6 2.8a5.8 5.8 0 1 0 6.6 6.6Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </div>
    </header>

    <?php if ($headerBanner !== ''): ?>
        <div class="col" style="padding-top:16px">
            <div data-ad-slot="header_banner" class="ad-card">
                <?= $headerBanner ?>
            </div>
        </div>
    <?php endif; ?>

    <main>
        <?= $content ?>
    </main>

    <?php if ($stickyMobileAd !== ''): ?>
        <div x-data="{ showSticky: true }" x-show="showSticky" style="position:fixed;bottom:0;inset-inline:0;z-index:55;background:var(--surface);border-top:1px solid var(--line);padding:6px;display:flex;justify-content:center" class="md-hide">
            <button @click="showSticky = false" aria-label="Close ad" style="position:absolute;top:-22px;right:12px;background:var(--raise);border:1px solid var(--line);color:var(--muted);font-size:10px;padding:2px 8px;border-radius:6px 6px 0 0">✕</button>
            <div data-ad-slot="sticky_footer_mobile">
                <?= $stickyMobileAd ?>
            </div>
        </div>
    <?php endif; ?>

    <footer>
        <div class="col foot" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
            <span>&copy; <?= date('Y') ?> <?= View::e($siteTitle) ?></span>
            <nav aria-label="Footer links" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12px">
                <a href="/search" class="hover:underline"><?= $isFa ? 'جستجوی پیشرفته' : 'Search' ?></a>
                <a href="/subtitles/release/yts" dir="ltr" class="hover:underline">YTS / YIFY</a>
                <a href="/subtitles/release/web-dl" dir="ltr" class="hover:underline">WEB-DL</a>
                <a href="/subtitles/release/bluray" dir="ltr" class="hover:underline">BluRay</a>
                <a href="/subtitles/release/psa" dir="ltr" class="hover:underline">PSA</a>
            </nav>
        </div>
    </footer>

    <?php if ($adblockEnabled): ?>
        <div id="ad-banner-bait" class="adsbox ad-placement pub_300x250" style="width:1px;height:1px;position:absolute;left:-9999px;">&nbsp;</div>
        <script nonce="<?= CSP_NONCE ?>">
            window.addEventListener('load', function () {
                setTimeout(function () {
                    var bait = document.getElementById('ad-banner-bait');
                    var notice = document.getElementById('ab-notice');
                    if (bait && notice && (bait.offsetHeight === 0 || window.getComputedStyle(bait).display === 'none')) {
                        notice.hidden = false;
                    }
                }, 800);
            });
        </script>
    <?php endif; ?>

    <script nonce="<?= CSP_NONCE ?>">
        function appShell() {
            return {
                isDark: document.documentElement.dataset.theme === 'dark',
                toggleTheme() {
                    this.isDark = !this.isDark;
                    document.documentElement.dataset.theme = this.isDark ? 'dark' : 'light';
                    localStorage.setItem('sb_theme', this.isDark ? 'dark' : 'light');
                }
            };
        }

        document.addEventListener('click', function (e) {
            var slotEl = e.target.closest('[data-ad-slot]');
            if (slotEl && navigator.sendBeacon) {
                navigator.sendBeacon('/api/ad-click', JSON.stringify({ slot: slotEl.getAttribute('data-ad-slot') }));
            }
        });


        // Geo-accurate ad slot hydration for cached pages (Iran IRR vs VPN/Global USD)
        window.addEventListener('DOMContentLoaded', function () {
            var slotEls = document.querySelectorAll('[data-ad-slot]');
            if (!slotEls.length) return;
            fetch('/api/ad-slots', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.slots) return;
                    slotEls.forEach(function (el) {
                        var key = el.getAttribute('data-ad-slot');
                        if (key && typeof data.slots[key] === 'string' && data.slots[key] !== '' && el.innerHTML.trim() !== data.slots[key].trim()) {
                            el.innerHTML = data.slots[key];
                        }
                    });
                })
                .catch(function () {});
        });

        function liveSearch(isPrimary = true) {
            return {
                query: '',
                results: [],
                recent: [],
                open: false,
                activeIdx: -1,
                abortCtrl: null,
                init() {
                    try {
                        const raw = localStorage.getItem('sb_recent_searches');
                        this.recent = raw ? JSON.parse(raw) : [];
                    } catch (e) {
                        this.recent = [];
                    }
                    if (isPrimary) {
                        window.addEventListener('keydown', (e) => {
                            if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')) {
                                e.preventDefault();
                                this.$refs.searchInput?.focus();
                            }
                        });
                    }
                },
                get isImdb() {
                    return /^tt\d{5,10}$/i.test(this.query.trim());
                },
                saveHistory(term) {
                    const clean = String(term || '').trim();
                    if (clean.length < 2) return;
                    const next = [clean, ...this.recent.filter(x => x.toLowerCase() !== clean.toLowerCase())].slice(0, 5);
                    this.recent = next;
                    try { localStorage.setItem('sb_recent_searches', JSON.stringify(next)); } catch (e) {}
                },
                clearHistory() {
                    this.recent = [];
                    try { localStorage.removeItem('sb_recent_searches'); } catch (e) {}
                },
                clearQuery() {
                    this.query = '';
                    this.activeIdx = -1;
                    this.onFocus();
                    this.$refs.searchInput?.focus();
                },
                async onFocus() {
                    this.open = true;
                    if (this.query.trim().length < 2 && this.results.length === 0) {
                        await this.fetchResults(true);
                    }
                },
                async fetchResults(allowEmpty = false) {
                    const q = this.query.trim();
                    this.activeIdx = -1;
                    if (q.length < 2 && !allowEmpty) {
                        if (q.length === 0) {
                            await this.fetchResults(true);
                        } else {
                            this.results = [];
                        }
                        return;
                    }
                    if (this.abortCtrl) {
                        this.abortCtrl.abort();
                    }
                        const sKey = 'sb_ac_v2_' + q.toLowerCase();
                    try {
                        const hit = sessionStorage.getItem(sKey);
                        if (hit) {
                            this.results = JSON.parse(hit);
                            this.open = this.results.length > 0 || this.recent.length > 0;
                            return;
                        }
                    } catch (e) {}

                    this.abortCtrl = new AbortController();
                    try {
                        const res = await fetch('/api/autocomplete?q=' + encodeURIComponent(q), { signal: this.abortCtrl.signal });
                        const data = await res.json();
                        this.results = Array.isArray(data) ? data : [];
                        this.open = this.results.length > 0 || this.recent.length > 0;
                        if (this.results.length > 0) {
                            try { sessionStorage.setItem(sKey, JSON.stringify(this.results)); } catch (e) {}
                        }
                    } catch (e) {
                        if (e.name !== 'AbortError') {
                            this.results = [];
                        }
                    }
                },
                onKeydown(e) {
                    if (e.key === 'Escape') {
                        this.open = false;
                        this.activeIdx = -1;
                        return;
                    }
                    if (!this.open || this.results.length === 0) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        this.activeIdx = (this.activeIdx + 1) % this.results.length;
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        this.activeIdx = (this.activeIdx - 1 + this.results.length) % this.results.length;
                    } else if (e.key === 'Enter' && this.activeIdx >= 0 && this.results[this.activeIdx]) {
                        e.preventDefault();
                        const chosen = this.results[this.activeIdx];
                        this.saveHistory(chosen.title);
                        window.location.href = chosen.url;
                    }
                },
                pickExample(term) {
                    this.query = term;
                    this.open = true;
                    this.fetchResults();
                    this.$refs.searchInput?.focus();
                }
            };
        }
    </script>
</body>
</html>

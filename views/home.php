<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\View;
/** @var \App\DTOs\MediaItem[] $trendingMovies */
/** @var \App\DTOs\MediaItem[] $trendingSeries */
/** @var \App\Core\SettingsManager $settings */
/** @var string|null $locale */
/** @var array<int, array<string, mixed>> $latestSubtitles */

$isFa = (($locale ?? 'en') === 'fa');
$homeBelowSearchAd = $settings->getAd('home_below_search');
$homeBetweenAd     = $settings->getAd('home_between');
$homeFooterAd      = $settings->getAd('home_footer_banner');

$allPopular = [];
$seenIds = [];
foreach (array_merge($trendingMovies, $trendingSeries) as $m) {
    if (!isset($seenIds[$m->imdbId])) {
        $seenIds[$m->imdbId] = true;
        $allPopular[] = $m;
    }
}

$spotlight = null;
foreach ($allPopular as $cand) {
    if ($spotlight === null || $cand->voteAverage > $spotlight->voteAverage) {
        $spotlight = $cand;
    }
}

$hueFromId = static fn(string $id): int => (abs(crc32($id)) % 360);
?>
<div class="col" x-data="{ kind: 'all', layout: localStorage.getItem('sb_view') || 'grid', setLayout(v){ this.layout = v; localStorage.setItem('sb_view', v); } }">

    <!-- Editorial Hero + Live Cue Preview -->
    <section class="hero">
        <div>
            <h1>
                <?php if ($isFa): ?>
                    زیرنویس، <em>هماهنگ.</em>
                <?php else: ?>
                    Subtitles, <em>in sync.</em>
                <?php endif; ?>
            </h1>
            <p>
                <?= $isFa
                    ? 'نسخه‌ای که دارید را پیدا کنید، در صورت نیاز زمان‌بندی را تنظیم کنید و فایل زیرنویس سالم و خوانا دانلود کنید.'
                    : 'Match the release you actually have, shift the timing if it drifts, and download a clean UTF-8 .srt.' ?>
            </p>
        </div>

        <div class="frame" aria-hidden="true" x-data="cuePreviewBox()" x-init="start()">
            <div class="frame__stage">
                <div class="frame__tc mono" x-text="tc">00:01:12,480</div>
                <div class="frame__fmt">SRT · UTF-8</div>
                <div class="frame__lines" :class="fading ? 'out' : ''">
                    <p x-text="line1"></p>
                    <p x-text="line2"></p>
                </div>
                <div class="frame__prog" :style="'transition:' + (fading ? 'none' : 'width 3.4s linear') + ';width:' + (fading ? '0%' : '100%')"></div>
            </div>
            <div class="frame__foot">
                <span><?= $isFa ? 'پیش‌نمایش زنده' : 'Live cue preview' ?></span>
                <span><?= $isFa ? 'تنظیم زمان پیش از دانلود' : 'Timing adjustable before download' ?></span>
            </div>
        </div>
    </section>

    <!-- Minimal 660px Pill Search with Smart Live Autocomplete -->
    <div class="searchzone" x-data="liveSearch(true)">
        <form action="/search" method="GET" role="search" @submit="saveHistory(query)">
            <div class="spill">
                <div class="spill__in">
                    <svg class="sp-ico" viewBox="0 0 22 22" fill="none" aria-hidden="true">
                        <circle cx="9.6" cy="9.6" r="7" stroke="currentColor" stroke-width="1.7"/>
                        <path d="M14.8 14.8l4.6 4.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                    </svg>
                    <input
                        x-ref="searchInput"
                        class="sp-input"
                        type="text"
                        name="q"
                        x-model="query"
                        @input.debounce.220ms="fetchResults()"
                        @focus="onFocus()"
                        @keydown="onKeydown($event)"
                        @click.away="open = false"
                        required
                        autocomplete="off"
                        placeholder="<?= $isFa ? 'نام فیلم، سریال یا کد IMDb...' : 'Search a film, series, or IMDb ID...' ?>"
                    >
                    <button type="button" class="sp-clear" x-show="query.length > 0" x-cloak @click="clearQuery()" aria-label="Clear">✕</button>
                    <span class="sp-badge" x-show="isImdb" x-cloak>IMDb</span>
                    <span class="sp-kbd" x-show="query.length === 0">/</span>
                    <button type="submit" class="sp-go">
                        <span><?= $isFa ? 'جستجو' : 'Search' ?></span>
                        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.6 8h10M8.8 4.2L12.6 8l-3.8 3.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
            </div>
        </form>

        <!-- Smart Autocomplete Dropdown (Clean, No Highlighting) -->
        <div class="ac" x-show="open && (results.length > 0 || recent.length > 0)" x-cloak>
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

        <div class="smeta">
            <button type="button" class="ex" @click="pickExample('Inception')">Inception</button>
            <button type="button" class="ex" @click="pickExample('Breaking Bad')">Breaking Bad</button>
            <button type="button" class="ex" @click="pickExample('Dune')">Dune</button>
            <button type="button" class="ex mono" @click="pickExample('tt0816692')">tt0816692</button>
        </div>
    </div>

    <?php if ($homeBelowSearchAd !== ''): ?>
        <div style="max-width:760px;margin:28px auto 0">
            <div data-ad-slot="home_below_search" class="ad-card">
                <?= $homeBelowSearchAd ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Spotlight Card -->
    <?php if ($spotlight !== null): ?>
        <?php $spotHue = $hueFromId($spotlight->imdbId); ?>
        <a class="spot" style="--h:<?= $spotHue ?>" href="/subtitles/<?= View::e($spotlight->imdbId) ?>/<?= View::e($spotlight->slug) ?>">
            <span class="spot__p">
                <span class="pv pv--md" style="--h:<?= $spotHue ?>">
                    <span class="pv__bg"></span>
                    <span class="pv__fb"><span class="pv__fb-y"><?= View::e($spotlight->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($spotlight->title) ?></span></span>
                    <img src="<?= View::e($spotlight->posterUrl) ?>" alt="<?= View::e($spotlight->title) ?>" width="400" height="600" onerror="this.style.display='none'">
                </span>
            </span>
            <span class="spot__b">
                <span class="lbl"><?= $isFa ? 'پیشنهاد ویژه' : 'Featured Title' ?></span>
                <h3><?= ($isFa && $spotlight->persianTitle !== '') ? View::e($spotlight->persianTitle) : View::e($spotlight->title) ?></h3>
                <span class="spot__m">
                    <span><?= $spotlight->mediaType === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film') ?></span>
                    <?php if ($spotlight->year !== ''): ?>
                        <i class="sep"></i><span class="mono"><?= View::e($spotlight->year) ?></span>
                    <?php endif; ?>
                    <?php if ($spotlight->voteAverage > 0): ?>
                        <i class="sep"></i><span class="rate">★ <?= number_format($spotlight->voteAverage, 1) ?></span>
                    <?php endif; ?>
                </span>
                <?php $spotDesc = ($isFa && $spotlight->persianOverview !== '') ? $spotlight->persianOverview : $spotlight->overview; ?>
                <?php if ($spotDesc !== ''): ?>
                    <span class="spot__s" <?= ($isFa && $spotlight->persianOverview !== '') ? '' : 'dir="ltr"' ?>><?= View::e($spotDesc) ?></span>
                <?php endif; ?>
            </span>
            <span class="spot__a">
                <span class="btn btn--go">
                    <span><?= $isFa ? 'مشاهده زیرنویس‌ها' : 'View subtitles' ?></span>
                    <svg viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M3 7.5h9M8.5 4l3.5 3.5L8.5 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </span>
        </a>
    <?php endif; ?>

    <!-- Popular Titles with Kind Filter (All / Films / Series) & Grid/List View Switcher -->
    <?php if (!empty($allPopular)): ?>
        <section class="browse">
            <div class="browse__h">
                <h2><?= $isFa ? 'عناوین پرطرفدار' : 'Popular right now' ?></h2>
                <div class="browse__c">
                    <div class="seg" role="group">
                        <button type="button" @click="kind = 'all'" :aria-pressed="String(kind === 'all')"><?= $isFa ? 'همه' : 'All' ?></button>
                        <button type="button" @click="kind = 'movie'" :aria-pressed="String(kind === 'movie')"><?= $isFa ? 'فیلم' : 'Films' ?></button>
                        <button type="button" @click="kind = 'tv'" :aria-pressed="String(kind === 'tv')"><?= $isFa ? 'سریال' : 'Series' ?></button>
                    </div>
                    <div class="seg" role="group" aria-label="Layout">
                        <button type="button" @click="setLayout('grid')" :aria-pressed="String(layout === 'grid')" aria-label="Grid view">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="5" height="5" rx="1.6" stroke="currentColor" stroke-width="1.3"/><rect x="8" y="1" width="5" height="5" rx="1.6" stroke="currentColor" stroke-width="1.3"/><rect x="1" y="8" width="5" height="5" rx="1.6" stroke="currentColor" stroke-width="1.3"/><rect x="8" y="8" width="5" height="5" rx="1.6" stroke="currentColor" stroke-width="1.3"/></svg>
                        </button>
                        <button type="button" @click="setLayout('list')" :aria-pressed="String(layout === 'list')" aria-label="List view">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1.5 3h11M1.5 7h11M1.5 11h11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Grid Layout -->
            <div class="grid" x-show="layout === 'grid'">
                <?php foreach ($allPopular as $item): ?>
                    <?php $itemHue = $hueFromId($item->imdbId); ?>
                    <a
                        href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>"
                        x-show="kind === 'all' || kind === '<?= View::e($item->mediaType) ?>'"
                        class="card"
                    >
                        <span class="card__p">
                            <span class="pv" style="--h:<?= $itemHue ?>">
                                <span class="pv__bg"></span>
                                <span class="pv__fb"><span class="pv__fb-y"><?= View::e($item->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($item->title) ?></span></span>
                                <img src="<?= View::e($item->posterUrl) ?>" alt="<?= View::e($item->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                                <?php if ($item->voteAverage > 0): ?>
                                    <span class="pv__rate">★ <?= number_format($item->voteAverage, 1) ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="card__body">
                            <span class="card__t" dir="ltr"><?= View::e($item->title) ?></span>
                            <span class="card__m">
                                <span class="mono"><?= View::e($item->year) ?></span>
                                <span class="dot"></span>
                                <span><?= $item->mediaType === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film') ?></span>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- List Layout -->
            <div class="rows" x-show="layout === 'list'" x-cloak>
                <?php foreach ($allPopular as $item): ?>
                    <?php $itemHue = $hueFromId($item->imdbId); ?>
                    <a
                        href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>"
                        x-show="kind === 'all' || kind === '<?= View::e($item->mediaType) ?>'"
                        class="row"
                    >
                        <span class="row__p">
                            <span class="pv pv--sm" style="--h:<?= $itemHue ?>">
                                <span class="pv__bg"></span>
                                <img src="<?= View::e($item->posterUrl) ?>" alt="" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                            </span>
                        </span>
                        <span style="min-width:0">
                            <span class="row__t" dir="ltr"><?= View::e($item->title) ?></span>
                            <span class="row__m">
                                <?= View::e($item->year) ?> · <?= $item->mediaType === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film') ?><?= $item->voteAverage > 0 ? ' · ★ ' . number_format($item->voteAverage, 1) : '' ?>
                            </span>
                        </span>
                        <span></span>
                        <svg class="row__a" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M5.5 3l4 4.5-4 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($homeBetweenAd !== ''): ?>
        <div style="margin-bottom:44px">
            <div data-ad-slot="home_between" class="ad-card">
                <?= $homeBetweenAd ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($homeFooterAd !== ''): ?>
        <div style="margin-bottom:48px">
            <div data-ad-slot="home_footer_banner" class="ad-card">
                <?= $homeFooterAd ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<script nonce="<?= CSP_NONCE ?>">
    function cuePreviewBox() {
        const lines = [
            "You said the last train had already gone.",
            "It had. I walked the rest of the way.",
            "Nobody walks eleven kilometres in this weather.",
            "Then somebody did, because here I am.",
            "We have until morning. Not a minute more.",
            "Then we go over the hill like we used to."
        ];
        return {
            idx: 0,
            fading: false,
            line1: lines[0],
            line2: lines[1],
            tc: '00:01:12,480',
            start() {
                setInterval(() => {
                    this.fading = true;
                    setTimeout(() => {
                        this.idx = (this.idx + 1) % lines.length;
                        this.line1 = lines[this.idx];
                        this.line2 = lines[(this.idx + 1) % lines.length];
                        const secs = 72 + this.idx * 4;
                        const m = String(Math.floor(secs / 60)).padStart(2, '0');
                        const s = String(secs % 60).padStart(2, '0');
                        this.tc = '00:' + m + ':' + s + ',480';
                        this.fading = false;
                    }, 320);
                }, 3600);
            }
        };
    }
</script>

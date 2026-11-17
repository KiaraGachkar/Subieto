<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\View;
use App\Services\LanguageMap;
/** @var \App\DTOs\MediaItem[] $trendingMovies */
/** @var \App\DTOs\MediaItem[] $trendingSeries */
/** @var \App\DTOs\MediaItem[] $topPicks */
/** @var \App\Core\SettingsManager $settings */
/** @var string|null $locale */
/** @var array<int, array<string, mixed>> $latestSubtitles */

$isFa = (($locale ?? 'en') === 'fa');
$homeBelowSearchAd = $settings->getAd('home_below_search');
$homeBetweenAd     = $settings->getAd('home_between');
$homeFooterAd      = $settings->getAd('home_footer_banner');

$trendFilms  = array_slice($trendingMovies, 0, 6);
$trendSeries = array_slice($trendingSeries, 0, 6);

$allPopular = [];
$seenIds = [];
foreach (array_merge(array_slice($trendingMovies, 6), array_slice($trendingSeries, 6)) as $m) {
    if (!isset($seenIds[$m->imdbId])) {
        $seenIds[$m->imdbId] = true;
        $allPopular[] = $m;
    }
}
$allPopular = array_slice($allPopular, 0, 12);

$langLinks = [];
foreach ($latestSubtitles as $row) {
    $raw = (string) ($row['langs_json'] ?? '');
    if ($raw === '') {
        continue;
    }
    $codes = json_decode($raw, true);
    if (!is_array($codes)) {
        continue;
    }
    foreach ($codes as $code) {
        $code = strtolower(trim((string) $code));
        if ($code === '' || isset($langLinks[$code])) {
            continue;
        }
        $resolved = LanguageMap::resolve($code);
        $slug = (string) ($resolved['slug'] ?? '');
        if ($slug === '' || LanguageMap::fromUrlSlug($slug) === null) {
            continue;
        }
        $langLinks[$code] = [
            'label' => (string) ($resolved['label'] ?? strtoupper($code)),
            'url'   => '/subtitles/' . rawurlencode((string) $row['imdb_id']) . '/' . rawurlencode((string) $row['slug']) . '/' . $slug,
        ];
    }
}
$langLinks = array_slice($langLinks, 0, 8);

$hueFromId = static fn(string $id): int => (abs(crc32($id)) % 360);
?>
<div class="col" x-data="{ trendTab: 'movie', kind: 'all', layout: localStorage.getItem('sb_view') || 'grid', setLayout(v){ this.layout = v; localStorage.setItem('sb_view', v); } }">

    <!-- Editorial Hero + Click-to-play Cue Strip -->
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
                    ? 'نسخه‌ای که دارید را پیدا کنید و فایل زیرنویس سالم و خوانا دانلود کنید.'
                    : 'Find the release you actually have and download a clean UTF-8 .srt.' ?>
            </p>
        </div>
    </section>

    <?php if ($homeBelowSearchAd !== ''): ?>
        <div style="max-width:760px;margin:28px auto 0">
            <div data-ad-slot="home_below_search" class="ad-card">
                <?= $homeBelowSearchAd ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Top 5 — most downloaded on this site -->
    <?php if (!empty($topPicks)): ?>
        <section class="top5">
            <div class="browse__h">
                <h2><?= $isFa ? 'برترین‌های این هفته' : 'Top 5 this week' ?></h2>
                <span class="lbl"><?= $isFa ? 'بیشترین دانلود' : 'Most downloaded' ?></span>
            </div>
            <div class="topgrid">
                <?php foreach ($topPicks as $i => $item): ?>
                    <?php $topHue = $hueFromId($item->imdbId); ?>
                    <a class="slide" href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>">
                        <span class="pv" style="--h:<?= $topHue ?>">
                            <span class="pv__bg"></span>
                            <img src="<?= View::e($item->posterUrl) ?>" alt="<?= View::e($item->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                            <?php if ($item->voteAverage > 0): ?><span class="pv__rate">★ <?= number_format($item->voteAverage, 1) ?></span><?php endif; ?>
                        </span>
                        <span class="slide__t" dir="ltr"><?= View::e($item->title) ?></span>
                        <span class="slide__m"><span class="mono"><?= View::e($item->year) ?></span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Trending — Films / Series tabs -->
    <?php if (!empty($trendFilms) || !empty($trendSeries)): ?>
        <section class="trend">
            <div class="browse__h">
                <h2><?= $isFa ? 'ترندها' : 'Trending now' ?></h2>
                <div class="ttabs" role="group" aria-label="<?= $isFa ? 'نوع' : 'Trending type' ?>">
                    <button type="button" class="ttab" @click="trendTab = 'movie'" :aria-pressed="String(trendTab === 'movie')"><?= $isFa ? 'فیلم' : 'Films' ?></button>
                    <button type="button" class="ttab" @click="trendTab = 'tv'" :aria-pressed="String(trendTab === 'tv')"><?= $isFa ? 'سریال' : 'Series' ?></button>
                </div>
            </div>

            <?php if (!empty($trendFilms)): ?>
                <div class="grid" x-show="trendTab === 'movie'">
                    <?php foreach ($trendFilms as $item): ?>
                        <?php $itemHue = $hueFromId($item->imdbId); ?>
                        <a href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>" class="card">
                            <span class="card__p">
                                <span class="pv" style="--h:<?= $itemHue ?>">
                                    <span class="pv__bg"></span>
                                    <span class="pv__fb"><span class="pv__fb-y"><?= View::e($item->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($item->title) ?></span></span>
                                    <img src="<?= View::e($item->posterUrl) ?>" alt="<?= View::e($item->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                                    <?php if ($item->voteAverage > 0): ?><span class="pv__rate">★ <?= number_format($item->voteAverage, 1) ?></span><?php endif; ?>
                                </span>
                            </span>
                            <span class="card__body">
                                <span class="card__t" dir="ltr"><?= View::e($item->title) ?></span>
                                <span class="card__m"><span class="mono"><?= View::e($item->year) ?></span><span class="dot"></span><span><?= $isFa ? 'فیلم' : 'Film' ?></span></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($trendSeries)): ?>
                <div class="grid" x-show="trendTab === 'tv'" x-cloak>
                    <?php foreach ($trendSeries as $item): ?>
                        <?php $itemHue = $hueFromId($item->imdbId); ?>
                        <a href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>" class="card">
                            <span class="card__p">
                                <span class="pv" style="--h:<?= $itemHue ?>">
                                    <span class="pv__bg"></span>
                                    <span class="pv__fb"><span class="pv__fb-y"><?= View::e($item->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($item->title) ?></span></span>
                                    <img src="<?= View::e($item->posterUrl) ?>" alt="<?= View::e($item->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                                    <?php if ($item->voteAverage > 0): ?><span class="pv__rate">★ <?= number_format($item->voteAverage, 1) ?></span><?php endif; ?>
                                </span>
                            </span>
                            <span class="card__body">
                                <span class="card__t" dir="ltr"><?= View::e($item->title) ?></span>
                                <span class="card__m"><span class="mono"><?= View::e($item->year) ?></span><span class="dot"></span><span><?= $isFa ? 'سریال' : 'Series' ?></span></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Popular Titles (12) with Kind Filter & Grid/List Switcher -->
    <?php if (!empty($allPopular)): ?>
        <section class="browse">
            <div class="browse__h">
                <h2><?= $isFa ? 'عناوین پرطرفدار' : 'Popular right now' ?></h2>
                <div class="browse__c">
                    <div class="ttabs" role="group">
                        <button type="button" class="ftab" @click="kind = 'all'" :aria-pressed="String(kind === 'all')"><?= $isFa ? 'همه' : 'All' ?></button>
                        <button type="button" class="ftab" @click="kind = 'movie'" :aria-pressed="String(kind === 'movie')"><?= $isFa ? 'فیلم' : 'Films' ?></button>
                        <button type="button" class="ftab" @click="kind = 'tv'" :aria-pressed="String(kind === 'tv')"><?= $isFa ? 'سریال' : 'Series' ?></button>
                        <span class="fsep"></span>
                        <button type="button" class="ftab" @click="setLayout('grid')" :aria-pressed="String(layout === 'grid')"><?= $isFa ? 'شبکه' : 'Grid' ?></button>
                        <button type="button" class="ftab" @click="setLayout('list')" :aria-pressed="String(layout === 'list')"><?= $isFa ? 'فهرست' : 'List' ?></button>
                    </div>
                </div>
            </div>

            <div class="grid" x-show="layout === 'grid'">
                <?php foreach ($allPopular as $item): ?>
                    <?php $itemHue = $hueFromId($item->imdbId); ?>
                    <a href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>" x-show="kind === 'all' || kind === '<?= View::e($item->mediaType) ?>'" class="card">
                        <span class="card__p">
                            <span class="pv" style="--h:<?= $itemHue ?>">
                                <span class="pv__bg"></span>
                                <span class="pv__fb"><span class="pv__fb-y"><?= View::e($item->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($item->title) ?></span></span>
                                <img src="<?= View::e($item->posterUrl) ?>" alt="<?= View::e($item->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                                <?php if ($item->voteAverage > 0): ?><span class="pv__rate">★ <?= number_format($item->voteAverage, 1) ?></span><?php endif; ?>
                            </span>
                        </span>
                        <span class="card__body">
                            <span class="card__t" dir="ltr"><?= View::e($item->title) ?></span>
                            <span class="card__m"><span class="mono"><?= View::e($item->year) ?></span><span class="dot"></span><span><?= $item->mediaType === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film') ?></span></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="rows" x-show="layout === 'list'" x-cloak>
                <?php foreach ($allPopular as $item): ?>
                    <?php $itemHue = $hueFromId($item->imdbId); ?>
                    <a href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>" x-show="kind === 'all' || kind === '<?= View::e($item->mediaType) ?>'" class="row">
                        <span class="row__p">
                            <span class="pv pv--sm" style="--h:<?= $itemHue ?>">
                                <span class="pv__bg"></span>
                                <img src="<?= View::e($item->posterUrl) ?>" alt="" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                            </span>
                        </span>
                        <span style="min-width:0">
                            <span class="row__t" dir="ltr"><?= View::e($item->title) ?></span>
                            <span class="row__m"><?= View::e($item->year) ?> · <?= $item->mediaType === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film') ?><?= $item->voteAverage > 0 ? ' · ★ ' . number_format($item->voteAverage, 1) : '' ?></span>
                        </span>
                        <span></span>
                        <svg class="row__a" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M5.5 3l4 4.5-4 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($homeBetweenAd !== ''): ?>
        <div style="margin-bottom:32px">
            <div data-ad-slot="home_between" class="ad-card">
                <?= $homeBetweenAd ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recently added + Language shortcuts -->
    <section class="extras">
        <div class="extra">
            <div class="browse__h"><h2><?= $isFa ? 'تازه اضافه شده' : 'Recently added' ?></h2></div>
            <?php if (!empty($latestSubtitles)): ?>
                <div class="rlist">
                    <?php foreach (array_slice($latestSubtitles, 0, 6) as $row): ?>
                        <a class="ritem" href="/subtitles/<?= View::e($row['imdb_id']) ?>/<?= View::e($row['slug']) ?>">
                            <span class="rdot"></span>
                            <span class="rtx"><b dir="ltr"><?= View::e($row['title']) ?></b><small><?= View::e($row['year']) ?> · <?= ($row['media_type'] ?? '') === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film') ?></small></span>
                            <svg class="rarrow" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M5.5 3l4 4.5-4 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="xempty"><?= $isFa ? 'هنوز زیرنویسی اضافه نشده است.' : 'No subtitles added yet.' ?></p>
            <?php endif; ?>
        </div>
        <div class="extra">
            <div class="browse__h"><h2><?= $isFa ? 'مرور بر اساس زبان' : 'Browse by language' ?></h2></div>
            <?php if (!empty($langLinks)): ?>
                <div class="lchips">
                    <?php foreach ($langLinks as $link): ?>
                        <a class="lchip" href="<?= View::e($link['url']) ?>"><?= View::e($link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="xempty"><?= $isFa ? 'زبان‌ها در دسترس نیستند.' : 'No languages available yet.' ?></p>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($homeFooterAd !== ''): ?>
        <div style="margin-bottom:48px">
            <div data-ad-slot="home_footer_banner" class="ad-card">
                <?= $homeFooterAd ?>
            </div>
        </div>
    <?php endif; ?>

</div>


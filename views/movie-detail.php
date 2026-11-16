<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\View;
use App\Services\LanguageMap;
/** @var \App\DTOs\MediaItem $media */
/** @var \App\DTOs\SubtitleItem[] $subtitles */
/** @var int $totalSubsCount */
/** @var int|null $activeSeason */
/** @var int[] $availableSeasons */
/** @var \App\DTOs\MediaItem[] $similarMedia */
/** @var \App\Core\SettingsManager $settings */
/** @var \App\Core\UrlSigner $signer */
/** @var string|null $clientIp */
/** @var string|null $locale */
/** @var array{code: string, label: string, slug: string}|null $activeLangInfo */
/** @var int|null $tokenTtl */

$isFa         = (($locale ?? 'en') === 'fa');
$initialLang  = $activeLangInfo['code'] ?? 'all';
$sidebarAd    = $settings->getAd('movie_sidebar');
$aboveTableAd = $settings->getAd('movie_above_table');
$belowTableAd = $settings->getAd('movie_below_table');
$inFeedAd     = $settings->getAd('movie_in_feed');
$typeLabel    = $media->mediaType === 'tv' ? ($isFa ? 'سریال' : 'Series') : ($isFa ? 'فیلم' : 'Film');
$baseMediaUrl = '/subtitles/' . $media->imdbId . '/' . $media->slug;
$mediaHue     = (abs(crc32($media->imdbId)) % 360);
$hueFromId    = static fn(string $id): int => (abs(crc32($id)) % 360);

$langCounts = [];
$qualities  = [];
$episodes   = [];
foreach ($subtitles as $s) {
    $code = $s->languageCode !== '' ? $s->languageCode : 'un';
    if (!isset($langCounts[$code])) {
        $langCounts[$code] = [
            'code'  => $code,
            'label' => $s->languageLabel !== '' ? $s->languageLabel : strtoupper($code),
            'count' => 0,
        ];
    }
    $langCounts[$code]['count']++;

    if ($s->qualityTag !== '' && $s->qualityTag !== 'All') {
        $qualities[$s->qualityTag] = $s->qualityTag;
    }
    if ($s->episode !== null && $s->episode > 0) {
        $episodes[$s->episode] = $s->episode;
    }
}
ksort($episodes);

$prioRaw  = strtolower(trim((string) $settings->get('providers.priority_languages', $isFa ? 'fa,en,ar,tr,es,fr' : 'en,fa,ar,es,fr,tr')));
$prioList = array_values(array_filter(array_map('trim', explode(',', $prioRaw)), static fn(string $v): bool => $v !== ''));
$prioMap  = array_flip($prioList);
uasort($langCounts, static function (array $a, array $b) use ($prioMap): int {
    $rA = $prioMap[$a['code']] ?? 999;
    $rB = $prioMap[$b['code']] ?? 999;
    if ($rA !== $rB) return $rA <=> $rB;
    return $b['count'] <=> $a['count'];
});

$hueColor = static function (string $code): string {
    return match ($code) {
        'en' => 'var(--sky)',
        'fa' => 'var(--green)',
        'ar', 'tr', 'he' => 'var(--amber)',
        'es', 'pt', 'fr', 'it' => 'var(--rust)',
        'de', 'nl', 'sv', 'fi', 'no', 'da' => 'var(--teal)',
        default => 'var(--violet)',
    };
};
?>
<div class="col" x-data="subtitlePage()">

    <!-- Breadcrumb -->
    <div class="crumb">
        <a href="/">
            <svg viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M8 2.5L4 6.5l4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span><?= $isFa ? 'خانه' : 'Home' ?></span>
        </a>
        <span style="color:var(--muted);font-size:13px;padding-inline:6px" aria-hidden="true">/</span>
        <a href="<?= View::e($baseMediaUrl) ?>">
            <span dir="ltr"><?= View::e($media->title) ?></span>
        </a>
        <?php if ($activeSeason !== null && $activeSeason > 0): ?>
            <span style="color:var(--muted);font-size:13px;padding-inline:6px" aria-hidden="true">/</span>
            <span style="font-size:13px;color:var(--muted)"><?= $isFa ? "فصل {$activeSeason}" : "Season {$activeSeason}" ?></span>
        <?php endif; ?>
    </div>

    <!-- Editorial Title Head -->
    <div class="head">
        <div class="head__p">
            <div class="pv pv--md" style="--h:<?= $mediaHue ?>">
                <div class="pv__bg"></div>
                <div class="pv__fb"><span class="pv__fb-y"><?= View::e($media->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($media->title) ?></span></div>
                <img src="<?= View::e($media->posterUrl) ?>" alt="<?= View::e($media->title) ?>" width="400" height="600" fetchpriority="high" onerror="this.style.display='none'">
            </div>
        </div>

        <div>
            <?php if ($isFa && $media->persianTitle !== ''): ?>
                <span class="lbl"><?= View::e($media->persianTitle) ?></span>
            <?php else: ?>
                <span class="lbl"><?= $typeLabel ?></span>
            <?php endif; ?>

            <h1>
                <?php if ($isFa): ?>
                    دانلود زیرنویس <?= $activeLangInfo !== null ? View::e($activeLangInfo['label']) . ' ' : '' ?><?= $activeSeason !== null ? "فصل {$activeSeason} " : '' ?><span dir="ltr"><?= View::e($media->title) ?></span>
                <?php else: ?>
                    <?= View::e($media->title) ?><?= $activeSeason !== null ? " — Season {$activeSeason}" : '' ?><?= $activeLangInfo !== null ? ' ' . View::e($activeLangInfo['label']) : '' ?> Subtitles
                <?php endif; ?>
            </h1>

            <div class="head__m">
                <span><?= $typeLabel ?></span>
                <?php if ($media->year !== ''): ?>
                    <i class="sep"></i><span class="mono"><?= View::e($media->year) ?></span>
                <?php endif; ?>
                <?php if ($media->voteAverage > 0): ?>
                    <i class="sep"></i><span class="rate">★ <?= number_format($media->voteAverage, 1) ?></span>
                <?php endif; ?>
                <i class="sep"></i><span class="mono"><?= View::e($media->imdbId) ?></span>
                <?php if (count($langCounts) > 0): ?>
                    <i class="sep"></i><span><?= count($langCounts) ?> <?= $isFa ? 'زبان' : 'languages' ?></span>
                <?php endif; ?>
            </div>

            <?php if ($isFa && $media->persianOverview !== ''): ?>
                <p class="head__s"><?= View::e($media->persianOverview) ?></p>
            <?php elseif ($media->overview !== ''): ?>
                <p class="head__s" dir="ltr"><?= View::e($media->overview) ?></p>
            <?php endif; ?>

            <!-- Season Selector Pills -->
            <?php if ($media->mediaType === 'tv' && !empty($availableSeasons)): ?>
                <div class="head__x">
                    <a href="<?= View::e($baseMediaUrl) ?>" class="pill" aria-pressed="<?= $activeSeason === null ? 'true' : 'false' ?>">
                        <?= $isFa ? 'همه فصل‌ها' : 'All seasons' ?>
                    </a>
                    <?php foreach ($availableSeasons as $sNum): ?>
                        <a href="<?= View::e($baseMediaUrl . '/season-' . $sNum) ?>" class="pill" aria-pressed="<?= $activeSeason === $sNum ? 'true' : 'false' ?>">
                            <?= $isFa ? "فصل {$sNum}" : "Season {$sNum}" ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($sidebarAd !== ''): ?>
            <aside data-ad-slot="movie_sidebar" class="ad-card" style="width:240px;flex:none">
                <?= $sidebarAd ?>
            </aside>
        <?php endif; ?>
    </div>

    <!-- Visual Cue Ruler & Interactive Timing Shift Panel -->
    <div class="sync">
        <div class="sync__g">
            <div class="sync__l">
                <div class="sync__hd">
                    <span class="lbl"><?= $isFa ? 'زمان‌بندی' : 'Timing' ?></span>
                    <span class="lbl"><?= $isFa ? 'پیش‌نمایش ۸ خط اول' : 'First 8 cues · relative' ?></span>
                </div>
                <div class="sync__t" dir="ltr">
                    <s>00:01:12,480</s>
                    <svg viewBox="0 0 14 10" fill="none" aria-hidden="true"><path d="M1 5h11M9 2l3 3-3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <b :class="Math.abs(timeShift) >= 0.05 ? 'on' : ''" x-text="shiftedBaseTc">00:01:12,480</b>
                </div>
                <p class="sync__x" dir="ltr" x-text="sampleLine">You said the last train had already gone.</p>
                <div class="ruler" aria-hidden="true">
                    <span class="tick" style="inset-inline-start:0%"></span><span class="tl" style="inset-inline-start:0%">0s</span>
                    <span class="tick" style="inset-inline-start:25%"></span><span class="tl" style="inset-inline-start:25%">10s</span>
                    <span class="tick" style="inset-inline-start:50%"></span><span class="tl" style="inset-inline-start:50%">20s</span>
                    <span class="tick" style="inset-inline-start:75%"></span><span class="tl" style="inset-inline-start:75%">30s</span>
                    <span class="tick" style="inset-inline-start:100%"></span><span class="tl" style="inset-inline-start:100%">40s</span>
                    <template x-for="(pos, idx) in ghostMarks" :key="'g'+idx">
                        <span class="gh" :style="'inset-inline-start:' + pos + '%'"></span>
                    </template>
                    <template x-for="(pos, idx) in liveMarks" :key="'l'+idx">
                        <span class="lv" :class="Math.abs(timeShift) >= 0.05 ? 'on' : ''" :style="'inset-inline-start:' + pos.pct + '%;opacity:' + pos.op"></span>
                    </template>
                </div>
            </div>

            <div class="sync__r">
                <div class="sync__rh">
                    <span class="lbl"><?= $isFa ? 'تغییر زمان' : 'Shift' ?></span>
                    <button type="button" class="linkb" x-show="Math.abs(timeShift) >= 0.05" x-cloak @click="timeShift = 0">
                        <?= $isFa ? 'بازنشانی' : 'Reset' ?>
                    </button>
                </div>
                <div class="step" dir="ltr">
                    <button type="button" @click="adjustShift(-0.5)" aria-label="Shift earlier">−</button>
                    <output :class="Math.abs(timeShift) >= 0.05 ? 'on' : ''" x-text="(timeShift > 0 ? '+' : '') + timeShift.toFixed(1) + 's'">0.0s</output>
                    <button type="button" @click="adjustShift(0.5)" aria-label="Shift later">+</button>
                </div>
                <input type="range" min="-10" max="10" step="0.1" x-model.number="timeShift" aria-label="Fine timing shift">
                <div class="sync__f" dir="ltr">
                    <span>−10s</span>
                    <span><?= $isFa ? 'اعمال روی فایل دانلودی' : 'Applies to files' ?></span>
                    <span>+10s</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Language Pills + Release Search + Quality Filter + Match My Video File -->
    <div class="tools">
        <div class="pills">
            <a href="<?= View::e($baseMediaUrl) ?>" class="pill" @click.prevent="lang = 'all'" :aria-pressed="String(lang === 'all')">
                <span><?= $isFa ? 'همه زبان‌ها' : 'All languages' ?></span>
                <u><?= count($subtitles) ?></u>
            </a>
            <?php foreach ($langCounts as $lc): ?>
                <?php
                    $resolved = LanguageMap::resolve($lc['code']);
                    $lSlug = LanguageMap::fromUrlSlug($resolved['slug']) !== null ? $resolved['slug'] : null;
                ?>
                <?php if ($lSlug !== null): ?>
                    <a
                        href="<?= View::e($baseMediaUrl . '/' . $lSlug) ?>"
                        @click.prevent="lang = <?= View::e(json_encode($lc['code'])) ?>"
                        :aria-pressed="String(lang === <?= View::e(json_encode($lc['code'])) ?>)"
                        class="pill"
                    >
                        <span class="dot" style="background:<?= $hueColor($lc['code']) ?>"></span>
                        <span><?= View::e($lc['label']) ?></span>
                        <u><?= $lc['count'] ?></u>
                    </a>
                <?php else: ?>
                    <button
                        type="button"
                        @click="lang = <?= View::e(json_encode($lc['code'])) ?>"
                        :aria-pressed="String(lang === <?= View::e(json_encode($lc['code'])) ?>)"
                        class="pill"
                    >
                        <span class="dot" style="background:<?= $hueColor($lc['code']) ?>"></span>
                        <span><?= View::e($lc['label']) ?></span>
                        <u><?= $lc['count'] ?></u>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="tools__r">
            <div class="field">
                <svg viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M2 4h11M4 7.5h7M6 11h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                <input
                    type="text"
                    x-model="releaseFilter"
                    placeholder="<?= $isFa ? 'فیلتر نسخه‌ها — 1080p، BluRay، YTS...' : 'Filter releases — 1080p, BluRay, YTS...' ?>"
                >
            </div>

            <?php if ($media->mediaType === 'tv' && !empty($episodes)): ?>
                <select class="sel" x-model="episodeFilter" aria-label="Episode">
                    <option value="all"><?= $isFa ? 'همه قسمت‌ها' : 'All episodes' ?></option>
                    <?php foreach ($episodes as $epNum): ?>
                        <option value="<?= (int) $epNum ?>"><?= $isFa ? "قسمت {$epNum}" : 'Episode ' . str_pad((string) $epNum, 2, '0', STR_PAD_LEFT) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if (!empty($qualities)): ?>
                <select class="sel" x-model="qualityFilter" aria-label="Quality">
                    <option value="all"><?= $isFa ? 'همه کیفیت‌ها' : 'Any quality' ?></option>
                    <?php foreach ($qualities as $qTag): ?>
                        <option value="<?= View::e($qTag) ?>"><?= View::e($qTag) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <label class="btn" style="cursor:pointer">
                <svg viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M7.5 10V2.6M4.8 5.3L7.5 2.6l2.7 2.7M2.2 10.6v1.8h10.6v-1.8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><?= $isFa ? 'تطبیق با فایل ویدیو' : 'Match my file' ?></span>
                <input type="file" @change="handleFileSelect($event)" accept=".mkv,.mp4,.avi,.mov,.wmv,.m4v" hidden>
            </label>
        </div>

        <div class="matchbar" x-show="matchedRelease !== ''" x-cloak>
            <span style="flex:1;min-width:180px">
                <?= $isFa ? 'تطبیق با:' : 'Matching:' ?>
                <strong class="mono" dir="ltr" x-text="matchedRelease"></strong>
            </span>
            <button type="button" class="linkb" style="color:var(--green-deep)" @click="matchedRelease = ''; matchedTokens = []">
                <?= $isFa ? 'حذف فایل' : 'Clear file' ?>
            </button>
        </div>
    </div>

    <?php if ($aboveTableAd !== ''): ?>
        <div style="margin-top:22px">
            <div data-ad-slot="movie_above_table" class="ad-card">
                <?= $aboveTableAd ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Count Bar -->
    <div class="count">
        <span><?= count($subtitles) ?> <?= $isFa ? 'زیرنویس موجود' : 'subtitles available' ?></span>
        <span class="shifted" x-show="Math.abs(timeShift) >= 0.05" x-cloak x-text="'<?= $isFa ? 'تغییر زمان: ' : 'shifted ' ?>' + (timeShift > 0 ? '+' : '') + timeShift.toFixed(1) + 's'"></span>
    </div>

    <!-- Subtitle List (Clean: Release Name + Language + Quality + Translator, NO external source clutter) -->
    <div class="subs">
        <?php if (empty($subtitles)): ?>
            <div style="padding:48px 16px;text-align:center;color:var(--muted)">
                <?= $isFa ? 'هنوز زیرنویسی برای این عنوان یافت نشد.' : 'No subtitles found for this title yet.' ?>
            </div>
        <?php else: ?>
            <?php foreach ($subtitles as $idx => $sub): ?>
                <?php
                    $signedToken = $signer->sign([
                        'url'      => $sub->downloadUrl,
                        'file'     => $sub->fileName,
                        'imdb'     => $media->imdbId,
                        'title'    => $media->title,
                        'provider' => $sub->provider,
                        'lang'     => $sub->languageCode,
                    ], (int) ($tokenTtl ?? 14400), $clientIp ?? null);

                    $encodedToken = urlencode($signedToken);
                    $dlHref = '/download?token=' . $encodedToken;
                    $searchIndex = mb_strtolower($sub->releaseName . ' ' . $sub->languageLabel . ' ' . $sub->languageCode . ' ' . $sub->translator . ' ' . $sub->qualityTag);
                    $codeUpper = strtoupper(substr($sub->languageCode !== '' ? $sub->languageCode : 'UN', 0, 2));
                    $rowKey = 'dl_' . $idx;
                ?>
                <div
                    data-lang="<?= View::e($sub->languageCode) ?>"
                    data-quality="<?= View::e($sub->qualityTag) ?>"
                    data-episode="<?= $sub->episode !== null ? (int) $sub->episode : '' ?>"
                    data-search="<?= View::e($searchIndex) ?>"
                    x-show="matchesRow($el)"
                    :class="(isBestMatch($el.dataset.search || '') ? 'best ' : '') + (downloaded['<?= $rowKey ?>'] ? 'done' : '')"
                    class="srow"
                >
                    <span class="badge">
                        <?= View::e($codeUpper) ?>
                        <i style="background:<?= $hueColor($sub->languageCode) ?>"></i>
                    </span>

                    <div class="srow__main">
                        <span class="srow__n" dir="ltr"><?= View::e($sub->releaseName) ?></span>
                        <div class="srow__m">
                            <strong style="color:var(--soft)"><?= View::e($sub->languageLabel) ?></strong>
                            <?php if ($sub->qualityTag !== '' && $sub->qualityTag !== 'All'): ?>
                                <i class="sep"></i>
                                <span class="tagx"><?= View::e($sub->qualityTag) ?></span>
                            <?php endif; ?>
                            <?php if ($sub->season !== null): ?>
                                <i class="sep"></i>
                                <span class="tagx tagx--ep" dir="ltr">S<?= str_pad((string)$sub->season, 2, '0', STR_PAD_LEFT) ?><?= $sub->episode !== null ? 'E' . str_pad((string)$sub->episode, 2, '0', STR_PAD_LEFT) : '' ?></span>
                            <?php endif; ?>
                            <?php if ($sub->translator !== '' && !in_array($sub->translator, ['OpenSubtitles Archive', 'OpenSubtitles', 'Podnapisi', 'YIFY', 'Subf2m'], true)): ?>
                                <i class="sep"></i>
                                <span><?= View::e($sub->translator) ?></span>
                            <?php endif; ?>
                            <em x-show="isBestMatch($el.closest('[data-search]')?.dataset.search || '')" x-cloak>
                                ✓ <?= $isFa ? 'هماهنگ با فایل شما' : 'Best match' ?>
                            </em>
                        </div>
                    </div>

                    <div class="srow__a">
                        <button
                            type="button"
                            class="eye"
                            @click="openPreview(<?= View::e(json_encode($sub->releaseName)) ?>, <?= View::e(json_encode($encodedToken)) ?>)"
                            aria-label="<?= $isFa ? 'پیش‌نمایش' : 'Preview' ?>"
                            title="<?= $isFa ? 'پیش‌نمایش' : 'Preview' ?>"
                        >
                            <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M1.5 8S3.9 3.6 8 3.6 14.5 8 14.5 8 12.1 12.4 8 12.4 1.5 8 1.5 8Z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="8" r="1.8" stroke="currentColor" stroke-width="1.3"/></svg>
                        </button>

                        <a
                            :href="'<?= View::e($dlHref) ?>' + (Math.abs(timeShift) >= 0.05 ? '&shift=' + timeShift.toFixed(1) : '')"
                            @click="markDownloaded('<?= $rowKey ?>', <?= View::e(json_encode($sub->releaseName)) ?>)"
                            rel="nofollow noopener"
                            class="btn btn--go dl"
                        >
                            <span x-text="downloaded['<?= $rowKey ?>'] ? '<?= $isFa ? 'ذخیره شد' : 'Saved' ?>' : ('<?= $isFa ? 'دانلود' : 'Download' ?>' + (Math.abs(timeShift) >= 0.05 ? ' (' + (timeShift > 0 ? '+' : '') + timeShift.toFixed(1) + 's)' : ''))"><?= $isFa ? 'دانلود' : 'Download' ?></span>
                        </a>
                    </div>
                </div>

                <?php if ($idx === 2 && $inFeedAd !== ''): ?>
                    <div style="padding:12px 0">
                        <div data-ad-slot="movie_in_feed" class="ad-card">
                            <?= $inFeedAd ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($belowTableAd !== ''): ?>
        <div style="margin-top:26px">
            <div data-ad-slot="movie_below_table" class="ad-card">
                <?= $belowTableAd ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Clean Editorial Sync Guide & Crawlable Language Links (SEO) -->
    <?php if (!empty($subtitles)): ?>
        <section style="margin-top:34px;padding:20px 22px;border-radius:var(--r-panel);background:var(--surface);border:1px solid var(--line);font-size:13.5px;color:var(--soft);line-height:1.7">
            <h2 style="font-family:var(--serif);font-size:17px;font-weight:500;color:var(--ink);margin:0 0 8px">
                <?= $isFa
                    ? 'راهنمای دانلود و هماهنگ‌سازی زیرنویس ' . View::e($media->title)
                    : 'How to Sync & Use ' . View::e($media->title) . ' Subtitles' ?>
            </h2>
            <p style="margin:0">
                <?php if ($isFa): ?>
                    در این صفحه <strong><?= count($subtitles) ?></strong> فایل زیرنویس برای <?= $typeLabel ?> <span dir="ltr"><?= View::e($media->title) ?></span><?= $media->year !== '' ? ' (' . View::e($media->year) . ')' : '' ?> با کدگذاری استاندارد <strong>UTF-8</strong> و فرمت <strong>SRT</strong> آماده دانلود است. برای بهترین هماهنگی، از دکمه «تطبیق با فایل ویدیو» استفاده کنید یا در صورت جلو و عقب بودن دیالوگ‌ها، از پنل تغییر زمان بالای لیست یا کلیدهای <kbd class="chip">G</kbd> و <kbd class="chip">H</kbd> در نرم‌افزار VLC بهره ببرید.
                <?php else: ?>
                    Download from <strong><?= count($subtitles) ?></strong> verified UTF-8 <strong>.srt</strong> subtitle files for <?= View::e($media->title) ?><?= $media->year !== '' ? ' (' . View::e($media->year) . ')' : '' ?>. Use <em>Match my file</em> to highlight releases matching your video filename, or adjust timestamp drift in 0.5-second steps before downloading.
                <?php endif; ?>
            </p>
            <?php if (count($langCounts) > 1): ?>
                <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px">
                    <span style="color:var(--muted)"><?= $isFa ? 'صفحات اختصاصی زبان:' : 'Language pages:' ?></span>
                    <?php foreach (array_slice($langCounts, 0, 10) as $lc): ?>
                        <?php
                            $resL = LanguageMap::resolve($lc['code']);
                            $sL   = LanguageMap::fromUrlSlug($resL['slug']) !== null ? $resL['slug'] : null;
                        ?>
                        <?php if ($sL !== null): ?>
                            <a href="<?= View::e($baseMediaUrl . '/' . $sL) ?>" class="chip" style="text-decoration:none">
                                <?= View::e($lc['label']) ?> (<?= (int) $lc['count'] ?>)
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Related Titles -->
    <?php if (!empty($similarMedia)): ?>
        <section class="related">
            <h2><?= $isFa ? 'عناوین مشابه' : 'More like this' ?></h2>
            <div class="rgrid">
                <?php foreach ($similarMedia as $sim): ?>
                    <?php $simHue = $hueFromId($sim->imdbId); ?>
                    <a href="/subtitles/<?= View::e($sim->imdbId) ?>/<?= View::e($sim->slug) ?>" class="card">
                        <span class="card__p">
                            <span class="pv pv--md" style="--h:<?= $simHue ?>">
                                <span class="pv__bg"></span>
                                <span class="pv__fb"><span class="pv__fb-y"><?= View::e($sim->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($sim->title) ?></span></span>
                                <img src="<?= View::e($sim->posterUrl) ?>" alt="<?= View::e($sim->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
                            </span>
                        </span>
                        <span class="card__body">
                            <span class="card__t" dir="ltr"><?= View::e($sim->title) ?></span>
                            <span class="card__m"><span class="mono"><?= View::e($sim->year) ?></span></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Live Subtitle Preview Modal -->
    <div
        x-show="previewOpen"
        x-cloak
        @keydown.escape.window="previewOpen = false"
        @click.self="previewOpen = false"
        class="scrim"
    >
        <div class="modal" role="dialog" aria-modal="true">
            <div class="modal__h">
                <b dir="ltr" x-text="previewTitle"></b>
                <button type="button" class="x" @click="previewOpen = false" aria-label="Close">✕</button>
            </div>
            <div class="modal__b">
                <template x-if="previewLoading">
                    <div style="padding:28px;text-align:center;color:var(--muted)"><?= $isFa ? 'در حال بارگذاری...' : 'Loading cues...' ?></div>
                </template>
                <template x-if="!previewLoading && previewCues.length === 0">
                    <div style="padding:28px;text-align:center;color:var(--muted)"><?= $isFa ? 'پیش‌نمایش متنی برای این فایل در دسترس نیست.' : 'Text preview is unavailable for this archive.' ?></div>
                </template>
                <template x-for="(cue, i) in previewCues" :key="i">
                    <div>
                        <div class="cue__t" dir="ltr" x-text="cue.time"></div>
                        <div class="cue__x" x-text="cue.text"></div>
                    </div>
                </template>
            </div>
            <div class="modal__f">
                <button type="button" class="btn btn--sm" @click="previewOpen = false"><?= $isFa ? 'بستن' : 'Close' ?></button>
                <a
                    :href="'/download?token=' + previewToken + (Math.abs(timeShift) >= 0.05 ? '&shift=' + timeShift.toFixed(1) : '')"
                    @click="previewOpen = false; triggerToast(previewTitle)"
                    class="btn btn--go btn--sm"
                >
                    <?= $isFa ? 'دانلود فایل' : 'Download .srt' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div x-show="toastVisible" x-cloak class="toast" role="status">
        <span x-text="toastMessage"></span>
    </div>

</div>

<script nonce="<?= CSP_NONCE ?>">
    function subtitlePage() {
        const baseCuesSec = [0, 4.2, 9.1, 14.5, 19.8, 25.0, 30.4, 35.8];
        return {
            lang: <?= json_encode($initialLang) ?>,
            releaseFilter: '',
            qualityFilter: 'all',
            episodeFilter: 'all',
            timeShift: 0,
            matchedRelease: '',
            matchedTokens: [],
            downloaded: {},
            previewOpen: false,
            previewLoading: false,
            previewTitle: '',
            previewToken: '',
            previewCues: [],
            toastVisible: false,
            toastMessage: '',
            sampleLine: 'You said the last train had already gone.',
            ghostMarks: baseCuesSec.map(s => (s / 40) * 100),
            get liveMarks() {
                return baseCuesSec.map(s => {
                    const p = ((s + Number(this.timeShift)) / 40) * 100;
                    return {
                        pct: Math.max(0, Math.min(100, p)),
                        op: (p < 0 || p > 100) ? 0 : 1
                    };
                });
            },
            get shiftedBaseTc() {
                const baseMs = 72480;
                const total = Math.max(0, Math.round(baseMs + Number(this.timeShift) * 1000));
                const h = String(Math.floor(total / 3600000)).padStart(2, '0');
                const m = String(Math.floor((total % 3600000) / 60000)).padStart(2, '0');
                const s = String(Math.floor((total % 60000) / 1000)).padStart(2, '0');
                const ms = String(total % 1000).padStart(3, '0');
                return h + ':' + m + ':' + s + ',' + ms;
            },
            adjustShift(delta) {
                const next = Math.max(-300, Math.min(300, Number(this.timeShift) + delta));
                this.timeShift = Math.round(next * 10) / 10;
            },
            handleFileSelect(e) {
                if (e.target.files && e.target.files.length > 0) {
                    const clean = e.target.files[0].name.replace(/\.[^/.]+$/, '');
                    this.matchedRelease = clean;
                    const rawTokens = clean.toLowerCase().split(/[\.\-_\s\[\]\(\)]+/).filter(t => t.length >= 2);
                    const stopWords = ['the', 'and', 'for', 'with', 'x264', 'x265', 'h264', 'h265', 'aac', 'ddp5', '10bit', 'mkv', 'mp4', 'avi'];
                    this.matchedTokens = rawTokens.filter(t => !stopWords.includes(t));
                }
            },
            isBestMatch(rowSearch) {
                if (!this.matchedRelease || this.matchedTokens.length === 0) return false;
                let hits = 0;
                for (const tok of this.matchedTokens) {
                    if (rowSearch.includes(tok)) hits++;
                }
                return hits >= Math.min(2, this.matchedTokens.length);
            },
            matchesRow(el) {
                const rowLang = el.dataset.lang || '';
                const rowQual = el.dataset.quality || '';
                const rowEp   = el.dataset.episode || '';
                const rowSearch = el.dataset.search || '';
                const langOk = this.lang === 'all' || this.lang === rowLang;
                const qualOk = this.qualityFilter === 'all' || this.qualityFilter === rowQual;
                const epOk   = this.episodeFilter === 'all' || this.episodeFilter === rowEp;
                const textOk = rowSearch.includes(this.releaseFilter.toLowerCase().trim());
                return langOk && qualOk && epOk && textOk;
            },
            markDownloaded(key, name) {
                this.downloaded[key] = true;
                this.triggerToast(name);
                setTimeout(() => { this.downloaded[key] = false; }, 2400);
            },
            async openPreview(title, encodedToken) {
                this.previewTitle = title;
                this.previewToken = encodedToken;
                this.previewCues = [];
                this.previewLoading = true;
                this.previewOpen = true;
                try {
                    const shiftParam = Math.abs(this.timeShift) >= 0.05 ? '&shift=' + Number(this.timeShift).toFixed(1) : '';
                    const res = await fetch('/api/preview?token=' + encodedToken + shiftParam);
                    const data = await res.json();
                    this.previewCues = data.cues || [];
                } catch (e) {
                    this.previewCues = [];
                }
                this.previewLoading = false;
            },
            triggerToast(name) {
                this.toastMessage = '<?= $isFa ? 'ذخیره شد · ' : 'Saved · ' ?>' + name;
                this.toastVisible = true;
                setTimeout(() => { this.toastVisible = false; }, 2800);
            }
        };
    }
</script>

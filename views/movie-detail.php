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

$langCounts    = [];
$qualities     = [];
$qualityCounts = [];
$episodes      = [];
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
        $qTag = $s->qualityTag;
        $qualities[$qTag] = $qTag;
        $qualityCounts[$qTag] = ($qualityCounts[$qTag] ?? 0) + 1;
    }
    if ($s->episode !== null && $s->episode > 0) {
        $episodes[$s->episode] = $s->episode;
    }
}
ksort($episodes);

$qualityRank = static function (string $tag): int {
    $t = strtolower($tag);
    if (str_contains($t, '2160') || str_contains($t, '4k') || str_contains($t, 'uhd')) return 0;
    if (str_contains($t, '1080')) return 1;
    if (str_contains($t, '720')) return 2;
    if (str_contains($t, '480')) return 3;
    return 4;
};
usort($qualities, static function (string $a, string $b) use ($qualityRank, $qualityCounts): int {
    $rankA = $qualityRank($a);
    $rankB = $qualityRank($b);
    if ($rankA !== $rankB) return $rankA <=> $rankB;
    if ($qualityCounts[$a] !== $qualityCounts[$b]) return $qualityCounts[$b] <=> $qualityCounts[$a];
    return strcasecmp($a, $b);
});

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
                <img src="<?= View::e($media->posterUrl) ?>" alt="<?= View::e($media->title) ?>" width="400" height="600" fetchpriority="high" referrerpolicy="no-referrer" data-imdb="<?= View::e($media->imdbId) ?>">
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


        </div>

        <?php if ($sidebarAd !== ''): ?>
            <aside data-ad-slot="movie_sidebar" class="ad-card" style="width:240px;flex:none">
                <?= $sidebarAd ?>
            </aside>
        <?php endif; ?>
    </div>

    <!-- TV Series Season Selector Section (Prominent Season Cards + Quick Filter) -->
    <?php if ($media->mediaType === 'tv' && !empty($availableSeasons)): ?>
        <section aria-label="<?= $isFa ? 'انتخاب فصل سریال' : 'Select Season' ?>" style="margin-top:24px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r-panel);padding:18px 20px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                <div>
                    <span class="lbl"><?= $isFa ? 'فصل‌های سریال' : 'Series Seasons' ?></span>
                    <h2 style="margin:3px 0 0;font-size:16px;font-weight:600">
                        <?= $activeSeason !== null
                            ? ($isFa ? "در حال نمایش زیرنویس‌های فصل {$activeSeason}" : "Showing Season {$activeSeason} Subtitles")
                            : ($isFa ? 'ابتدا فصل مورد نظر را انتخاب کنید' : 'Choose a season to view its episodes & subtitles') ?>
                    </h2>
                </div>
                <a href="<?= View::e($baseMediaUrl) ?>" class="<?= $activeSeason === null ? 'lchip on' : 'lchip' ?>">
                    <span><?= $isFa ? 'همه فصل‌ها' : 'All Seasons' ?></span>
                    <u><?= (int) $totalSubsCount ?></u>
                </a>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:10px">
                <?php foreach ($availableSeasons as $sNum): ?>
                    <?php
                        $sCnt = (int) ($seasonCounts[$sNum] ?? 0);
                        $isCurrentSeason = ($activeSeason === $sNum);
                    ?>
                    <a
                        href="<?= View::e($baseMediaUrl . '/season-' . $sNum) ?>"
                        style="display:flex;flex-direction:column;justify-content:space-between;padding:12px 14px;border-radius:14px;border:1px solid <?= $isCurrentSeason ? 'var(--green)' : 'var(--firm)' ?>;background:<?= $isCurrentSeason ? 'var(--green-wash)' : 'var(--paper)' ?>;transition:border-color .15s,transform .15s"
                    >
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
                            <span class="mono" style="font-size:11px;font-weight:700;color:<?= $isCurrentSeason ? 'var(--green-deep)' : 'var(--muted)' ?>">S<?= str_pad((string) $sNum, 2, '0', STR_PAD_LEFT) ?></span>
                            <?php if ($sCnt > 0): ?>
                                <span class="chip" style="<?= $isCurrentSeason ? 'background:var(--green);color:#fff;border-color:var(--green)' : '' ?>"><?= $sCnt ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:6px;font-size:13.5px;font-weight:600;color:<?= $isCurrentSeason ? 'var(--green-deep)' : 'var(--ink)' ?>">
                            <?= $isFa ? "فصل {$sNum}" : "Season {$sNum}" ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Language Pills + Release Search + Quality Filter + Match My Video File -->
    <div class="tools">
        <div class="pills">
            <label class="field langsearch" style="height:30px;min-width:170px">
                <svg viewBox="0 0 22 22" fill="none" aria-hidden="true"><circle cx="9.6" cy="9.6" r="7" stroke="currentColor" stroke-width="1.7"/><path d="M14.8 14.8l4.6 4.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <input
                    type="text"
                    x-model="langSearch"
                    aria-label="<?= $isFa ? 'جستجوی زبان' : 'Search languages' ?>"
                    placeholder="<?= $isFa ? 'جستجوی زبان...' : 'Search languages…' ?>"
                >
            </label>
            <button type="button" class="lchip" :class="lang === 'all' ? 'lchip on' : 'lchip'" @click="lang = 'all'">
                <span><?= $isFa ? 'همه زبان‌ها' : 'All languages' ?></span>
                <u><?= count($subtitles) ?></u>
            </button>
            <?php foreach ($langCounts as $lc): ?>
                <button type="button" class="lchip" data-lang-search="<?= View::e(mb_strtolower($lc['label'] . ' ' . $lc['code'])) ?>" x-show="langChipVisible($el)" :class="lang === <?= View::e(json_encode($lc['code'])) ?> ? 'lchip on' : 'lchip'" @click="lang = <?= View::e(json_encode($lc['code'])) ?>">
                    <i style="background:<?= $hueColor($lc['code']) ?>"></i>
                    <span><?= View::e($lc['label']) ?></span>
                    <u><?= $lc['count'] ?></u>
                </button>
            <?php endforeach; ?>
            <span class="subcount mono" x-show="hasVisibleLangs"><?= count($subtitles) ?> subtitles</span>
            <span class="subcount mono" x-show="!hasVisibleLangs" x-cloak><?= $isFa ? 'زبانی یافت نشد' : 'No languages found' ?></span>
        </div>

        <div class="tools__r">
            <label class="matchbtn">
                <svg viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M7.5 10V2.6M4.8 5.3L7.5 2.6l2.7 2.7M2.2 10.6v1.8h10.6v-1.8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><?= $isFa ? 'تطبیق با فایل ویدیو' : 'Match my file' ?></span>
                <input type="file" @change="handleFileSelect($event)" accept=".mkv,.mp4,.avi,.mov,.wmv,.m4v" hidden>
            </label>
            <div class="field">
                <svg viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M2 4h11M4 7.5h7M6 11h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                <input
                    type="text"
                    x-model="releaseFilter"
                    placeholder="<?= $isFa ? 'فیلتر نسخه‌ها — 1080p، BluRay، YTS...' : 'Filter releases — 1080p, BluRay, YTS...' ?>"
                >
            </div>

            <?php if ($media->mediaType === 'tv' && $activeSeason === null && !empty($availableSeasons)): ?>
                <div class="frow" role="group" aria-label="<?= $isFa ? 'فیلتر سریع فصل' : 'Quick season filter' ?>">
                    <button type="button" class="ftab" @click="seasonFilter = 'all'" :aria-pressed="String(seasonFilter === 'all')"><?= $isFa ? 'همه فصل‌ها' : 'All seasons' ?></button>
                    <?php foreach ($availableSeasons as $sNum): ?>
                        <button type="button" class="ftab" @click="seasonFilter = '<?= (int) $sNum ?>'" :aria-pressed="String(seasonFilter === '<?= (int) $sNum ?>')"><?= $isFa ? "فصل {$sNum}" : "S" . str_pad((string) $sNum, 2, '0', STR_PAD_LEFT) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($media->mediaType === 'tv' && !empty($episodes)): ?>
                <div class="frow" role="group" aria-label="<?= $isFa ? 'قسمت' : 'Episode' ?>">
                    <button type="button" class="ftab" @click="episodeFilter = 'all'" :aria-pressed="String(episodeFilter === 'all')"><?= $isFa ? 'همه قسمت‌ها' : 'All episodes' ?></button>
                    <?php foreach ($episodes as $epNum): ?>
                        <button type="button" class="ftab" @click="episodeFilter = '<?= (int) $epNum ?>'" :aria-pressed="String(episodeFilter === '<?= (int) $epNum ?>')"><?= $isFa ? "قسمت {$epNum}" : 'Episode ' . str_pad((string) $epNum, 2, '0', STR_PAD_LEFT) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($qualities)): ?>
                <div class="filtergroup" role="group" aria-label="Quality">
                    <span class="lbl">Quality</span>
                    <div class="frow">
                        <button type="button" class="ftab" @click="qualityFilter = 'all'" :aria-pressed="String(qualityFilter === 'all')">Any</button>
                        <?php foreach ($qualities as $qTag): ?>
                            <button type="button" class="ftab" x-show="availableQualities.includes(<?= View::e(json_encode($qTag)) ?>)" @click="qualityFilter = <?= View::e(json_encode($qTag)) ?>" :aria-pressed="String(qualityFilter === <?= View::e(json_encode($qTag)) ?>)"><?= View::e($qTag) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

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

    <!-- Subtitle List (Clean: Release Name + Language + Quality + Translator, NO external source clutter) -->
    <div class="subs" id="subs">
        <?php if (empty($subtitles)): ?>
            <div style="padding:48px 20px;text-align:center;color:var(--muted);display:flex;flex-direction:column;align-items:center;gap:14px">
                <div style="font-size:15px;font-weight:700;color:var(--ink)">
                    <?= $isFa ? 'زیرنویس این عنوان هنوز به آرشیو اضافه نشده است.' : 'Subtitles for this title have not been archived yet.' ?>
                </div>
                <div style="font-size:13px;max-width:420px;line-height:1.7">
                    <?= $isFa ? 'با کلیک روی دکمه زیر، این عنوان در اولویت صف دانلود آرشیو قرار می‌گیرد و به‌زودی با کیفیت تمیز اضافه می‌شود.' : 'Click below to prioritize this title in our archive queue so verified UTF-8 subtitles are added soon.' ?>
                </div>
                <button
                    type="button"
                    @click="requestSubtitle()"
                    :disabled="requesting || requested"
                    style="padding:10px 22px;border-radius:999px;border:none;cursor:pointer;font-weight:700;font-size:13px;background:var(--accent,#2e6f40);color:#fff;opacity:1;transition:opacity .2s"
                    :style="requested ? 'background:#16a34a;cursor:default' : (requesting ? 'opacity:.65;cursor:wait' : '')"
                >
                    <span x-show="!requested && !requesting"><?= $isFa ? 'درخواست زیرنویس این عنوان' : 'Request Subtitle' ?></span>
                    <span x-show="requesting" x-cloak><?= $isFa ? 'در حال ثبت درخواست...' : 'Submitting...' ?></span>
                    <span x-show="requested" x-cloak><?= $isFa ? 'درخواست شما ثبت شد ✓' : 'Request Queued ✓' ?></span>
                </button>
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
                    $voteHash = hash('sha256', strtolower($media->imdbId) . '|' . $sub->downloadUrl);
                    $netVotes = $sub->upvotes - $sub->downvotes;
                ?>
                <div
                    data-lang="<?= View::e($sub->languageCode) ?>"
                    data-quality="<?= View::e($sub->qualityTag) ?>"
                    data-season="<?= ($sub->season !== null && $sub->season > 0) ? (int) $sub->season : '' ?>"
                    data-episode="<?= ($sub->episode !== null && $sub->episode > 0) ? (int) $sub->episode : '' ?>"
                    data-search="<?= View::e($searchIndex) ?>"
                    x-show="matchesRow($el)"
                    :class="(isBestMatch($el.dataset.search || '') ? 'best ' : '') + (downloaded['<?= $rowKey ?>'] ? 'done' : '')"
                    class="srow"
                >
                    <span class="badge" style="background:color-mix(in srgb, <?= $hueColor($sub->languageCode) ?> 16%, var(--surface));border-color:color-mix(in srgb, <?= $hueColor($sub->languageCode) ?> 45%, transparent);color:<?= $hueColor($sub->languageCode) ?>"><?= View::e($codeUpper) ?></span>

                    <div class="srow__main">
                        <span class="srow__n" dir="ltr"><?= View::e($sub->releaseName) ?></span>
                        <div class="srow__m">
                            <span class="srow__lang" style="color:<?= $hueColor($sub->languageCode) ?>"><?= View::e($sub->languageLabel) ?></span>
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
                            <?php if ($netVotes >= 1): ?>
                                <i class="sep"></i>
                                <span class="tagx" style="background:var(--green-wash);color:var(--green-deep);border-color:var(--green);font-weight:600">✓ <?= $isFa ? 'تأیید شده · هماهنگ' : 'Synced' ?></span>
                            <?php endif; ?>
                            <em x-show="isBestMatch($el.closest('[data-search]')?.dataset.search || '')" x-cloak>
                                ✓ <?= $isFa ? 'هماهنگ با فایل شما' : 'Best match' ?>
                            </em>
                        </div>
                    </div>

                    <div class="srow__a">
                        <div class="vgrp" aria-label="<?= $isFa ? 'رأی هماهنگی زیرنویس' : 'Sync feedback' ?>">
                            <button
                                type="button"
                                class="vbtn"
                                :class="voted['<?= $voteHash ?>'] === 'up' ? 'on-up' : ''"
                                :disabled="Boolean(voted['<?= $voteHash ?>'])"
                                @click="castVote('<?= $voteHash ?>', 'up')"
                                title="<?= $isFa ? 'هماهنگ بود' : 'In sync' ?>"
                            >👍 <span x-text="voteCounts['<?= $voteHash ?>']?.up ?? <?= (int) $sub->upvotes ?>"><?= (int) $sub->upvotes ?></span></button>
                            <button
                                type="button"
                                class="vbtn"
                                :class="voted['<?= $voteHash ?>'] === 'down' ? 'on-dn' : ''"
                                :disabled="Boolean(voted['<?= $voteHash ?>'])"
                                @click="castVote('<?= $voteHash ?>', 'down')"
                                title="<?= $isFa ? 'ناهماهنگ بود' : 'Out of sync' ?>"
                            >👎 <span x-text="voteCounts['<?= $voteHash ?>']?.down ?? <?= (int) $sub->downvotes ?>"><?= (int) $sub->downvotes ?></span></button>
                        </div>
                        <a
                            href="<?= View::e($dlHref) ?>"
                            @click="markDownloaded('<?= $rowKey ?>', <?= View::e(json_encode($sub->releaseName)) ?>)"
                            rel="nofollow noopener"
                            class="btn btn--go dl"
                        >
                            <span x-text="downloaded['<?= $rowKey ?>'] ? '<?= $isFa ? 'ذخیره شد' : 'Saved' ?>' : '<?= $isFa ? 'دانلود' : 'Download' ?>'"><?= $isFa ? 'دانلود' : 'Download' ?></span>
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

    <!-- Compact guide + crawlable language links -->
    <?php if (!empty($subtitles)): ?>
        <div class="guide">
            <span class="guide__txt">
                <strong><?= count($subtitles) ?></strong> verified UTF-8 <strong>.srt</strong> files for <span dir="ltr"><?= View::e($media->title) ?></span><?= $media->year !== '' ? ' (' . View::e($media->year) . ')' : '' ?>.
            </span>
            <?php if (count($langCounts) > 1): ?>
                <span class="guide__langs">
                    <?php foreach (array_slice($langCounts, 0, 10) as $lc): ?>
                        <?php
                            $resL = LanguageMap::resolve($lc['code']);
                            $sL   = LanguageMap::fromUrlSlug($resL['slug']) !== null ? $resL['slug'] : null;
                        ?>
                        <?php if ($sL !== null): ?>
                            <a href="<?= View::e($baseMediaUrl . '/' . $sL) ?>" class="chip" style="text-decoration:none"><?= View::e($lc['label']) ?> (<?= (int) $lc['count'] ?>)</a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </div>
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
                                <img src="<?= View::e($sim->posterUrl) ?>" alt="<?= View::e($sim->title) ?>" width="400" height="600" loading="lazy" referrerpolicy="no-referrer" data-imdb="<?= View::e($sim->imdbId) ?>">
                                <?php if ($sim->voteAverage > 0): ?><span class="pv__rate">★ <?= number_format($sim->voteAverage, 1) ?></span><?php endif; ?>
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

    <!-- Toast Notification -->
    <div x-show="toastVisible" x-cloak class="toast" role="status">
        <span x-text="toastMessage"></span>
    </div>

</div>

<script nonce="<?= CSP_NONCE ?>">
    function subtitlePage() {
        return {
            lang: <?= json_encode($initialLang) ?>,
            langSearch: '',
            releaseFilter: '',
            qualityFilter: 'all',
            seasonFilter: 'all',
            episodeFilter: 'all',
            matchedRelease: '',
            matchedTokens: [],
            downloaded: {},
            voted: JSON.parse(localStorage.getItem('sb_votes_v1') || '{}'),
            voteCounts: {},
            requesting: false,
            requested: !!JSON.parse(localStorage.getItem('sb_reqs_v1') || '{}')[<?= json_encode($media->imdbId) ?>],
            toastVisible: false,
            toastMessage: '',
            init() {
                this.$watch('lang', () => this.ensureQualityFilter());
                this.$watch('seasonFilter', () => this.ensureQualityFilter());
                this.$watch('episodeFilter', () => this.ensureQualityFilter());
                this.$watch('releaseFilter', () => this.ensureQualityFilter());
            },
            langChipVisible(el) {
                const q = this.langSearch.trim().toLowerCase();
                return q === '' || (el.dataset.langSearch || '').includes(q);
            },
            get hasVisibleLangs() {
                const q = this.langSearch.trim().toLowerCase();
                if (q === '') return true;
                return Array.from(document.querySelectorAll('.pills .lchip[data-lang-search]'))
                    .some(el => (el.dataset.langSearch || '').includes(q));
            },
            get availableQualities() {
                const rows = document.querySelectorAll('.srow[data-quality]');
                const needle = this.releaseFilter.toLowerCase().trim();
                const set = new Set();
                rows.forEach(el => {
                    const q = el.dataset.quality || '';
                    if (q === '') return;
                    if (this.lang !== 'all' && this.lang !== el.dataset.lang) return;
                    if (this.seasonFilter !== 'all' && el.dataset.season !== '' && this.seasonFilter !== el.dataset.season) return;
                    if (this.episodeFilter !== 'all' && this.episodeFilter !== el.dataset.episode) return;
                    if (!(el.dataset.search || '').includes(needle)) return;
                    set.add(q);
                });
                return Array.from(set);
            },
            ensureQualityFilter() {
                if (this.qualityFilter !== 'all' && !this.availableQualities.includes(this.qualityFilter)) {
                    this.qualityFilter = 'all';
                }
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
                const rowLang   = el.dataset.lang || '';
                const rowQual   = el.dataset.quality || '';
                const rowSeason = el.dataset.season || '';
                const rowEp     = el.dataset.episode || '';
                const rowSearch = el.dataset.search || '';
                const langOk   = this.lang === 'all' || this.lang === rowLang;
                const qualOk   = this.qualityFilter === 'all' || this.qualityFilter === rowQual;
                const seasonOk = this.seasonFilter === 'all' || this.seasonFilter === rowSeason;
                const epOk     = this.episodeFilter === 'all' || this.episodeFilter === rowEp;
                const textOk   = rowSearch.includes(this.releaseFilter.toLowerCase().trim());
                return langOk && qualOk && seasonOk && epOk && textOk;
            },
            async castVote(hash, dir) {
                if (this.voted[hash]) return;
                this.voted[hash] = dir;
                localStorage.setItem('sb_votes_v1', JSON.stringify(this.voted));
                try {
                    const res = await fetch('/api/sub-vote', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ hash: hash, imdb: <?= json_encode($media->imdbId) ?>, vote: dir })
                    });
                    const data = await res.json();
                    if (data && data.ok) {
                        this.voteCounts[hash] = { up: data.up, down: data.down };
                    }
                } catch (e) {}
            },
            markDownloaded(key, name) {
                this.downloaded[key] = true;
                this.triggerToast(name);
                setTimeout(() => { this.downloaded[key] = false; }, 2400);
            },
            async requestSubtitle() {
                if (this.requesting || this.requested) return;
                this.requesting = true;
                try {
                    const res = await fetch('/api/request-subtitle', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ imdb: <?= json_encode($media->imdbId) ?> })
                    });
                    const data = await res.json();
                    if (data && data.ok) {
                        this.requested = true;
                        const map = JSON.parse(localStorage.getItem('sb_reqs_v1') || '{}');
                        map[<?= json_encode($media->imdbId) ?>] = Date.now();
                        localStorage.setItem('sb_reqs_v1', JSON.stringify(map));
                        this.toastMessage = <?= json_encode($isFa ? 'درخواست زیرنویس ثبت شد ✓' : 'Subtitle request queued ✓') ?>;
                        this.toastVisible = true;
                        setTimeout(() => { this.toastVisible = false; }, 3200);
                    }
                } catch (e) {}
                this.requesting = false;
            },
            triggerToast(name) {
                this.toastMessage = '<?= $isFa ? 'ذخیره شد · ' : 'Saved · ' ?>' + name;
                this.toastVisible = true;
                setTimeout(() => { this.toastVisible = false; }, 2800);
            }
        };
    }
</script>

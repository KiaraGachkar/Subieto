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
                <div class="head__x frow" role="group" aria-label="<?= $isFa ? 'فصل‌ها' : 'Seasons' ?>">
                    <a href="<?= View::e($baseMediaUrl) ?>" class="ftab" aria-pressed="<?= $activeSeason === null ? 'true' : 'false' ?>">
                        <?= $isFa ? 'همه فصل‌ها' : 'All seasons' ?>
                    </a>
                    <?php foreach ($availableSeasons as $sNum): ?>
                        <a href="<?= View::e($baseMediaUrl . '/season-' . $sNum) ?>" class="ftab" aria-pressed="<?= $activeSeason === $sNum ? 'true' : 'false' ?>">
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

    <!-- Language Pills + Release Search + Quality Filter + Match My Video File -->
    <div class="tools">
        <div class="pills">
            <button type="button" class="lchip" :class="lang === 'all' ? 'lchip on' : 'lchip'" @click="lang = 'all'">
                <span>All languages</span>
                <u><?= count($subtitles) ?></u>
            </button>
            <?php foreach ($langCounts as $lc): ?>
                <button type="button" class="lchip" :class="lang === <?= View::e(json_encode($lc['code'])) ?> ? 'lchip on' : 'lchip'" @click="lang = <?= View::e(json_encode($lc['code'])) ?>">
                    <i style="background:<?= $hueColor($lc['code']) ?>"></i>
                    <span><?= View::e($lc['label']) ?></span>
                    <u><?= $lc['count'] ?></u>
                </button>
            <?php endforeach; ?>
            <span class="subcount mono"><?= count($subtitles) ?> subtitles</span>
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
                            <em x-show="isBestMatch($el.closest('[data-search]')?.dataset.search || '')" x-cloak>
                                ✓ <?= $isFa ? 'هماهنگ با فایل شما' : 'Best match' ?>
                            </em>
                        </div>
                    </div>

                    <div class="srow__a">
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
                                <img src="<?= View::e($sim->posterUrl) ?>" alt="<?= View::e($sim->title) ?>" width="400" height="600" loading="lazy" onerror="this.style.display='none'">
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
            releaseFilter: '',
            qualityFilter: 'all',
            episodeFilter: 'all',
            matchedRelease: '',
            matchedTokens: [],
            downloaded: {},
            toastVisible: false,
            toastMessage: '',
            init() {
                this.$watch('lang', () => this.ensureQualityFilter());
                this.$watch('episodeFilter', () => this.ensureQualityFilter());
                this.$watch('releaseFilter', () => this.ensureQualityFilter());
            },
            get availableQualities() {
                const rows = document.querySelectorAll('.srow[data-quality]');
                const needle = this.releaseFilter.toLowerCase().trim();
                const set = new Set();
                rows.forEach(el => {
                    const q = el.dataset.quality || '';
                    if (q === '') return;
                    if (this.lang !== 'all' && this.lang !== el.dataset.lang) return;
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
            triggerToast(name) {
                this.toastMessage = '<?= $isFa ? 'ذخیره شد · ' : 'Saved · ' ?>' + name;
                this.toastVisible = true;
                setTimeout(() => { this.toastVisible = false; }, 2800);
            }
        };
    }
</script>

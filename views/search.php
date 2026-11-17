<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\View;
/** @var string $query */
/** @var \App\DTOs\MediaItem[] $results */
/** @var string|null $locale */
/** @var \App\Core\SettingsManager $settings */

$isFa = (($locale ?? 'en') === 'fa');
$searchTopAd = $settings->getAd('search_top_banner');
$hueFromId = static fn(string $id): int => (abs(crc32($id)) % 360);
?>
<div class="col" style="padding-top:32px;padding-bottom:72px">

    <?php if ($searchTopAd !== ''): ?>
        <div style="margin-top:26px">
            <div data-ad-slot="search_top_banner" class="ad-card">
                <?= $searchTopAd ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="browse" style="padding-top:34px">
        <div class="browse__h">
            <h2>
                <?= count($results) ?> <?= $isFa ? 'نتیجه برای ' : 'results for ' ?>&ldquo;<?= View::e($query) ?>&rdquo;
            </h2>
        </div>

        <?php if (empty($results)): ?>
            <div style="padding:56px 16px;text-align:center;border-top:1px solid var(--line);color:var(--muted)">
                <?= $isFa ? 'موردی یافت نشد. لطفاً نام انگلیسی فیلم یا کد IMDb را جستجو کنید.' : 'No matching titles found. Try the English title or an IMDb ID.' ?>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($results as $item): ?>
                    <?php $itemHue = $hueFromId($item->imdbId); ?>
                    <a href="/subtitles/<?= View::e($item->imdbId) ?>/<?= View::e($item->slug) ?>" class="card">
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
        <?php endif; ?>
    </div>

</div>

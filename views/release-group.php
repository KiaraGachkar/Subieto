<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\View;
/** @var string $groupSlug */
/** @var string $groupName */
/** @var string $groupDesc */
/** @var array<string, array{name: string, desc: string}> $groupCatalog */
/** @var array<int, array<string, mixed>> $indexedRows */
/** @var \App\DTOs\MediaItem[] $trending */
/** @var string|null $locale */

$isFa = (($locale ?? 'en') === 'fa');
$hueFromId = static fn(string $id): int => (abs(crc32($id)) % 360);
?>
<div class="col" style="padding-top:28px;padding-bottom:72px">

    <div class="crumb" style="padding-top:0">
        <a href="/">
            <svg viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M8 2.5L4 6.5l4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span><?= $isFa ? 'خانه' : 'Home' ?></span>
        </a>
    </div>

    <section style="margin-top:20px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r-card);padding:26px">
        <h1 style="font-family:var(--serif);font-size:clamp(1.6rem,3.2vw,2.3rem);font-weight:500;margin:0">
            <?= $isFa ? 'زیرنویس‌های هماهنگ با نسخه ' . View::e($groupName) : View::e($groupName) . ' Synchronized Subtitles' ?>
        </h1>
        <p style="margin:10px 0 0;color:var(--soft);font-size:14px;max-width:62ch"><?= View::e($groupDesc) ?></p>

        <div class="frow" role="group" aria-label="<?= $isFa ? 'نسخه‌ها' : 'Release groups' ?>" style="margin-top:18px">
            <?php foreach ($groupCatalog as $gKey => $gInfo): ?>
                <a href="/subtitles/release/<?= View::e($gKey) ?>" class="ftab" aria-pressed="<?= $gKey === $groupSlug ? 'true' : 'false' ?>">
                    <span class="mono"><?= View::e($gInfo['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (!empty($trending)): ?>
        <section class="browse" style="padding-bottom:24px">
            <div class="browse__h">
                <h2><?= $isFa ? 'عناوین محبوب' : 'Popular Titles' ?></h2>
            </div>
            <div class="grid">
                <?php foreach ($trending as $movie): ?>
                    <?php $mHue = $hueFromId($movie->imdbId); ?>
                    <a href="/subtitles/<?= View::e($movie->imdbId) ?>/<?= View::e($movie->slug) ?>" class="card">
                        <span class="card__p">
                            <span class="pv" style="--h:<?= $mHue ?>">
                                <span class="pv__bg"></span>
                                <span class="pv__fb"><span class="pv__fb-y"><?= View::e($movie->year) ?></span><span class="pv__fb-t" dir="ltr"><?= View::e($movie->title) ?></span></span>
                                <img src="<?= View::e($movie->posterUrl) ?>" alt="<?= View::e($movie->title) ?>" width="400" height="600" loading="lazy" referrerpolicy="no-referrer" data-imdb="<?= View::e($movie->imdbId) ?>">
                            </span>
                        </span>
                        <span class="card__body">
                            <span class="card__t" dir="ltr"><?= View::e($movie->title) ?></span>
                            <span class="card__m"><span class="mono"><?= View::e($movie->year) ?></span></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

</div>

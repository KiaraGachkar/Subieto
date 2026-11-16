<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\Session;
use App\Core\View;
/** @var string|null $error */
/** @var int $lockSeconds */
/** @var string|null $adminLocale */

$isFa = (($adminLocale ?? 'fa') === 'fa');
?>
<!DOCTYPE html>
<html lang="<?= $isFa ? 'fa' : 'en' ?>" dir="<?= $isFa ? 'rtl' : 'ltr' ?>" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isFa ? 'ورود به پنل مدیریت | Subieto' : 'Admin Sign In | Subieto' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400..500,0..1,0" rel="stylesheet">
    <?php if ($isFa): ?>
        <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <?php endif; ?>
    <style>
        html { -webkit-font-smoothing: antialiased; }
        body { font-family: <?= $isFa ? "'Vazirmatn', " : "" ?>'Nunito', system-ui, sans-serif; background-color: #faf7f2; color: #2b302d; }
        .font-display { font-family: <?= $isFa ? "'Vazirmatn', " : "" ?>'Outfit', 'Nunito', sans-serif; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-white border border-[#efeae1] rounded-3xl p-8 w-full max-w-sm shadow-[0_8px_30px_rgba(43,48,45,0.06)] relative">
        <a href="/admin/login?lang=<?= $isFa ? 'en' : 'fa' ?>" class="absolute top-5 <?= $isFa ? 'left-5' : 'right-5' ?> px-2.5 py-1 rounded-full bg-[#f5f1ea] hover:bg-[#efeae1] text-[11px] font-bold text-[#6c655a]">
            <?= $isFa ? 'EN' : 'فا' ?>
        </a>

        <div class="flex flex-col items-center text-center mb-6">
            <div class="w-12 h-12 rounded-2xl bg-[#d6efe0] text-[#2e6f40] flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-[24px]">shield_lock</span>
            </div>
            <h1 class="font-display text-xl font-bold text-[#2b302d]"><?= $isFa ? 'پنل مدیریت Subieto' : 'Subieto Admin' ?></h1>
            <p class="text-xs text-[#6c655a] mt-1"><?= $isFa ? 'برای مدیریت منابع، تبلیغات و آمار وارد شوید' : 'Sign in to manage sources, ads, and analytics' ?></p>
        </div>

        <?php if ($lockSeconds > 0): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold rounded-2xl p-3.5 mb-4">
                <?= $isFa ? 'دسترسی موقتاً مسدود است. لطفاً ' . ceil($lockSeconds / 60) . ' دقیقه دیگر تلاش کنید.' : 'Access temporarily locked. Try again in ' . ceil($lockSeconds / 60) . ' minute(s).' ?>
            </div>
        <?php elseif ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold rounded-2xl p-3.5 mb-4">
                <?= View::e($error) ?>
            </div>
        <?php endif; ?>

        <form action="/admin/login" method="POST" class="space-y-4">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <div>
                <label class="block text-xs font-bold text-[#6c655a] mb-1.5"><?= $isFa ? 'رمز عبور مدیریت' : 'Administrator Password' ?></label>
                <input
                    type="password"
                    name="password"
                    dir="ltr"
                    required
                    <?= $lockSeconds > 0 ? 'disabled' : 'autofocus' ?>
                    placeholder="••••••••"
                    class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-3 text-sm text-[#2b302d] focus:outline-none focus:border-[#2e6f40] focus:bg-white transition-colors"
                >
            </div>
            <button
                type="submit"
                <?= $lockSeconds > 0 ? 'disabled' : '' ?>
                class="w-full bg-[#2e6f40] hover:bg-[#245832] disabled:bg-slate-200 disabled:text-slate-400 text-white font-bold py-3 rounded-full text-sm transition shadow-sm"
            >
                <?= $isFa ? 'ورود به پنل' : 'Sign In' ?>
            </button>
        </form>

        <div class="text-center mt-6">
            <a href="/" class="inline-flex items-center gap-1 text-xs font-semibold text-[#6c655a] hover:text-[#2e6f40] transition-colors">
                <span class="material-symbols-outlined text-[15px]"><?= $isFa ? 'arrow_forward' : 'arrow_back' ?></span>
                <span><?= $isFa ? 'بازگشت به سایت' : 'Back to Subieto' ?></span>
            </a>
        </div>
    </div>
</body>
</html>

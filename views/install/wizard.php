<?php
defined('APP_SECURE_BOOT') or exit;
use App\Core\Session;
use App\Core\View;
/** @var array<int, array{label: string, ok: bool, detail: string}> $checks */
/** @var bool $allPassed */
/** @var string|null $error */
/** @var array<string, string> $old */
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Wizard | Subieto</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400..500,0..1,0" rel="stylesheet">
    <style>
        html { -webkit-font-smoothing: antialiased; }
        body { font-family: 'Nunito', system-ui, sans-serif; background-color: #faf7f2; color: #2b302d; }
        .font-display { font-family: 'Outfit', 'Nunito', sans-serif; letter-spacing: -0.015em; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="min-h-screen py-10 px-4">
    <div class="max-w-2xl mx-auto space-y-6">

        <!-- Header -->
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-[#d6efe0] text-[#2e6f40] mb-1">
                <span class="material-symbols-outlined text-[24px]">rocket_launch</span>
            </div>
            <h1 class="font-display text-2xl md:text-3xl font-bold text-[#2b302d]">Subieto Installation Wizard</h1>
            <p class="text-xs text-[#6c655a]">Automated MySQL database setup, secure HMAC keys, and environment configuration</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold rounded-2xl p-4 shadow-sm">
                <?= View::e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Requirements -->
        <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-4">
            <h2 class="font-display text-sm font-bold text-[#2b302d] border-b border-[#efeae1] pb-3 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-[#f5f1ea] text-[#2e6f40] text-xs flex items-center justify-center font-bold">1</span>
                <span>Server &amp; PHP Requirements</span>
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <?php foreach ($checks as $check): ?>
                    <div class="flex items-center justify-between bg-[#faf7f2] border <?= $check['ok'] ? 'border-emerald-200' : 'border-rose-200' ?> rounded-2xl px-4 py-3">
                        <div>
                            <div class="font-bold text-[#2b302d]"><?= View::e($check['label']) ?></div>
                            <div class="text-[11px] text-[#6c655a] mt-0.5 font-mono"><?= View::e($check['detail']) ?></div>
                        </div>
                        <span class="text-base font-bold <?= $check['ok'] ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= $check['ok'] ? '✓' : '✕' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form action="/install/run" method="POST" class="space-y-6">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

            <!-- Step 2: Database -->
            <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                <h2 class="font-display text-sm font-bold text-[#2b302d] border-b border-[#efeae1] pb-3 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-[#f5f1ea] text-[#2e6f40] text-xs flex items-center justify-center font-bold">2</span>
                    <span>MySQL Database Configuration</span>
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <div class="sm:col-span-2">
                        <label class="block font-bold text-[#6c655a] mb-1">Database Host</label>
                        <input type="text" name="db_host" dir="ltr" required value="<?= View::e($old['db_host'] ?? '127.0.0.1') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1">Port</label>
                        <input type="number" name="db_port" dir="ltr" required value="<?= View::e($old['db_port'] ?? '3306') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1">Database Name</label>
                        <input type="text" name="db_name" dir="ltr" required placeholder="subieto_db" value="<?= View::e($old['db_name'] ?? '') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1">Database User</label>
                        <input type="text" name="db_user" dir="ltr" required placeholder="root" value="<?= View::e($old['db_user'] ?? '') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1">Database Password</label>
                        <input type="password" name="db_pass" dir="ltr" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-[#6c655a] mb-1">Table Prefix</label>
                    <input type="text" name="db_prefix" dir="ltr" value="<?= View::e($old['db_prefix'] ?? 'sh_') ?>" class="w-full sm:w-1/3 bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2 text-xs font-mono">
                </div>
            </div>

            <!-- Step 3: Site & Admin -->
            <div class="bg-white border border-[#efeae1] rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                <h2 class="font-display text-sm font-bold text-[#2b302d] border-b border-[#efeae1] pb-3 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-[#f5f1ea] text-[#2e6f40] text-xs flex items-center justify-center font-bold">3</span>
                    <span>Site Details &amp; Admin Credentials</span>
                </h2>

                <div>
                    <label class="block font-bold text-[#6c655a] mb-1">Canonical Base URL (with https://)</label>
                    <input type="url" name="site_base_url" dir="ltr" required value="<?= View::e($old['site_base_url'] ?? \App\Services\SeoHelper::getBaseUrl()) ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono text-xs">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1">Site Title / Brand</label>
                        <input type="text" name="site_title" required value="<?= View::e($old['site_title'] ?? 'Subieto') ?>" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-bold">
                    </div>
                    <div>
                        <label class="block font-bold text-[#6c655a] mb-1">Admin Password (Min 8 chars)</label>
                        <input type="password" name="admin_password" dir="ltr" required minlength="8" placeholder="••••••••" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-4 py-2.5 font-mono">
                    </div>
                </div>

                <div class="pt-2 space-y-2">
                    <p class="text-[11px] text-[#6c655a]">Subieto operates 100% free via OpenSubtitles, Podnapisi, YIFY, Subf2m, and Cinemeta without mandatory API keys. Optional keys below:</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1">TMDB API Key</label>
                            <input type="text" name="tmdb_api_key" dir="ltr" value="<?= View::e($old['tmdb_api_key'] ?? '') ?>" placeholder="Optional" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3 py-2 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1">SubDL API Key</label>
                            <input type="text" name="subdl_api_key" dir="ltr" value="<?= View::e($old['subdl_api_key'] ?? '') ?>" placeholder="Optional" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3 py-2 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-[#6c655a] mb-1">OpenSubtitles API Key</label>
                            <input type="text" name="opensubtitles_api_key" dir="ltr" value="<?= View::e($old['opensubtitles_api_key'] ?? '') ?>" placeholder="Optional" class="w-full bg-[#f5f1ea] border border-[#d8d3c9] rounded-2xl px-3 py-2 font-mono">
                        </div>
                    </div>
                </div>
            </div>

            <button
                type="submit"
                <?= !$allPassed ? 'disabled' : '' ?>
                class="w-full <?= $allPassed ? 'bg-[#2e6f40] hover:bg-[#245832] text-white cursor-pointer shadow-sm' : 'bg-slate-200 text-slate-400 cursor-not-allowed' ?> font-bold py-4 rounded-full text-sm transition active:scale-95"
            >
                <?= $allPassed ? 'Run Automated Installation &amp; Launch Subieto' : 'Please resolve server requirements above' ?>
            </button>
        </form>
    </div>
</body>
</html>

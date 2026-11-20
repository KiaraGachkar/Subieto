<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\RateLimiter;
use App\Providers\TmdbMetadataProvider;
use App\Services\SeoHelper;
use PDO;
use Throwable;

defined('APP_SECURE_BOOT') or exit;

final class TelegramController extends BaseController
{
    public function webhook(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('telegram_wh', 60, 60)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate_limited']);
            return;
        }

        $botToken = trim((string) $this->settings->get('telegram.bot_token', ''));
        $secret   = trim((string) $this->settings->get('telegram.webhook_secret', ''));

        if ($botToken === '') {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'bot_not_configured']);
            return;
        }

        if ($secret !== '') {
            $hdrSecret = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? $_GET['secret'] ?? ''));
            if ($hdrSecret === '' || !hash_equals($secret, $hdrSecret)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'forbidden']);
                return;
            }
        }

        $raw    = file_get_contents('php://input');
        $update = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($update) || empty($update['message']['chat']['id'])) {
            echo json_encode(['ok' => true]);
            return;
        }

        $chatId = (string) $update['message']['chat']['id'];
        $text   = trim((string) ($update['message']['text'] ?? ''));
        $base   = SeoHelper::getBaseUrl();

        if ($text === '' || $text === '/start' || $text === '/help') {
            $welcome = "سلام! به ربات رسمی زیرنویس Subieto خوش آمدید 🎬

"
                     . "نام انگلیسی فیلم یا سریال (یا کد IMDb مثل tt1375666) را بفرستید تا لینک مستقیم زیرنویس‌ها برایتان ارسال شود.";
            $this->sendMessage($botToken, $chatId, $welcome);
            echo json_encode(['ok' => true]);
            return;
        }

        $query = mb_substr($text, 0, 100);
        $results = $this->searchArchive($query);

        // Fallback to live Cinemeta/TMDB if not yet in local archive
        if ($results === []) {
            $tmdb = new TmdbMetadataProvider($this->http, $this->settings, $this->cache);
            foreach ($tmdb->searchMulti($query, 5) as $item) {
                $this->metrics->indexMediaForSeo($item);
                $results[] = [
                    'imdb_id'    => $item->imdbId,
                    'slug'       => $item->slug,
                    'title'      => $item->title,
                    'year'       => $item->year,
                    'media_type' => $item->mediaType,
                    'sub_count'  => 0,
                ];
            }
        }

        if ($results === []) {
            $this->sendMessage($botToken, $chatId, "متأسفانه عنوانی برای «{$query}» پیدا نشد. لطفاً نام انگلیسی فیلم یا کد IMDb را بررسی کنید.");
            echo json_encode(['ok' => true]);
            return;
        }

        $buttons = [];
        foreach (array_slice($results, 0, 6) as $row) {
            $typeIcon = ($row['media_type'] ?? '') === 'tv' ? '📺' : '🎬';
            $yr       = ($row['year'] ?? '') !== '' ? " ({$row['year']})" : '';
            $cnt      = (int) ($row['sub_count'] ?? 0);
            $cntTxt   = $cnt > 0 ? " · {$cnt} زیرنویس" : '';
            $url      = "{$base}/subtitles/{$row['imdb_id']}/{$row['slug']}";
            $buttons[] = [[
                'text' => "{$typeIcon} {$row['title']}{$yr}{$cntTxt}",
                'url'  => $url,
            ]];
        }

        $replyText = "نتایج یافت‌شده برای «{$query}»:
روی عنوان مورد نظر بزنید تا مستقیم وارد صفحه دانلود زیرنویس شوید:";
        $this->sendMessage($botToken, $chatId, $replyText, ['inline_keyboard' => $buttons]);
        echo json_encode(['ok' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchArchive(string $query): array
    {
        try {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
            $stmt = $this->db->prepare(
                "SELECT m.`imdb_id`, m.`slug`, m.`title`, m.`year`, m.`media_type`,
                        (SELECT COUNT(*) FROM `{$this->prefix}subtitles` s WHERE s.`imdb_id` = m.`imdb_id` AND s.`is_active` = 1 AND s.`download_url` LIKE 'localzip://%') AS `sub_count`
                 FROM `{$this->prefix}indexed_media` m
                 WHERE m.`title` LIKE :q1 OR m.`persian_title` LIKE :q2 OR m.`imdb_id` = :exact
                 ORDER BY `sub_count` DESC, m.`updated_at` DESC
                 LIMIT 6"
            );
            $stmt->execute([
                ':q1'    => $like,
                ':q2'    => $like,
                ':exact' => strtolower($query),
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    private function sendMessage(string $botToken, string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $this->http->postJson("https://api.telegram.org/bot{$botToken}/sendMessage", $payload, [], 6);
    }
}

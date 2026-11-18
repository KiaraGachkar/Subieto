<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SubtitleRepository;

defined('APP_SECURE_BOOT') or exit;

final class CronController extends BaseController
{
    public function sync(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $expectedToken = $this->ensureCronToken();
        $providedToken = trim((string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            return;
        }

        @set_time_limit(90);
        $limit = max(1, min(10, (int) ($_GET['limit'] ?? 5)));
        $repo = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');

        $specificImdb = strtolower(trim((string) ($_GET['imdb'] ?? '')));
        $queue = ($specificImdb !== '' && preg_match('/^tt\d{5,10}$/', $specificImdb))
            ? [$specificImdb]
            : $repo->getNextSyncQueue($limit);

        $results = [];
        foreach ($queue as $imdbId) {
            $results[] = $repo->syncSingleTitle($imdbId, $this->http, $this->settings, $this->cache);
        }

        echo json_encode([
            'ok'      => true,
            'synced'  => count($results),
            'results' => $results,
            'stats'   => $repo->getArchiveStats(),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function ensureCronToken(): string
    {
        $token = trim((string) $this->settings->get('cron.secret_token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(20));
            $this->settings->updateMany(['cron.secret_token' => $token]);
        }
        return $token;
    }
}

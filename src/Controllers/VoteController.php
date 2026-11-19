<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\RateLimiter;
use App\Services\SubtitleRepository;

defined('APP_SECURE_BOOT') or exit;

final class VoteController extends BaseController
{
    public function vote(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('sub_vote', 30, 60)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate_limited']);
            return;
        }

        $originHost = strtolower((string) (parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST) ?? ''));
        $serverHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if ($originHost !== '' && $originHost !== $serverHost) {
            http_response_code(403);
            echo json_encode(['ok' => false]);
            return;
        }

        $raw  = file_get_contents('php://input');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = $_POST;
        }

        $hash   = trim((string) ($data['hash'] ?? ''));
        $imdbId = strtolower(trim((string) ($data['imdb'] ?? '')));
        $vote   = strtolower(trim((string) ($data['vote'] ?? '')));

        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !preg_match('/^tt\d{5,10}$/', $imdbId) || !in_array($vote, ['up', 'down'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_params']);
            return;
        }

        // Prevent duplicate voting on the same subtitle from same IP within 1 hour
        if (!$limiter->allow('sub_vote_' . substr($hash, 0, 16), 1, 3600)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'already_voted']);
            return;
        }

        $repo = new SubtitleRepository($this->db, $this->prefix, $this->basePath . '/storage');
        $counts = $repo->recordVote($hash, $imdbId, $vote);

        // Clear static page cache so updated vote rankings appear soon
        if ($this->cache->lock('vote_page_clear_throttle', 120)) {
            $this->pageCache?->clearAll();
        }

        echo json_encode([
            'ok'   => true,
            'up'   => $counts['up'],
            'down' => $counts['down'],
        ]);
    }
}

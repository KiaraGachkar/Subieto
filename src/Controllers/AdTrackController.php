<?php

declare(strict_types=1);

namespace App\Controllers;

defined('APP_SECURE_BOOT') or exit;

use App\Core\RateLimiter;

final class AdTrackController extends BaseController
{
    public function click(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $limiter = new RateLimiter($this->db, $this->prefix);
        if (!$limiter->allow('ad_click', 30, 60)) {
            http_response_code(429);
            echo json_encode(['ok' => false]);
            return;
        }

        $originHost = strtolower((string) (parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST) ?? ''));
        $serverHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if ($originHost !== '' && $originHost !== $serverHost) {
            http_response_code(403);
            echo json_encode(['ok' => false]);
            return;
        }

        $raw = file_get_contents('php://input');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $slot = is_array($data) ? (string) ($data['slot'] ?? '') : (string) ($_POST['slot'] ?? '');

        $allowedSlots = [
            'header_banner',
            'home_below_search',
            'home_between',
            'home_footer_banner',
            'search_top_banner',
            'movie_sidebar',
            'movie_above_table',
            'movie_in_feed',
            'movie_below_table',
            'sticky_footer_mobile',
        ];

        if (!in_array($slot, $allowedSlots, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false]);
            return;
        }

        $this->metrics->recordAdClick($slot);
        echo json_encode(['ok' => true]);
    }

    public function slots(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        $allowedSlots = [
            'header_banner',
            'home_below_search',
            'home_between',
            'home_footer_banner',
            'search_top_banner',
            'movie_sidebar',
            'movie_above_table',
            'movie_in_feed',
            'movie_below_table',
            'sticky_footer_mobile',
        ];

        $out = [];
        foreach ($allowedSlots as $slot) {
            $out[$slot] = $this->settings->getAd($slot);
        }

        echo json_encode(['slots' => $out], JSON_UNESCAPED_UNICODE);
    }
}

<?php
// Built-in PHP CLI server router for desktop testing:
// Run: php -S 127.0.0.1:8080 -t public public/router.php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // Serve static assets (.css, .js, images) directly
}

require __DIR__ . '/index.php';

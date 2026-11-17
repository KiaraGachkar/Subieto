<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

final class HttpClient
{
    private const MAX_RESPONSE_BYTES = 5242880; // 5 MB memory cap
    private ?\CurlHandle $reusableHandle = null;

    public function __destruct()
    {
        if ($this->reusableHandle !== null) {
            curl_close($this->reusableHandle);
            $this->reusableHandle = null;
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<int, mixed>     $curlExtra
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 12,
        bool $followRedirects = true,
        array $curlExtra = []
    ): array {
        $currentUrl     = $url;
        $currentHeaders = $headers;
        $currentMethod  = strtoupper($method);
        $currentBody    = $body;
        $maxHops        = $followRedirects ? 3 : 0;

        for ($hop = 0; ; $hop++) {
            $res = $this->singleRequest($currentMethod, $currentUrl, $currentHeaders, $currentBody, $timeout, $curlExtra);
            if ($hop >= $maxHops
                || !in_array($res['status'], [301, 302, 303, 307, 308], true)
                || empty($res['headers']['location'])
            ) {
                return $res;
            }

            $next = self::absolutize($currentUrl, (string) $res['headers']['location']);
            if (strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https') {
                return $res; // Never downgrade HTTPS -> HTTP
            }

            if (strtolower((string) parse_url($next, PHP_URL_HOST)) !== strtolower((string) parse_url($currentUrl, PHP_URL_HOST))) {
                $currentHeaders = []; // Strip sensitive headers cross-origin
                $curlExtra = [];
            }

            $currentUrl = $next;
            if ($res['status'] !== 307 && $res['status'] !== 308) {
                $currentMethod = 'GET';
                $currentBody   = null;
            }
        }
    }

    public static function absolutize(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p      = parse_url($base);
        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host'] ?? '';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $scheme . '://' . $host . $dir . '/' . $location;
    }

    /**
     * @param array<string, string> $headers
     * @param array<int, mixed>     $curlExtra
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function singleRequest(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        array $curlExtra = [],
        bool $allowRetry = true
    ): array {
        if ($this->reusableHandle === null) {
            $init = curl_init();
            if ($init === false) {
                return ['status' => 0, 'body' => '', 'headers' => []];
            }
            $this->reusableHandle = $init;
        } else {
            curl_reset($this->reusableHandle);
        }
        $ch = $this->reusableHandle;

        $formattedHeaders = [];
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = $k . ': ' . $v;
        }

        $responseHeaders = [];
        $downloadedBytes = 0;

        curl_setopt_array($ch, [
            CURLOPT_URL              => $url,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_FOLLOWLOCATION   => false,
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_CONNECTTIMEOUT   => 3,
            CURLOPT_ENCODING         => '',
            CURLOPT_CUSTOMREQUEST    => strtoupper($method),
            CURLOPT_HTTPHEADER       => $formattedHeaders,
            CURLOPT_USERAGENT        => 'SubtitleHub/5.0 (PHP)',
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $dlTotal, $dlNow) use (&$downloadedBytes): int {
                $downloadedBytes = (int) $dlNow;
                return $downloadedBytes > self::MAX_RESPONSE_BYTES ? 1 : 0;
            },
            CURLOPT_HEADERFUNCTION   => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($curlExtra !== []) {
            curl_setopt_array($ch, $curlExtra);
        }

        $responseBody = curl_exec($ch);
        $curlError    = (int) curl_errno($ch);
        $statusCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // One jittered retry for idempotent GETs on transient transport errors.
        // HTTP error statuses (4xx/5xx) are never retried (curl errno is 0 there).
        if ($allowRetry && strtoupper($method) === 'GET' && self::isTransientCurlError($curlError)) {
            usleep(random_int(20000, 60000));
            return $this->singleRequest($method, $url, $headers, $body, $timeout, $curlExtra, false);
        }

        return [
            'status'  => $statusCode,
            'body'    => is_string($responseBody) ? $responseBody : '',
            'headers' => $responseHeaders,
        ];
    }

    private static function isTransientCurlError(int $errno): bool
    {
        return in_array($errno, [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
            CURLE_PARTIAL_FILE,
        ], true);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    public function getJson(string $url, array $headers = [], int $timeout = 10): ?array
    {
        $headers['Accept'] = 'application/json';
        $res = $this->request('GET', $url, $headers, null, $timeout);

        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return null;
        }

        $decoded = json_decode($res['body'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string|int, string|array{url: string, headers?: array<string, string>, rawHtml?: bool}> $requests
     * @return array<string|int, array<string, mixed>|null>
     */
    public function getJsonBatch(array $requests, int $timeout = 10): array
    {
        if (empty($requests)) {
            return [];
        }

        $items = [];
        foreach ($requests as $key => $req) {
            $url = is_array($req) ? (string) ($req['url'] ?? '') : (string) $req;
            $reqHeaders = is_array($req) ? ($req['headers'] ?? []) : [];
            if (!is_array($reqHeaders)) {
                $reqHeaders = [];
            }
            $isRaw = is_array($req) && !empty($req['rawHtml']);
            if (!$isRaw && !isset($reqHeaders['Accept'])) {
                $reqHeaders['Accept'] = 'application/json';
            }
            $items[$key] = [
                'url'     => $url,
                'headers' => $reqHeaders,
                'raw'     => $isRaw,
                'hops'    => 0,
                'retries' => 0,
            ];
        }

        $results = [];
        $pending = $items;

        // Bounded redirect chain. Each round is a parallel curl_multi batch, so
        // following redirects keeps the concurrency of the original code.
        for ($round = 0; $round <= 5 && $pending !== []; $round++) {
            $roundResults = $this->multiRound($pending, $timeout);
            $nextPending  = [];

            foreach ($roundResults as $key => $res) {
                $item = $pending[$key];

                // One jittered retry for transient curl/timeout errors on GET.
                if ($res['errno'] !== 0 && self::isTransientCurlError($res['errno']) && $item['retries'] < 1) {
                    usleep(random_int(20000, 60000));
                    $item['retries']++;
                    $nextPending[$key] = $item;
                    continue;
                }

                $isRedirect = in_array($res['status'], [301, 302, 303, 307, 308], true)
                    && !empty($res['headers']['location']);
                if ($item['hops'] >= 3 || !$isRedirect) {
                    $results[$key] = self::decodeBatchResult($res, $item['raw']);
                    continue;
                }

                $next = self::safeRedirect($item['url'], (string) $res['headers']['location']);
                if ($next === null) {
                    $results[$key] = null;
                    continue;
                }

                $headers = $item['headers'];
                if (strtolower((string) parse_url($next, PHP_URL_HOST))
                    !== strtolower((string) parse_url($item['url'], PHP_URL_HOST))) {
                    // Origin change: strip sensitive headers, same as request().
                    $headers = [];
                    if (!$item['raw']) {
                        $headers['Accept'] = 'application/json';
                    }
                }

                $item['url']     = $next;
                $item['headers'] = $headers;
                $item['hops']++;
                $nextPending[$key] = $item;
            }

            $pending = $nextPending;
        }

        foreach ($items as $key => $unused) {
            if (!array_key_exists($key, $results)) {
                $results[$key] = null;
            }
        }

        return $results;
    }

    /**
     * Resolve and validate a redirect target: HTTPS only, absolute URL, no
     * private/reserved IP-literal host. Mirrors request()'s redirect rules.
     */
    private static function safeRedirect(string $currentUrl, string $location): ?string
    {
        $next = self::absolutize($currentUrl, $location);
        if (strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https') {
            return null; // Never downgrade HTTPS -> HTTP
        }

        $host = strtolower((string) parse_url($next, PHP_URL_HOST));
        if ($host === '' || str_contains($host, ' ')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $next;
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, errno: int} $res
     */
    private static function decodeBatchResult(array $res, bool $isRaw): ?array
    {
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return null;
        }
        if ($isRaw) {
            return ['_html' => $res['body']];
        }
        $decoded = json_decode($res['body'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Execute one parallel round of GETs, capturing status, headers and errno
     * for each handle so the caller can follow redirects / retry safely.
     *
     * @param array<mixed, array{url: string, headers: array<string, string>, raw: bool, hops: int, retries: int}> $items
     * @return array<mixed, array{status: int, body: string, headers: array<string, string>, errno: int}>
     */
    private function multiRound(array $items, int $timeout): array
    {
        $out = [];
        $mh  = curl_multi_init();

        if ($mh !== false) {
            $handles      = [];
            $headersByKey = [];

            foreach ($items as $key => $item) {
                $formattedHeaders = [];
                foreach ($item['headers'] as $hk => $hv) {
                    $formattedHeaders[] = $hk . ': ' . $hv;
                }

                $ch = curl_init();
                if ($ch === false) {
                    continue;
                }
                curl_setopt_array($ch, [
                    CURLOPT_URL              => $item['url'],
                    CURLOPT_RETURNTRANSFER   => true,
                    CURLOPT_FOLLOWLOCATION   => false,
                    CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                    CURLOPT_TIMEOUT          => $timeout,
                    CURLOPT_CONNECTTIMEOUT   => 3,
                    CURLOPT_ENCODING         => '',
                    CURLOPT_HTTPHEADER       => $formattedHeaders,
                    CURLOPT_USERAGENT        => 'SubtitleHub/5.0 (PHP)',
                    CURLOPT_SSL_VERIFYPEER   => true,
                    CURLOPT_SSL_VERIFYHOST   => 2,
                    CURLOPT_NOPROGRESS       => false,
                    CURLOPT_PROGRESSFUNCTION => static function ($resource, $dlTotal, $dlNow): int {
                        return ((int) $dlNow) > self::MAX_RESPONSE_BYTES ? 1 : 0;
                    },
                    CURLOPT_HEADERFUNCTION   => static function ($curl, string $headerLine) use (&$headersByKey, $key): int {
                        $len = strlen($headerLine);
                        $parts = explode(':', $headerLine, 2);
                        if (count($parts) === 2) {
                            $headersByKey[$key][strtolower(trim($parts[0]))] = trim($parts[1]);
                        }
                        return $len;
                    },
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active && curl_multi_select($mh, 0.2) === -1) {
                    usleep(10000);
                }
            } while ($active && $status === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $body = curl_multi_getcontent($ch);
                $out[$key] = [
                    'status'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                    'body'    => is_string($body) ? $body : '',
                    'headers' => $headersByKey[$key] ?? [],
                    'errno'   => (int) curl_errno($ch),
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        foreach ($items as $key => $unused) {
            if (!isset($out[$key])) {
                $out[$key] = ['status' => 0, 'body' => '', 'headers' => [], 'errno' => CURLE_COULDNT_CONNECT];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 10): ?array
    {
        $headers['Accept']       = 'application/json';
        $headers['Content-Type'] = 'application/json';
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $res = $this->request('POST', $url, $headers, $jsonBody ?: '{}', $timeout);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return null;
        }

        $decoded = json_decode($res['body'], true);
        return is_array($decoded) ? $decoded : null;
    }
}

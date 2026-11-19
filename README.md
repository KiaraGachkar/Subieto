# Subieto v28 — Editorial Subtitle Aggregator

PHP 8.1+ bilingual subtitle aggregator with hardened security, geo-targeted ads,
programmatic SEO and partitioned static cache.

## Highlights

- **Static page cache** with embedded TTL (`<!-- exp:TIMESTAMP -->`), partitioned by
  locale and visitor geo. Covers `/`, `/search`, `/subtitles/*`, sitemaps and robots.txt.
  Cache hits skip the expensive metrics/dedup path (`MetricsService::recordCachedView()`).
- **Parallel provider aggregation** via curl_multi with bounded redirects, DNS pinning,
  HTTPS-only fetches and a 5 MB response cap. Subf2m fallback runs only when the batch
  is short and its result is cached separately.
- **Cache invalidation** includes enabled providers and API-key fingerprints; saving
  settings clears both static pages and the API cache.
- **Security**: CSRF with `hash_equals`, session regeneration on privilege change,
  HMAC-SHA256 signed download URLs with optional IP binding, trusted-proxy-aware client
  IP, rate limiting, CSP with per-request script nonces, and an opt-in installer
  (`SUBIETO_ALLOW_INSTALL=1`) that is disabled by default.
- **SEO**: self-referencing hreflang canonicals with `x-default`, availability-aware
  sitemap generation, structured data with safe JSON encoding, and no third-party
  rating data passed off as site reviews.

## Install

Set `SUBIETO_ALLOW_INSTALL=1`, place a blank `storage/installed.lock`-free checkout behind
a trusted host, open `/install`, then remove the environment variable. For existing
installations the web installer is intentionally unavailable.

## Deploy notes

- Run `composer install --no-dev -o` to generate the optimized autoloader.
- Enable OPcache (`opcache.enable=1`) in production; precompiled admin CSS is recommended
  over the in-browser Tailwind CDN used by the admin views.
- `site.cache_ttl` is clamped to 7 days for API entries and 1 hour for detail pages.
- Suggested extensions: `apcu` (fast cache/rate-limit path), `zip` (in-archive processing),
  `iconv` (legacy CP1256 subtitles).

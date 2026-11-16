<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ClientIp;
use App\Core\SettingsManager;
use App\DTOs\MediaItem;
use App\DTOs\SubtitleItem;

defined('APP_SECURE_BOOT') or exit;

final class SeoHelper
{
    private static string $configuredBaseUrl = '';

    public static function configure(string $url): void
    {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $url)) {
            self::$configuredBaseUrl = $url;
        }
    }

    public static function isConfigured(): bool
    {
        return self::$configuredBaseUrl !== '';
    }

    public static function isTrustedHost(): bool
    {
        if (self::$configuredBaseUrl === '') {
            return false;
        }
        $configuredHost = strtolower((string) parse_url(self::$configuredBaseUrl, PHP_URL_HOST));
        $incomingHost   = strtolower((string) explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        return $configuredHost !== '' && $configuredHost === $incomingHost;
    }

    public static function getBaseUrl(): string
    {
        if (self::$configuredBaseUrl !== '') {
            return self::$configuredBaseUrl;
        }

        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }

        $scheme = ClientIp::isHttps() ? 'https' : 'http';
        return "{$scheme}://{$host}";
    }

    /**
     * @param array{code: string, label: string, slug: string}|null $langInfo
     */
    public static function buildMovieTitle(
        SettingsManager $settings,
        MediaItem $media,
        ?int $season = null,
        ?array $langInfo = null,
        string $locale = 'en'
    ): string {
        $siteTitle = (string) $settings->get('site.title', 'Subieto');
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $siteTitle)) {
            $siteTitle = 'Subieto';
        }

        if ($locale === 'fa') {
            $tpl = (string) $settings->get('seo.movie_title_tpl', 'دانلود زیرنویس {title} {fa_title} ({year}) | {site}');
            $langNames = ['fa' => 'فارسی', 'en' => 'انگلیسی', 'ar' => 'عربی', 'es' => 'اسپانیایی', 'fr' => 'فرانسه', 'tr' => 'ترکی'];
            $title = strtr($tpl, [
                '{title}'    => $media->title,
                '{fa_title}' => $media->persianTitle,
                '{year}'     => $media->year,
                '{site}'     => $siteTitle,
                '{season}'   => $season !== null ? " فصل {$season}" : '',
                '{lang}'     => $langInfo !== null ? ' ' . ($langNames[$langInfo['code']] ?? strtoupper($langInfo['code'])) : '',
            ]);
            return trim((string) preg_replace('/\s+/u', ' ', $title));
        }

        $yearPart = $media->year !== '' ? " ({$media->year})" : '';
        $seasonTx = $season !== null ? " Season {$season}" : '';
        $langTx   = $langInfo !== null ? ' ' . $langInfo['label'] : ' English & Multi-Language';
        return "Download {$media->title}{$seasonTx}{$yearPart}{$langTx} Subtitles (SRT) | {$siteTitle}";
    }

    /**
     * @param SubtitleItem[] $subtitles
     * @param array{code: string, label: string, slug: string}|null $langInfo
     */
    public static function buildMovieMetaDescription(
        MediaItem $media,
        array $subtitles,
        ?int $season = null,
        ?array $langInfo = null,
        string $locale = 'en'
    ): string {
        $count    = count($subtitles);
        $yearStr  = $media->year !== '' ? " ({$media->year})" : '';
        $seasonTx = ($season !== null && $season > 0) ? " Season {$season}" : '';
        $langTx   = $langInfo !== null ? " {$langInfo['label']}" : '';

        if ($locale === 'fa') {
            $typeLabel = $media->mediaType === 'tv' ? 'سریال' : 'فیلم';
            $seasonTx  = ($season !== null && $season > 0) ? " فصل {$season}" : '';
            $langTx    = $langInfo !== null ? " {$langInfo['label']}" : '';
            return "دانلود {$count} فایل زیرنویس تأییدشده{$langTx} برای {$typeLabel} «{$media->title}»{$yearStr}{$seasonTx} (IMDb: {$media->imdbId}). سازگار با نسخه‌های BluRay و WEB-DL با کدگذاری تمیز UTF-8.";
        }

        $typeLabel = $media->mediaType === 'tv' ? 'TV series' : 'movie';
        return "Download {$count} verified{$langTx} SRT subtitles for {$typeLabel} {$media->title}{$yearStr}{$seasonTx} (IMDb: {$media->imdbId}). Compatible with BluRay, WEB-DL, and HDRip releases in clean UTF-8 encoding.";
    }

    /**
     * @param SubtitleItem[] $subtitles
     */
    public static function buildMovieJsonLd(
        SettingsManager $settings,
        MediaItem $media,
        array $subtitles,
        string $canonicalUrl,
        ?int $season = null,
        string $locale = 'en'
    ): string {
        $baseUrl   = self::getBaseUrl();
        $siteTitle = (string) $settings->get('site.title', 'Subieto');
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $siteTitle)) {
            $siteTitle = 'Subieto';
        }
        $count = count($subtitles);
        $isFa  = $locale === 'fa';

        $description = $media->overview !== ''
            ? $media->overview
            : self::buildMovieMetaDescription($media, $subtitles, $season, null, $locale);
        if ($isFa && $media->persianOverview !== '') {
            $description = $media->persianOverview;
        }

        $mediaSchema = [
            '@type'       => $media->mediaType === 'tv' ? 'TVSeries' : 'Movie',
            'name'        => $media->title,
            'image'       => $media->posterUrl,
            'description' => $description,
            'sameAs'      => 'https://www.imdb.com/title/' . $media->imdbId . '/',
            'url'         => $baseUrl . '/subtitles/' . $media->imdbId . '/' . $media->slug,
        ];

        if ($media->year !== '') {
            $year = trim($media->year);
            $mediaSchema['datePublished'] = preg_match('/^\d{4}$/', $year) ? "{$year}-01-01" : $year;
        }

        $breadcrumbItems = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => $siteTitle,
                'item'     => $baseUrl . '/',
            ],
            [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $isFa ? "زیرنویس {$media->title}" : "{$media->title} Subtitles",
                'item'     => $baseUrl . '/subtitles/' . $media->imdbId . '/' . $media->slug,
            ],
        ];

        if ($season !== null && $season > 0) {
            $breadcrumbItems[] = [
                '@type'    => 'ListItem',
                'position' => 3,
                'name'     => $isFa ? "فصل {$season}" : "Season {$season}",
                'item'     => $canonicalUrl,
            ];
        }

        $subjectTitle = ($season !== null && $season > 0)
            ? ($isFa ? "{$media->title} فصل {$season}" : "{$media->title} Season {$season}")
            : $media->title;

        if ($isFa) {
            $faqSchema = [
                '@type'      => 'FAQPage',
                'mainEntity' => [
                    [
                        '@type'          => 'Question',
                        'name'           => "چگونه زیرنویس {$subjectTitle} را دانلود کنیم؟",
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => "{$count} فایل زیرنویس تأییدشده برای {$subjectTitle} موجود است. نسخه‌ای را انتخاب کنید که با فایل ویدیویی خود مطابقت دارد (مانند BluRay یا WEB-DL) و روی دانلود کلیک کنید تا فایل SRT با کدگذاری UTF-8 فوراً دریافت شود.",
                        ],
                    ],
                    [
                        '@type'          => 'Question',
                        'name'           => "چگونه مشکلات هماهنگی زمانی زیرنویس را برطرف کنیم؟",
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => "روی ابزار تنظیم زمان بالای فهرست زیرنویس کلیک کنید تا زمان را در گام‌های ±0.5 ثانیهای جابهجا کنید، یا در VLC Media Player کلیدهای G و H را فشار دهید.",
                        ],
                    ],
                ],
            ];
        } else {
            $faqSchema = [
                '@type'      => 'FAQPage',
                'mainEntity' => [
                    [
                        '@type'          => 'Question',
                        'name'           => "How to download subtitles for {$subjectTitle}?",
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => "There are {$count} verified subtitle files available for {$subjectTitle}. Select the release matching your video file (such as BluRay or WEB-DL) and click Download for an instant UTF-8 SRT file.",
                        ],
                    ],
                    [
                        '@type'          => 'Question',
                        'name'           => "How can I fix subtitle timing sync issues?",
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => "Click Sync Tools above the subtitle list to shift timestamps by ±0.5s increments before downloading, or press G and H in VLC Media Player.",
                        ],
                    ],
                ],
            ];
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                $mediaSchema,
                [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => $breadcrumbItems,
                ],
                $faqSchema,
            ],
        ];

        return (string) json_encode(
            $graph,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    public static function buildHomeJsonLd(SettingsManager $settings, string $locale = 'en'): string
    {
        $baseUrl   = self::getBaseUrl();
        $siteTitle = (string) $settings->get('site.title', 'Subieto');
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $siteTitle)) {
            $siteTitle = 'Subieto';
        }

        $description = $locale === 'fa'
            ? 'دانلود مستقیم زیرنویس فیلم و سریال با کدگذاری تمیز UTF-8 و فرمت SRT.'
            : 'Fast direct movie and TV series subtitle downloads in clean UTF-8 SRT format.';

        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'           => 'WebSite',
                    'name'            => $siteTitle,
                    'description'     => $description,
                    'url'             => $baseUrl . '/',
                    'potentialAction' => [
                        '@type'       => 'SearchAction',
                        'target'      => $baseUrl . '/search?q={search_term_string}',
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        ];

        return (string) json_encode(
            $graph,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

defined('APP_SECURE_BOOT') or exit;

final class LanguageMap
{
    /** @var array<string, array{code: string, label: string, slug: string}> */
    private const MAP = [
        'en' => ['code' => 'en', 'label' => 'English', 'slug' => 'english'],
        'eng' => ['code' => 'en', 'label' => 'English', 'slug' => 'english'],
        'english' => ['code' => 'en', 'label' => 'English', 'slug' => 'english'],

        'fa' => ['code' => 'fa', 'label' => 'Persian', 'slug' => 'persian'],
        'per' => ['code' => 'fa', 'label' => 'Persian', 'slug' => 'persian'],
        'fas' => ['code' => 'fa', 'label' => 'Persian', 'slug' => 'persian'],
        'farsi' => ['code' => 'fa', 'label' => 'Persian', 'slug' => 'persian'],
        'persian' => ['code' => 'fa', 'label' => 'Persian', 'slug' => 'persian'],
        'farsi_persian' => ['code' => 'fa', 'label' => 'Persian', 'slug' => 'persian'],

        'ar' => ['code' => 'ar', 'label' => 'Arabic', 'slug' => 'arabic'],
        'ara' => ['code' => 'ar', 'label' => 'Arabic', 'slug' => 'arabic'],
        'arabic' => ['code' => 'ar', 'label' => 'Arabic', 'slug' => 'arabic'],

        'es' => ['code' => 'es', 'label' => 'Spanish', 'slug' => 'spanish'],
        'spa' => ['code' => 'es', 'label' => 'Spanish', 'slug' => 'spanish'],
        'spanish' => ['code' => 'es', 'label' => 'Spanish', 'slug' => 'spanish'],

        'fr' => ['code' => 'fr', 'label' => 'French', 'slug' => 'french'],
        'fre' => ['code' => 'fr', 'label' => 'French', 'slug' => 'french'],
        'fra' => ['code' => 'fr', 'label' => 'French', 'slug' => 'french'],
        'french' => ['code' => 'fr', 'label' => 'French', 'slug' => 'french'],

        'de' => ['code' => 'de', 'label' => 'German', 'slug' => 'german'],
        'ger' => ['code' => 'de', 'label' => 'German', 'slug' => 'german'],
        'deu' => ['code' => 'de', 'label' => 'German', 'slug' => 'german'],
        'german' => ['code' => 'de', 'label' => 'German', 'slug' => 'german'],

        'tr' => ['code' => 'tr', 'label' => 'Turkish', 'slug' => 'turkish'],
        'tur' => ['code' => 'tr', 'label' => 'Turkish', 'slug' => 'turkish'],
        'turkish' => ['code' => 'tr', 'label' => 'Turkish', 'slug' => 'turkish'],

        'ru' => ['code' => 'ru', 'label' => 'Russian', 'slug' => 'russian'],
        'rus' => ['code' => 'ru', 'label' => 'Russian', 'slug' => 'russian'],
        'russian' => ['code' => 'ru', 'label' => 'Russian', 'slug' => 'russian'],

        'it' => ['code' => 'it', 'label' => 'Italian', 'slug' => 'italian'],
        'ita' => ['code' => 'it', 'label' => 'Italian', 'slug' => 'italian'],
        'italian' => ['code' => 'it', 'label' => 'Italian', 'slug' => 'italian'],

        'pt' => ['code' => 'pt', 'label' => 'Portuguese', 'slug' => 'portuguese'],
        'por' => ['code' => 'pt', 'label' => 'Portuguese', 'slug' => 'portuguese'],
        'portuguese' => ['code' => 'pt', 'label' => 'Portuguese', 'slug' => 'portuguese'],
        'pob' => ['code' => 'pt', 'label' => 'Portuguese (BR)', 'slug' => 'portuguese'],
        'brazilian' => ['code' => 'pt', 'label' => 'Portuguese (BR)', 'slug' => 'portuguese'],

        'ko' => ['code' => 'ko', 'label' => 'Korean', 'slug' => 'korean'],
        'kor' => ['code' => 'ko', 'label' => 'Korean', 'slug' => 'korean'],
        'korean' => ['code' => 'ko', 'label' => 'Korean', 'slug' => 'korean'],

        'ja' => ['code' => 'ja', 'label' => 'Japanese', 'slug' => 'japanese'],
        'jpn' => ['code' => 'ja', 'label' => 'Japanese', 'slug' => 'japanese'],
        'japanese' => ['code' => 'ja', 'label' => 'Japanese', 'slug' => 'japanese'],

        'zh' => ['code' => 'zh', 'label' => 'Chinese', 'slug' => 'chinese'],
        'chi' => ['code' => 'zh', 'label' => 'Chinese', 'slug' => 'chinese'],
        'zho' => ['code' => 'zh', 'label' => 'Chinese', 'slug' => 'chinese'],
        'chinese' => ['code' => 'zh', 'label' => 'Chinese', 'slug' => 'chinese'],

        'hi' => ['code' => 'hi', 'label' => 'Hindi', 'slug' => 'hindi'],
        'hin' => ['code' => 'hi', 'label' => 'Hindi', 'slug' => 'hindi'],
        'hindi' => ['code' => 'hi', 'label' => 'Hindi', 'slug' => 'hindi'],

        'nl' => ['code' => 'nl', 'label' => 'Dutch', 'slug' => 'dutch'],
        'dut' => ['code' => 'nl', 'label' => 'Dutch', 'slug' => 'dutch'],
        'nld' => ['code' => 'nl', 'label' => 'Dutch', 'slug' => 'dutch'],
        'dutch' => ['code' => 'nl', 'label' => 'Dutch', 'slug' => 'dutch'],

        'pl' => ['code' => 'pl', 'label' => 'Polish', 'slug' => 'polish'],
        'pol' => ['code' => 'pl', 'label' => 'Polish', 'slug' => 'polish'],
        'polish' => ['code' => 'pl', 'label' => 'Polish', 'slug' => 'polish'],

        'sv' => ['code' => 'sv', 'label' => 'Swedish', 'slug' => 'swedish'],
        'swe' => ['code' => 'sv', 'label' => 'Swedish', 'slug' => 'swedish'],
        'swedish' => ['code' => 'sv', 'label' => 'Swedish', 'slug' => 'swedish'],

        'fi' => ['code' => 'fi', 'label' => 'Finnish', 'slug' => 'finnish'],
        'fin' => ['code' => 'fi', 'label' => 'Finnish', 'slug' => 'finnish'],
        'finnish' => ['code' => 'fi', 'label' => 'Finnish', 'slug' => 'finnish'],

        'da' => ['code' => 'da', 'label' => 'Danish', 'slug' => 'danish'],
        'dan' => ['code' => 'da', 'label' => 'Danish', 'slug' => 'danish'],
        'danish' => ['code' => 'da', 'label' => 'Danish', 'slug' => 'danish'],

        'no' => ['code' => 'no', 'label' => 'Norwegian', 'slug' => 'norwegian'],
        'nor' => ['code' => 'no', 'label' => 'Norwegian', 'slug' => 'norwegian'],
        'norwegian' => ['code' => 'no', 'label' => 'Norwegian', 'slug' => 'norwegian'],

        'el' => ['code' => 'el', 'label' => 'Greek', 'slug' => 'greek'],
        'gre' => ['code' => 'el', 'label' => 'Greek', 'slug' => 'greek'],
        'ell' => ['code' => 'el', 'label' => 'Greek', 'slug' => 'greek'],
        'greek' => ['code' => 'el', 'label' => 'Greek', 'slug' => 'greek'],

        'he' => ['code' => 'he', 'label' => 'Hebrew', 'slug' => 'hebrew'],
        'heb' => ['code' => 'he', 'label' => 'Hebrew', 'slug' => 'hebrew'],
        'hebrew' => ['code' => 'he', 'label' => 'Hebrew', 'slug' => 'hebrew'],

        'id' => ['code' => 'id', 'label' => 'Indonesian', 'slug' => 'indonesian'],
        'ind' => ['code' => 'id', 'label' => 'Indonesian', 'slug' => 'indonesian'],
        'indonesian' => ['code' => 'id', 'label' => 'Indonesian', 'slug' => 'indonesian'],

        'vi' => ['code' => 'vi', 'label' => 'Vietnamese', 'slug' => 'vietnamese'],
        'vie' => ['code' => 'vi', 'label' => 'Vietnamese', 'slug' => 'vietnamese'],
        'vietnamese' => ['code' => 'vi', 'label' => 'Vietnamese', 'slug' => 'vietnamese'],

        'th' => ['code' => 'th', 'label' => 'Thai', 'slug' => 'thai'],
        'tha' => ['code' => 'th', 'label' => 'Thai', 'slug' => 'thai'],
        'thai' => ['code' => 'th', 'label' => 'Thai', 'slug' => 'thai'],

        'ro' => ['code' => 'ro', 'label' => 'Romanian', 'slug' => 'romanian'],
        'rum' => ['code' => 'ro', 'label' => 'Romanian', 'slug' => 'romanian'],
        'ron' => ['code' => 'ro', 'label' => 'Romanian', 'slug' => 'romanian'],
        'romanian' => ['code' => 'ro', 'label' => 'Romanian', 'slug' => 'romanian'],

        'cs' => ['code' => 'cs', 'label' => 'Czech', 'slug' => 'czech'],
        'cze' => ['code' => 'cs', 'label' => 'Czech', 'slug' => 'czech'],
        'ces' => ['code' => 'cs', 'label' => 'Czech', 'slug' => 'czech'],
        'czech' => ['code' => 'cs', 'label' => 'Czech', 'slug' => 'czech'],

        'hu' => ['code' => 'hu', 'label' => 'Hungarian', 'slug' => 'hungarian'],
        'hun' => ['code' => 'hu', 'label' => 'Hungarian', 'slug' => 'hungarian'],
        'hungarian' => ['code' => 'hu', 'label' => 'Hungarian', 'slug' => 'hungarian'],

        'uk' => ['code' => 'uk', 'label' => 'Ukrainian', 'slug' => 'ukrainian'],
        'ukr' => ['code' => 'uk', 'label' => 'Ukrainian', 'slug' => 'ukrainian'],
        'ukrainian' => ['code' => 'uk', 'label' => 'Ukrainian', 'slug' => 'ukrainian'],

        'bg' => ['code' => 'bg', 'label' => 'Bulgarian', 'slug' => 'bulgarian'],
        'bul' => ['code' => 'bg', 'label' => 'Bulgarian', 'slug' => 'bulgarian'],
        'bulgarian' => ['code' => 'bg', 'label' => 'Bulgarian', 'slug' => 'bulgarian'],

        'hr' => ['code' => 'hr', 'label' => 'Croatian', 'slug' => 'croatian'],
        'hrv' => ['code' => 'hr', 'label' => 'Croatian', 'slug' => 'croatian'],
        'croatian' => ['code' => 'hr', 'label' => 'Croatian', 'slug' => 'croatian'],

        'sr' => ['code' => 'sr', 'label' => 'Serbian', 'slug' => 'serbian'],
        'srp' => ['code' => 'sr', 'label' => 'Serbian', 'slug' => 'serbian'],
        'serbian' => ['code' => 'sr', 'label' => 'Serbian', 'slug' => 'serbian'],

        'ms' => ['code' => 'ms', 'label' => 'Malay', 'slug' => 'malay'],
        'may' => ['code' => 'ms', 'label' => 'Malay', 'slug' => 'malay'],
        'msa' => ['code' => 'ms', 'label' => 'Malay', 'slug' => 'malay'],
        'malay' => ['code' => 'ms', 'label' => 'Malay', 'slug' => 'malay'],
    ];

    /**
     * @return array{code: string, label: string, slug: string}
     */
    public static function resolve(string $raw): array
    {
        $clean = strtolower(trim($raw));
        if ($clean === '') {
            return ['code' => 'un', 'label' => 'Other', 'slug' => 'other'];
        }

        if (isset(self::MAP[$clean])) {
            return self::MAP[$clean];
        }

        $primary = preg_split('/[-_\/\s]+/', $clean)[0] ?? $clean;
        if (isset(self::MAP[$primary])) {
            return self::MAP[$primary];
        }

        $short = substr(preg_replace('/[^a-z]/', '', $clean) ?: 'un', 0, 2);
        if ($short === '') {
            $short = 'un';
        }
        $label = ucfirst(str_replace(['_', '-'], ' ', $clean));
        $slug  = preg_replace('/[^a-z0-9]+/', '-', $clean) ?: 'other';
        return ['code' => $short, 'label' => $label, 'slug' => trim($slug, '-')];
    }

    /**
     * Resolves a URL language segment like "spanish", "persian", or "ar" into ['code', 'label', 'slug'] or null if unknown.
     *
     * @return array{code: string, label: string, slug: string}|null
     */
    public static function fromUrlSlug(string $segment): ?array
    {
        $clean = strtolower(trim($segment));
        return self::MAP[$clean] ?? null;
    }

    /**
     * @return array{code: string, label: string}
     */
    public static function fromPodnapisiId(int $langId, string $fallbackName = ''): array
    {
        $byId = [
            2  => ['code' => 'en', 'label' => 'English'],
            52 => ['code' => 'fa', 'label' => 'Persian'],
            12 => ['code' => 'ar', 'label' => 'Arabic'],
            28 => ['code' => 'es', 'label' => 'Spanish'],
            8  => ['code' => 'fr', 'label' => 'French'],
            5  => ['code' => 'de', 'label' => 'German'],
            30 => ['code' => 'tr', 'label' => 'Turkish'],
            27 => ['code' => 'ru', 'label' => 'Russian'],
            9  => ['code' => 'it', 'label' => 'Italian'],
            32 => ['code' => 'pt', 'label' => 'Portuguese'],
            48 => ['code' => 'pt', 'label' => 'Portuguese (BR)'],
            4  => ['code' => 'ko', 'label' => 'Korean'],
            11 => ['code' => 'ja', 'label' => 'Japanese'],
            17 => ['code' => 'zh', 'label' => 'Chinese'],
            42 => ['code' => 'hi', 'label' => 'Hindi'],
            23 => ['code' => 'nl', 'label' => 'Dutch'],
            26 => ['code' => 'pl', 'label' => 'Polish'],
            25 => ['code' => 'sv', 'label' => 'Swedish'],
            31 => ['code' => 'fi', 'label' => 'Finnish'],
            24 => ['code' => 'da', 'label' => 'Danish'],
            45 => ['code' => 'no', 'label' => 'Norwegian'],
            16 => ['code' => 'el', 'label' => 'Greek'],
            22 => ['code' => 'he', 'label' => 'Hebrew'],
            54 => ['code' => 'id', 'label' => 'Indonesian'],
            51 => ['code' => 'vi', 'label' => 'Vietnamese'],
            44 => ['code' => 'th', 'label' => 'Thai'],
            13 => ['code' => 'ro', 'label' => 'Romanian'],
            7  => ['code' => 'cs', 'label' => 'Czech'],
            15 => ['code' => 'hu', 'label' => 'Hungarian'],
            46 => ['code' => 'uk', 'label' => 'Ukrainian'],
            33 => ['code' => 'bg', 'label' => 'Bulgarian'],
            38 => ['code' => 'hr', 'label' => 'Croatian'],
            36 => ['code' => 'sr', 'label' => 'Serbian'],
            55 => ['code' => 'ms', 'label' => 'Malay'],
        ];

        if (isset($byId[$langId])) {
            return $byId[$langId];
        }

        if ($fallbackName !== '') {
            $res = self::resolve($fallbackName);
            return ['code' => $res['code'], 'label' => $res['label']];
        }

        return ['code' => 'un', 'label' => 'Language #' . $langId];
    }
}

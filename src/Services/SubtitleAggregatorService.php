<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\SettingsManager;
use App\DTOs\SubtitleItem;

defined('APP_SECURE_BOOT') or exit;

/**
 * Local-Archive-Only Subtitle Service (<5ms).
 * Reads exclusively from the self-hosted MySQL archive (`localzip://`), deduplicates, and sorts.
 */
final class SubtitleAggregatorService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SubtitleRepository $repository
    ) {
    }

    /**
     * @return SubtitleItem[]
     */
    public function getSubtitlesForImdb(string $imdbId, ?int $season = null): array
    {
        $imdbId = strtolower(trim($imdbId));
        $priorityRaw = strtolower(trim((string) $this->settings->get('providers.priority_languages', 'fa,en,ar,es,fr,tr')));
        $archived = $this->repository->getByImdbId($imdbId, $season);
        if ($archived === []) {
            return [];
        }
        return $this->dedupeAndSort($archived, $priorityRaw);
    }

    /**
     * @param SubtitleItem[] $items
     * @return SubtitleItem[]
     */
    private function dedupeAndSort(array $items, string $priorityRaw): array
    {
        $providerRank = [
            'SubDL'         => 80,
            'OpenSubtitles' => 70,
            'Subzone'       => 60,
            'Subf2m'        => 55,
            'YIFY'          => 45,
        ];

        $itemScore = static function (SubtitleItem $s) use ($providerRank): int {
            $score = $providerRank[$s->provider] ?? 40;
            if ($s->qualityTag !== '' && $s->qualityTag !== 'All') {
                $score += 5;
            }
            if ($s->season !== null && $s->season > 0) {
                $score += 3;
            }
            if ($s->episode !== null && $s->episode > 0) {
                $score += 3;
            }
            return $score;
        };

        $byKey = [];
        $unkeyed = [];
        foreach ($items as $item) {
            $normRel = ReleaseParser::normalizeReleaseKey($item->releaseName);
            if ($normRel === '') {
                $unkeyed[] = $item;
                continue;
            }
            $dKey = $item->languageCode . '|' . $normRel . '|' . ($item->season ?? 0) . '|' . ($item->episode ?? 0);
            if (!isset($byKey[$dKey]) || $itemScore($item) > $itemScore($byKey[$dKey])) {
                $byKey[$dKey] = $item;
            }
        }
        $deduped = array_merge(array_values($byKey), $unkeyed);

        $priorityList = array_values(array_filter(array_map('trim', explode(',', $priorityRaw)), static fn(string $v): bool => $v !== ''));
        $priorityMap  = array_flip($priorityList);

        usort($deduped, static function (SubtitleItem $a, SubtitleItem $b) use ($priorityMap): int {
            if ($a->languageCode !== $b->languageCode) {
                $rankA = $priorityMap[$a->languageCode] ?? 999;
                $rankB = $priorityMap[$b->languageCode] ?? 999;
                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }
                return strcmp($a->languageLabel, $b->languageLabel);
            }
            if (($a->season ?? 0) !== ($b->season ?? 0)) {
                return ($a->season ?? 0) <=> ($b->season ?? 0);
            }
            if (($a->episode ?? 0) !== ($b->episode ?? 0)) {
                return ($a->episode ?? 0) <=> ($b->episode ?? 0);
            }
            return strcmp($a->releaseName, $b->releaseName);
        });

        return $deduped;
    }
}

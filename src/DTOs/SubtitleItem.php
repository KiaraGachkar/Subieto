<?php

declare(strict_types=1);

namespace App\DTOs;

defined('APP_SECURE_BOOT') or exit;

final class SubtitleItem
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $languageCode,
        public string $languageLabel,
        public string $releaseName,
        public string $translator,
        public string $qualityTag,
        public string $downloadUrl,
        public string $fileName,
        public ?int $season = null,
        public ?int $episode = null,
        public bool $hearingImpaired = false,
        public int $upvotes = 0,
        public int $downvotes = 0
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            languageCode: (string) ($data['languageCode'] ?? 'en'),
            languageLabel: (string) ($data['languageLabel'] ?? 'انگلیسی'),
            releaseName: (string) ($data['releaseName'] ?? ''),
            translator: (string) ($data['translator'] ?? 'نامشخص'),
            qualityTag: (string) ($data['qualityTag'] ?? 'All'),
            downloadUrl: (string) ($data['downloadUrl'] ?? ''),
            fileName: (string) ($data['fileName'] ?? 'subtitle.srt'),
            season: (isset($data['season']) && (int) $data['season'] > 0) ? (int) $data['season'] : null,
            episode: (isset($data['episode']) && (int) $data['episode'] > 0) ? (int) $data['episode'] : null,
            hearingImpaired: (bool) ($data['hearingImpaired'] ?? false),
            upvotes: (int) ($data['upvotes'] ?? 0),
            downvotes: (int) ($data['downvotes'] ?? 0)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'provider'        => $this->provider,
            'languageCode'    => $this->languageCode,
            'languageLabel'   => $this->languageLabel,
            'releaseName'     => $this->releaseName,
            'translator'      => $this->translator,
            'qualityTag'      => $this->qualityTag,
            'downloadUrl'     => $this->downloadUrl,
            'fileName'        => $this->fileName,
            'season'          => $this->season,
            'episode'         => $this->episode,
            'hearingImpaired' => $this->hearingImpaired,
            'upvotes'         => $this->upvotes,
            'downvotes'       => $this->downvotes,
        ];
    }
}

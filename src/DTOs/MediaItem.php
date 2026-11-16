<?php

declare(strict_types=1);

namespace App\DTOs;

defined('APP_SECURE_BOOT') or exit;

final class MediaItem
{
    public function __construct(
        public int $tmdbId,
        public string $imdbId,
        public string $title,
        public string $originalTitle,
        public string $mediaType,
        public string $year,
        public string $posterUrl,
        public string $backdropUrl,
        public string $overview,
        public float $voteAverage,
        public int $voteCount,
        public string $slug,
        public string $persianTitle = '',
        public string $persianOverview = ''
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tmdbId: (int) ($data['tmdbId'] ?? 0),
            imdbId: (string) ($data['imdbId'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            originalTitle: (string) ($data['originalTitle'] ?? ''),
            mediaType: (string) ($data['mediaType'] ?? 'movie'),
            year: (string) ($data['year'] ?? ''),
            posterUrl: (string) ($data['posterUrl'] ?? ''),
            backdropUrl: (string) ($data['backdropUrl'] ?? ''),
            overview: (string) ($data['overview'] ?? ''),
            voteAverage: (float) ($data['voteAverage'] ?? 0.0),
            voteCount: (int) ($data['voteCount'] ?? 0),
            slug: (string) ($data['slug'] ?? 'title'),
            persianTitle: (string) ($data['persianTitle'] ?? ''),
            persianOverview: (string) ($data['persianOverview'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tmdbId'          => $this->tmdbId,
            'imdbId'          => $this->imdbId,
            'title'           => $this->title,
            'originalTitle'   => $this->originalTitle,
            'mediaType'       => $this->mediaType,
            'year'            => $this->year,
            'posterUrl'       => $this->posterUrl,
            'backdropUrl'     => $this->backdropUrl,
            'overview'        => $this->overview,
            'voteAverage'     => $this->voteAverage,
            'voteCount'       => $this->voteCount,
            'slug'            => $this->slug,
            'persianTitle'    => $this->persianTitle,
            'persianOverview' => $this->persianOverview,
        ];
    }
}

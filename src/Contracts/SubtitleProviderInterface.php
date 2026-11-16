<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SubtitleItem;

defined('APP_SECURE_BOOT') or exit;

interface SubtitleProviderInterface
{
    public function getName(): string;

    public function isConfigured(): bool;

    /**
     * Returns HTTP request descriptor ['url' => string, 'headers' => array<string, string>] for parallel execution.
     *
     * @return array{url: string, headers: array<string, string>}|null
     */
    public function buildSearchRequest(string $imdbId, string $mediaType = 'movie'): ?array;

    /**
     * Parses raw decoded JSON payload from the parallel batch into SubtitleItem DTOs.
     *
     * @param array<string, mixed>|null $data
     * @return SubtitleItem[]
     */
    public function parseSearchResponse(?array $data): array;

    /**
     * @return SubtitleItem[]
     */
    public function searchByImdbId(string $imdbId, string $mediaType = 'movie'): array;
}

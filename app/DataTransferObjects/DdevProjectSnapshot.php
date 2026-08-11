<?php

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A point-in-time result of `ddev list`. Cached as raw rows so that changing
 * the shape of DdevProject can never poison an existing cache entry.
 */
final readonly class DdevProjectSnapshot
{
    /**
     * @param  Collection<int, DdevProject>  $projects
     */
    public function __construct(
        public Collection $projects,
        public CarbonImmutable $refreshedAt,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCache(array $payload): self
    {
        $rows = $payload['rows'] ?? [];

        return new self(
            projects: collect($rows)
                ->map(fn (array $row): DdevProject => DdevProject::fromListRow($row))
                ->values(),
            refreshedAt: CarbonImmutable::createFromTimestamp($payload['refreshed_at'] ?? 0),
            error: $payload['error'] ?? null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function toCache(array $rows, ?string $error = null): array
    {
        return [
            'rows' => $rows,
            'refreshed_at' => CarbonImmutable::now()->getTimestamp(),
            'error' => $error,
        ];
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}

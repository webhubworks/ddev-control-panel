<?php

namespace App\Support\Ddev;

use App\DataTransferObjects\DdevOperationState;
use App\DataTransferObjects\DdevProjectSnapshot;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;

/**
 * The app's shared view of ddev: the last `ddev list` snapshot and any
 * lifecycle commands currently in flight.
 *
 * Both the HTTP process and the queue worker read and write this, which is why
 * it lives in the cache rather than in memory. Failed operations are kept for
 * a short while so their error message can be shown before it disappears.
 */
class DdevState
{
    private const SNAPSHOT_KEY = 'ddev.snapshot';

    private const OPERATIONS_KEY = 'ddev.operations';

    private const REFRESHING_KEY = 'ddev.refreshing';

    /**
     * How long a settled operation stays visible in the popup.
     */
    private const KEEP_SETTLED_SECONDS = 20;

    public function __construct(private readonly Repository $cache) {}

    public function snapshot(): ?DdevProjectSnapshot
    {
        $payload = $this->cache->get(self::SNAPSHOT_KEY);

        return is_array($payload) ? DdevProjectSnapshot::fromCache($payload) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function putSnapshot(array $rows, ?string $error = null): void
    {
        $this->cache->forever(self::SNAPSHOT_KEY, DdevProjectSnapshot::toCache($rows, $error));
    }

    public function markRefreshing(): void
    {
        // Expires on its own so a crashed worker cannot wedge the UI in a
        // permanent "refreshing" state.
        $this->cache->put(self::REFRESHING_KEY, true, now()->addSeconds(120));
    }

    public function clearRefreshing(): void
    {
        $this->cache->forget(self::REFRESHING_KEY);
    }

    public function isRefreshing(): bool
    {
        return (bool) $this->cache->get(self::REFRESHING_KEY, false);
    }

    /**
     * @return Collection<string, DdevOperationState>
     */
    public function operations(): Collection
    {
        return collect($this->cache->get(self::OPERATIONS_KEY, []))
            ->map(fn (array $payload): DdevOperationState => DdevOperationState::fromCache($payload))
            ->reject(fn (DdevOperationState $state): bool => $state->isExpired(self::KEEP_SETTLED_SECONDS));
    }

    public function operation(string $projectName): ?DdevOperationState
    {
        return $this->operations()->get($projectName);
    }

    public function hasPendingOperations(): bool
    {
        return $this->operations()->contains(
            fn (DdevOperationState $state): bool => $state->isPending()
        );
    }

    public function putOperation(DdevOperationState $state): void
    {
        $this->mutateOperations(
            fn (array $operations): array => [...$operations, $state->projectName => $state->toCache()]
        );
    }

    public function forgetOperation(string $projectName): void
    {
        $this->mutateOperations(function (array $operations) use ($projectName): array {
            unset($operations[$projectName]);

            return $operations;
        });
    }

    /**
     * The HTTP process and the queue worker both write here, so a read /
     * modify / write cycle has to be serialised or one of them loses its entry.
     *
     * @param  Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $mutator
     */
    private function mutateOperations(Closure $mutator): void
    {
        $lock = $this->cache->lock(self::OPERATIONS_KEY.'.lock', 10);

        $lock->block(5, function () use ($mutator): void {
            $operations = $this->cache->get(self::OPERATIONS_KEY, []);

            $this->cache->forever(self::OPERATIONS_KEY, $mutator($operations));
        });
    }
}

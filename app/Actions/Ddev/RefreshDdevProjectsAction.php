<?php

namespace App\Actions\Ddev;

use App\Exceptions\DdevBinaryNotFoundException;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevCommandFailedException;
use App\Support\Ddev\DdevProjectConfig;
use App\Support\Ddev\DdevState;

/**
 * Replaces the cached project snapshot with a fresh `ddev list`.
 *
 * Always leaves a snapshot behind, even on failure, so the popup can explain
 * what went wrong instead of showing an empty list.
 */
final class RefreshDdevProjectsAction
{
    public static function refresh(): void
    {
        $state = app(DdevState::class);

        $state->markRefreshing();

        try {
            $state->putSnapshot(self::withAdditionalHosts(app(DdevCli::class)->listProjects()));
        } catch (DdevBinaryNotFoundException $exception) {
            $state->putSnapshot([], $exception->getMessage());
        } catch (DdevCommandFailedException $exception) {
            // Docker not running is by far the most common cause here.
            $state->putSnapshot([], $exception->result->combinedOutput() ?: $exception->getMessage());
        } finally {
            $state->clearRefreshing();
        }
    }

    /**
     * Fold each project's extra hosts into its row, so the popup holds every
     * URL a project answers on and never has to run `ddev describe` for them.
     *
     * This happens here rather than in the snapshot's own hydration because a
     * snapshot is re-read from cache on every poll, and reading config files
     * that often would put file I/O back on the hot path we moved ddev off.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withAdditionalHosts(array $rows): array
    {
        return array_map(function (array $row): array {
            $config = DdevProjectConfig::read((string) ($row['approot'] ?? ''));

            return [
                ...$row,
                'additional_hostnames' => $config->additionalHostnames,
                'additional_fqdns' => $config->additionalFqdns,
            ];
        }, $rows);
    }
}

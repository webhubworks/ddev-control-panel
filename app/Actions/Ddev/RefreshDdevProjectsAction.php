<?php

namespace App\Actions\Ddev;

use App\Exceptions\DdevBinaryNotFoundException;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevCommandFailedException;
use App\Support\Ddev\DdevProjectConfig;
use App\Support\Ddev\DdevState;
use App\Support\Docker\DockerCli;

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
            $state->putSnapshot(self::withLocalDetail(app(DdevCli::class)->listProjects()));
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
     * Fold in the two things the popup needs and `ddev list` does not report:
     * the extra hosts a project answers on, and its published database port.
     * Both would otherwise mean `ddev describe`, at about a second per project.
     *
     * This happens here rather than in the snapshot's own hydration because a
     * snapshot is re-read from cache on every poll, and reading config files
     * that often would put file I/O back on the hot path we moved ddev off.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withLocalDetail(array $rows): array
    {
        // One docker call for every project, next to a `ddev list` that has
        // already spent seconds inspecting the same containers.
        $databases = app(DockerCli::class)->databasePorts();

        return array_map(function (array $row) use ($databases): array {
            $config = DdevProjectConfig::read((string) ($row['approot'] ?? ''));
            $database = $databases[(string) ($row['name'] ?? '')] ?? null;

            return [
                ...$row,
                'additional_hostnames' => $config->additionalHostnames,
                'additional_fqdns' => $config->additionalFqdns,
                'database_host_port' => $database['host_port'] ?? null,
                'database_container_port' => $database['container_port'] ?? null,
            ];
        }, $rows);
    }
}

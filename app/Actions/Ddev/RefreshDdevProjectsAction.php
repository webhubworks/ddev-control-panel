<?php

namespace App\Actions\Ddev;

use App\Exceptions\DdevBinaryNotFoundException;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevCommandFailedException;
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
            $state->putSnapshot(app(DdevCli::class)->listProjects());
        } catch (DdevBinaryNotFoundException $exception) {
            $state->putSnapshot([], $exception->getMessage());
        } catch (DdevCommandFailedException $exception) {
            // Docker not running is by far the most common cause here.
            $state->putSnapshot([], $exception->result->combinedOutput() ?: $exception->getMessage());
        } finally {
            $state->clearRefreshing();
        }
    }
}

<?php

namespace App\Actions\Ddev;

use App\DataTransferObjects\DdevOperationState;
use App\DataTransferObjects\DdevProjectSnapshot;
use App\Enums\DdevOperationStatus;
use App\Support\Ddev\DdevState;

/**
 * Finishes any pending operation the snapshot proves is done.
 *
 * The ddev process exiting is the usual proof, and on most projects it is the
 * first to arrive. It is not the only one, and on some projects it never
 * arrives at all: a `post-start` hook that starts a dev server through `exec`
 * rather than `web_extra_daemons` keeps `ddev start` attached to it forever,
 * so the row would read "Starting" until the operation's own timeout, minutes
 * after the project came up and started serving.
 *
 * So every fresh `ddev list` is given the chance to settle what it can see.
 * The process result still lands when it arrives, as long as the operation is
 * pending; a run this already settled is left alone rather than re-opened
 * minutes later with a timeout for a project that is plainly running.
 */
final class SettleDdevOperationsAction
{
    public static function settle(DdevProjectSnapshot $snapshot): void
    {
        $state = app(DdevState::class);

        $state->operations()
            ->filter(fn (DdevOperationState $operation): bool => $operation->isPending())
            ->each(function (DdevOperationState $operation) use ($state, $snapshot): void {
                $satisfied = $operation->operation->isSatisfiedBy($snapshot, $operation->projectName);

                // Nothing is conclusive until the project has been seen away
                // from where the operation is taking it, or a restart would
                // finish on the first refresh, before ddev had touched it.
                if (! $operation->observedDeparture) {
                    if (! $satisfied) {
                        $state->putOperation($operation->departed());
                    }

                    return;
                }

                if ($satisfied) {
                    // No message: in the ordinary case this runs while ddev is
                    // still printing, and the row wants no banner for a start
                    // that worked.
                    $state->putOperation($operation->settled(DdevOperationStatus::Succeeded));
                }
            });
    }
}

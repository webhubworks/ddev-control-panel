<?php

namespace App\Actions\Ddev;

use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\Jobs\RunDdevOperationJob;
use App\Support\Ddev\DdevState;

/**
 * Hands a lifecycle command off to the queue worker.
 *
 * The app is served by a single-worker PHP server, so running `ddev start`
 * inline would freeze the entire popup for the duration. Everything slow goes
 * through the queue; the UI polls the recorded state instead.
 */
final class QueueDdevOperationAction
{
    public static function queue(DdevOperationRequest $request): DdevOperationState
    {
        $state = app(DdevState::class);

        $existing = $state->operation($request->projectName);

        // Never stack a second command on a project that is already mid-flight.
        if ($existing?->isPending()) {
            return $existing;
        }

        $queued = DdevOperationState::queued($request->projectName, $request->operation);

        $state->putOperation($queued);

        RunDdevOperationJob::dispatch($request);

        return $queued;
    }
}

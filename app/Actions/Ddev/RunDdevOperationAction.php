<?php

namespace App\Actions\Ddev;

use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\Enums\DdevOperationStatus;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use App\Support\Ddev\DdevTranscript;
use Throwable;

/**
 * Executes a lifecycle command. Blocking by design: this only ever runs inside
 * the queue worker, never in a request.
 *
 * It runs on its own queue, so a command that takes minutes, or one that never
 * returns at all, cannot hold up the `ddev list` refreshes the popup reads.
 */
final class RunDdevOperationAction
{
    public static function run(DdevOperationRequest $request): void
    {
        $state = app(DdevState::class);

        $operation = $state->operation($request->projectName)
            ?? DdevOperationState::queued($request->projectName, $request->operation, $state->snapshot());

        $state->putOperation($operation->running());

        try {
            $result = app(DdevCli::class)->run(
                $request->operation->arguments($request->projectName),
                $request->operation->timeout(),
                // Recorded line by line as ddev prints it, for the log file
                // and for the popup's own panel: this is the command that can
                // sit on a hook for good, and a transcript that only appears
                // once the process exits would never appear at all.
                DdevTranscript::for($request->projectName),
            );

            $status = $result->successful ? DdevOperationStatus::Succeeded : DdevOperationStatus::Failed;
            $message = $result->summaryLine();
        } catch (Throwable $exception) {
            $status = DdevOperationStatus::Failed;
            $message = $exception->getMessage();
        }

        // A refresh may have settled this already, from a snapshot showing the
        // project where the operation was taking it. Reporting a timeout over
        // that, minutes after the row said the project started, would be the
        // less true of the two answers.
        if ($state->operation($request->projectName)?->isPending()) {
            $state->putOperation($operation->settled($status, $message));
        }

        // The project's status has changed either way, and on failure the
        // refreshed list is what tells the user where it actually ended up.
        RefreshDdevProjectsAction::refresh();
    }
}

<?php

namespace App\Actions\Ddev;

use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\Enums\DdevOperationStatus;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use Throwable;

/**
 * Executes a lifecycle command. Blocking by design: this only ever runs inside
 * the queue worker, never in a request.
 */
final class RunDdevOperationAction
{
    public static function run(DdevOperationRequest $request): void
    {
        $state = app(DdevState::class);

        $operation = $state->operation($request->projectName)
            ?? DdevOperationState::queued($request->projectName, $request->operation);

        $state->putOperation($operation->running());

        try {
            $result = app(DdevCli::class)->run(
                $request->operation->arguments($request->projectName),
                $request->operation->timeout(),
            );

            $state->putOperation($operation->settled(
                $result->successful ? DdevOperationStatus::Succeeded : DdevOperationStatus::Failed,
                $result->summaryLine(),
            ));
        } catch (Throwable $exception) {
            $state->putOperation($operation->settled(
                DdevOperationStatus::Failed,
                $exception->getMessage(),
            ));
        }

        // The project's status has changed either way, and on failure the
        // refreshed list is what tells the user where it actually ended up.
        RefreshDdevProjectsAction::refresh();
    }
}

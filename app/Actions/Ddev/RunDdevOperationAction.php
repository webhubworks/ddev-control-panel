<?php

namespace App\Actions\Ddev;

use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\DataTransferObjects\DdevProject;
use App\Enums\DdevOperationStatus;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use RuntimeException;
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
                self::workingDirectory($request),
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
        // A launcher changes nothing, so it is not worth a five second scan.
        if ($request->operation->changesProjectState()) {
            RefreshDdevProjectsAction::refresh();
        }
    }

    /**
     * Where to run ddev. Null means the default (the home directory).
     *
     * Host commands such as `ddev tableplus` carry no project name and refuse
     * to run outside a project, so they are scoped by cwd instead. The approot
     * comes from the snapshot rather than from the caller: it must not be
     * something the popup can hand us.
     */
    private static function workingDirectory(DdevOperationRequest $request): ?string
    {
        if (! $request->operation->runsInProjectDirectory()) {
            return null;
        }

        $appRoot = app(DdevState::class)->snapshot()
            ?->projects
            ->first(fn (DdevProject $project): bool => $project->name === $request->projectName)
            ?->appRoot;

        if (blank($appRoot) || ! is_dir($appRoot)) {
            throw new RuntimeException(
                "The project directory for {$request->projectName} could not be found."
            );
        }

        return $appRoot;
    }
}

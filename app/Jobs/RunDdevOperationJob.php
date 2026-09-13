<?php

namespace App\Jobs;

use App\Actions\Ddev\RefreshDdevProjectsAction;
use App\Actions\Ddev\RunDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\Enums\DdevOperationStatus;
use App\Support\Ddev\DdevState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunDdevOperationJob implements ShouldQueue
{
    use Queueable;

    /**
     * The queue this job runs on, which has a worker to itself.
     *
     * A lifecycle command takes seconds at best and can block for good at
     * worst: a `post-start` hook that starts a dev server through `exec`
     * rather than `web_extra_daemons` keeps `ddev start` attached to it
     * forever. Sharing the default queue would put every `ddev list` refresh
     * behind that, freezing the list the popup reads as well as the one row.
     */
    public const QUEUE = 'operations';

    /**
     * ddev serialises access to Docker itself; retrying a half-applied start
     * or delete would only confuse things further.
     */
    public int $tries = 1;

    /**
     * Long enough for the slowest operation plus the follow-up `ddev list`.
     *
     * **The worker has to allow at least as much** (`nativephp.queue_workers`),
     * or the job never gets to reach this: in local, NativePHP runs the worker
     * as `queue:listen`, which caps each job's process at its own `--timeout`
     * and kills it there regardless of what the job asks for.
     */
    public const TIMEOUT = 1200;

    public int $timeout = self::TIMEOUT;

    public function __construct(private readonly DdevOperationRequest $request)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        RunDdevOperationAction::run($this->request);
    }

    /**
     * The job died without settling its own operation.
     *
     * A killed worker is the way that happens: the ddev process goes with it,
     * so nothing writes a result and nothing reaches the target state either.
     * Without this the row spins for good, and the project cannot be started
     * again because a pending operation blocks a second command.
     */
    public function failed(?Throwable $exception): void
    {
        $state = app(DdevState::class);
        $operation = $state->operation($this->request->projectName);

        if ($operation?->isPending()) {
            $state->putOperation($operation->settled(
                DdevOperationStatus::Failed,
                $exception?->getMessage() ?? 'The command did not finish.',
            ));
        }

        // Whatever ddev managed before it was cut short, the list has to say so.
        RefreshDdevProjectsAction::refresh();
    }
}

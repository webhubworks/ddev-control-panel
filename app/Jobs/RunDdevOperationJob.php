<?php

namespace App\Jobs;

use App\Actions\Ddev\RunDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunDdevOperationJob implements ShouldQueue
{
    use Queueable;

    /**
     * ddev serialises access to Docker itself; retrying a half-applied start
     * or delete would only confuse things further.
     */
    public int $tries = 1;

    /**
     * Long enough for the slowest operation plus the follow-up `ddev list`.
     */
    public int $timeout = 1200;

    public function __construct(private readonly DdevOperationRequest $request) {}

    public function handle(): void
    {
        RunDdevOperationAction::run($this->request);
    }
}

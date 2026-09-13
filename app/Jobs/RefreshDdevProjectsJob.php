<?php

namespace App\Jobs;

use App\Actions\Ddev\RefreshDdevProjectsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Unique, because the popup can ask for a refresh far faster than `ddev list`
 * can answer and there is no value in queueing the same scan twice.
 */
class RefreshDdevProjectsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * `ddev list` is capped at 180 seconds by DdevCli, and this has to outlast
     * it. The worker's own timeout must too (`nativephp.queue_workers`): in
     * local it is the one that actually kills the job.
     */
    public const TIMEOUT = 300;

    public int $timeout = self::TIMEOUT;

    public int $uniqueFor = 300;

    public function handle(): void
    {
        RefreshDdevProjectsAction::refresh();
    }
}

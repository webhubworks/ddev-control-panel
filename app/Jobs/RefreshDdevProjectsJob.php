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

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function handle(): void
    {
        RefreshDdevProjectsAction::refresh();
    }
}

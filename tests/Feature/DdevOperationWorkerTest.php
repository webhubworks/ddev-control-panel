<?php

use App\Actions\Ddev\QueueDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\Enums\DdevOperation;
use App\Enums\DdevOperationStatus;
use App\Jobs\RefreshDdevProjectsJob;
use App\Jobs\RunDdevOperationJob;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    Process::fake();
    Queue::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));
    $this->state = app(DdevState::class);

    $this->mock(DdevCli::class, fn (MockInterface $mock) => $mock->shouldReceive('listProjects')->andReturn([
        ['name' => 'example', 'status' => 'stopped', 'status_desc' => 'stopped', 'approot' => '/reps/example'],
    ]));
});

it('keeps lifecycle commands off the queue the refreshes run on', function () {
    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Start));

    // A command that never returns must not take the `ddev list` refreshes
    // down with it, or the whole list stops updating and not just the row.
    Queue::assertPushed(
        RunDdevOperationJob::class,
        fn (RunDdevOperationJob $job): bool => $job->queue === RunDdevOperationJob::QUEUE,
    );

    expect(config('nativephp.queue_workers'))->toHaveKey(RunDdevOperationJob::QUEUE);
});

it('gives each worker long enough for the slowest command on its queue', function () {
    // In local NativePHP runs the worker as `queue:listen`, which caps each
    // job's process at the worker's own timeout and kills it there, whatever
    // the job asked for. Too low and `ddev start` dies mid-build with no error
    // of its own.
    $workers = config('nativephp.queue_workers');

    expect($workers[RunDdevOperationJob::QUEUE]['timeout'])
        ->toBeGreaterThanOrEqual(RunDdevOperationJob::TIMEOUT)
        ->toBeGreaterThan(DdevOperation::Start->timeout())
        ->and($workers['default']['timeout'])->toBeGreaterThanOrEqual(RefreshDdevProjectsJob::TIMEOUT);
});

it('settles an operation whose job died without finishing', function () {
    // A killed worker takes the ddev process with it, so nothing writes a
    // result and nothing reaches the target state either. Left alone the row
    // spins for good, and a pending operation blocks a second command, so the
    // project cannot even be started again.
    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Start));

    (new RunDdevOperationJob(new DdevOperationRequest('example', DdevOperation::Start)))
        ->failed(new RuntimeException('The process "queue:work --once" exceeded the timeout of 60 seconds.'));

    $operation = $this->state->operation('example');

    expect($operation?->status)->toBe(DdevOperationStatus::Failed)
        ->and($operation->message)->toContain('exceeded the timeout')
        ->and($this->state->hasPendingOperations())->toBeFalse();
});

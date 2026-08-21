<?php

use App\Actions\Ddev\QueueDdevOperationAction;
use App\Actions\Ddev\RunDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\Enums\DdevOperation;
use App\Enums\DdevOperationStatus;
use App\Exceptions\DdevBinaryNotFoundException;
use App\Jobs\RunDdevOperationJob;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    // Safety net: no test in this file may ever shell out to the real ddev and
    // touch this machine's actual projects.
    Process::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));
    $this->state = app(DdevState::class);
});

it('queues the command instead of running it in the request', function () {
    Queue::fake();

    $operation = QueueDdevOperationAction::queue(
        new DdevOperationRequest('example', DdevOperation::Start)
    );

    expect($operation->status)->toBe(DdevOperationStatus::Queued);

    // Running ddev inline would freeze the single-worker PHP server for the
    // whole duration of `ddev start`.
    Queue::assertPushed(RunDdevOperationJob::class, 1);

    expect($this->state->operation('example')?->status)->toBe(DdevOperationStatus::Queued)
        ->and($this->state->hasPendingOperations())->toBeTrue();
});

it('refuses to stack a second command on a busy project', function () {
    Queue::fake();

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Start));
    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Stop));

    Queue::assertPushed(RunDdevOperationJob::class, 1);

    expect($this->state->operation('example')?->operation)->toBe(DdevOperation::Start);
});

it('still tracks a different project independently', function () {
    Queue::fake();

    QueueDdevOperationAction::queue(new DdevOperationRequest('alpha', DdevOperation::Start));
    QueueDdevOperationAction::queue(new DdevOperationRequest('beta', DdevOperation::Stop));

    Queue::assertPushed(RunDdevOperationJob::class, 2);

    expect($this->state->operations())->toHaveCount(2);
});

it('records success and refreshes the project list afterwards', function () {
    Process::fake([
        '*' => Process::result('{"raw":[{"name":"example","status":"running","status_desc":"running"}]}'),
    ]);

    RunDdevOperationAction::run(new DdevOperationRequest('example', DdevOperation::Start));

    $operation = $this->state->operation('example');

    expect($operation?->status)->toBe(DdevOperationStatus::Succeeded)
        ->and($operation->isPending())->toBeFalse();

    // The status has changed, so the cached list must not be left stale.
    expect($this->state->snapshot()?->projects->first()?->name)->toBe('example');
});

it('records the failure reason so the popup can show it', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: 'Failed to start example: docker daemon is not running',
            exitCode: 1,
        ),
    ]);

    RunDdevOperationAction::run(new DdevOperationRequest('example', DdevOperation::Start));

    $operation = $this->state->operation('example');

    expect($operation?->status)->toBe(DdevOperationStatus::Failed)
        ->and($operation->failed())->toBeTrue()
        ->and($operation->message)->toContain('docker daemon is not running');
});

it('surfaces a missing ddev binary without wedging the ui', function () {
    // ddev not being installed must not leave the popup spinning forever, and
    // the reason has to reach the snapshot so the error banner can show it.
    $this->mock(DdevCli::class, function (MockInterface $mock) {
        $mock->shouldReceive('run')
            ->andThrow(DdevBinaryNotFoundException::afterSearching(['/opt/homebrew/bin/ddev']));

        $mock->shouldReceive('listProjects')
            ->andThrow(DdevBinaryNotFoundException::afterSearching(['/opt/homebrew/bin/ddev']));
    });

    RunDdevOperationAction::run(new DdevOperationRequest('example', DdevOperation::Stop));

    expect($this->state->isRefreshing())->toBeFalse()
        ->and($this->state->operation('example')?->status)->toBe(DdevOperationStatus::Failed)
        ->and($this->state->snapshot()?->failed())->toBeTrue()
        ->and($this->state->snapshot()?->error)->toContain('/opt/homebrew/bin/ddev');
});

it('drops a settled operation once it has been on screen long enough', function () {
    Queue::fake();

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Stop));

    $queued = $this->state->operation('example');
    $this->state->putOperation($queued->settled(DdevOperationStatus::Succeeded, 'done'));

    expect($this->state->operations())->toHaveCount(1);

    $this->travel(60)->seconds();

    expect($this->state->operations())->toHaveCount(0)
        ->and($this->state->hasPendingOperations())->toBeFalse();
});

it('keeps a pending operation no matter how long it takes', function () {
    Queue::fake();

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Start));

    // A cold container pull can genuinely run for many minutes.
    $this->travel(15)->minutes();

    expect($this->state->hasPendingOperations())->toBeTrue();
});

it('survives an operation left in the cache by an older build', function () {
    // The cache outlives the code. A row for an operation this build no longer
    // knows must not throw out of the popup's first render.
    Cache::forever('ddev.operations', [
        'example' => [
            'project_name' => 'example',
            'operation' => 'tableplus',
            'status' => 'succeeded',
            'started_at' => now()->getTimestamp(),
        ],
    ]);

    expect($this->state->operations())->toBeEmpty()
        ->and($this->state->operation('example'))->toBeNull();

    // And it is gone from the cache for good, not written back with the next
    // operation that touches it.
    $this->state->putOperation(DdevOperationState::queued('other', DdevOperation::Start));

    expect(Cache::get('ddev.operations'))->not->toHaveKey('example');
});

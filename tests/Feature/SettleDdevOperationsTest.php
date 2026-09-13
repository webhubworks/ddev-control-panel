<?php

use App\Actions\Ddev\QueueDdevOperationAction;
use App\Actions\Ddev\RefreshDdevProjectsAction;
use App\Actions\Ddev\RunDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\Enums\DdevOperation;
use App\Enums\DdevOperationStatus;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use App\Support\Docker\DockerBinary;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    // Safety net: nothing in this file may reach the real ddev, the real
    // Docker daemon, or this machine's actual projects.
    Process::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));
    $this->app->instance(DockerBinary::class, new DockerBinary('/usr/bin/true'));

    $this->state = app(DdevState::class);
});

/**
 * Replace `ddev list` with a fixed answer for one project and refresh, which
 * is the only thing that settles an operation from the snapshot side. A null
 * status is a project ddev no longer lists at all.
 */
function listReturns(?string $status): void
{
    test()->mock(DdevCli::class, fn (MockInterface $mock) => $mock->shouldReceive('listProjects')->andReturn(
        $status === null ? [] : [
            ['name' => 'example', 'status' => $status, 'status_desc' => $status, 'approot' => '/reps/example'],
        ]
    ));

    RefreshDdevProjectsAction::refresh();
}

it('settles a start once the list shows the project running', function () {
    Queue::fake();

    // The case this exists for: a `post-start` hook that starts a dev server
    // through `exec` rather than `web_extra_daemons` keeps `ddev start`
    // attached to it forever, so the process never reports anything. Without
    // this the row reads "Starting" until the operation's own timeout, long
    // after the project came up and started serving.
    listReturns('stopped');

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Start));

    expect($this->state->operation('example')?->isPending())->toBeTrue();

    listReturns('running');

    expect($this->state->operation('example')?->status)->toBe(DdevOperationStatus::Succeeded)
        ->and($this->state->hasPendingOperations())->toBeFalse();
});

it('shows no banner for a start the list settled', function () {
    Queue::fake();

    listReturns('stopped');

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Start));

    listReturns('running');

    // A start that worked says so by the project being up. The row's message
    // area is for something that needs reading.
    expect($this->state->operation('example')?->message)->toBeNull();
});

it('waits for a restart to take the project down before believing the list', function () {
    Queue::fake();

    listReturns('running');

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Restart));

    // A restart ends where it began, so a project that is still running says
    // nothing about whether ddev has touched it yet.
    listReturns('running');

    expect($this->state->operation('example')?->isPending())->toBeTrue();

    listReturns('stopped');

    expect($this->state->operation('example')?->isPending())->toBeTrue();

    listReturns('running');

    expect($this->state->operation('example')?->status)->toBe(DdevOperationStatus::Succeeded);
});

it('settles a delete once the project is gone from the list', function () {
    Queue::fake();

    listReturns('running');

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Delete));

    listReturns(null);

    expect($this->state->operation('example')?->status)->toBe(DdevOperationStatus::Succeeded);
});

it('leaves an operation the list cannot account for pending', function () {
    Queue::fake();

    listReturns('running');

    QueueDdevOperationAction::queue(new DdevOperationRequest('example', DdevOperation::Stop));

    // Still running: the stop has not happened, whatever else is going on.
    listReturns('running');

    expect($this->state->operation('example')?->isPending())->toBeTrue();
});

it('does not reopen an operation the list already settled', function () {
    // The process result arrives minutes later for a command stuck on a hook,
    // and by then it is a timeout for a project that is plainly running.
    // Reporting that over the row's "started" would be the less true answer.
    $this->mock(DdevCli::class, function (MockInterface $mock) {
        $mock->shouldReceive('run')->andReturnUsing(function () {
            app(DdevState::class)->putOperation(
                app(DdevState::class)->operation('example')->settled(DdevOperationStatus::Succeeded)
            );

            throw new RuntimeException('The process "ddev start example" exceeded the timeout of 900 seconds.');
        });

        $mock->shouldReceive('listProjects')->andReturn([
            ['name' => 'example', 'status' => 'running', 'status_desc' => 'running', 'approot' => '/reps/example'],
        ]);
    });

    RunDdevOperationAction::run(new DdevOperationRequest('example', DdevOperation::Start));

    expect($this->state->operation('example')?->status)->toBe(DdevOperationStatus::Succeeded)
        ->and($this->state->operation('example')?->message)->toBeNull();
});

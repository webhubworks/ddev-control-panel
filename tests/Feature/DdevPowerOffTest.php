<?php

use App\Actions\Ddev\RunDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\Enums\DdevOperation;
use App\Enums\DdevOperationStatus;
use App\Jobs\RunDdevOperationJob;
use App\Livewire\DdevProjectList;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevState;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Process::fake();
    Queue::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));

    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha-site', 'status' => 'running', 'status_desc' => 'running', 'type' => 'laravel', 'primary_url' => 'https://alpha-site.ddev.site', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
        ['name' => 'beta-api', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'php', 'approot' => '/reps/beta-api', 'shortroot' => '~/reps/beta-api'],
    ]);
});

it('asks before stopping everything', function () {
    Livewire::test(DdevProjectList::class)
        ->call('powerOff')
        ->assertSet('confirmingPowerOff', true)
        ->assertSee('Stop all')
        ->assertSee('router and ssh-agent')
        ->assertSee('No data is removed');

    Queue::assertNotPushed(RunDdevOperationJob::class);
});

it('queues the poweroff on the second click', function () {
    Livewire::test(DdevProjectList::class)
        ->call('powerOff')
        ->call('powerOff')
        ->assertSet('confirmingPowerOff', false);

    Queue::assertPushed(RunDdevOperationJob::class, 1);

    // Tracked under the reserved target, so it cannot collide with a project.
    $operation = app(DdevState::class)->operation(DdevOperationRequest::GLOBAL_TARGET);

    expect($operation?->operation)->toBe(DdevOperation::Poweroff)
        ->and($operation->status)->toBe(DdevOperationStatus::Queued);
});

it('lets the user back out', function () {
    Livewire::test(DdevProjectList::class)
        ->call('powerOff')
        ->call('cancelPowerOff')
        ->assertSet('confirmingPowerOff', false);

    Queue::assertNotPushed(RunDdevOperationJob::class);
});

it('cannot be triggered from a project row', function () {
    // runOperation is the row entry point and must refuse a machine wide command
    // regardless of the project name it is handed.
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'poweroff');

    Queue::assertNotPushed(RunDdevOperationJob::class);
});

it('stays available even when the snapshot says nothing is running', function () {
    // The count can be seconds out of date, and this is the button someone
    // reaches for precisely when ddev is in a state the list does not reflect.
    app(DdevState::class)->putSnapshot([
        ['name' => 'beta-api', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'php', 'approot' => '/reps/beta-api', 'shortroot' => '~/reps/beta-api'],
    ]);

    $response = $this->get('/')->assertOk();

    expect($response->content())->toContain('aria-label="Stop all projects"');

    Livewire::test(DdevProjectList::class)
        ->call('powerOff')
        ->call('powerOff');

    Queue::assertPushed(RunDdevOperationJob::class, 1);
});

it('runs ddev poweroff and refreshes afterwards', function () {
    Process::fake([
        '*' => Process::result('{"raw":[{"name":"alpha-site","status":"stopped","status_desc":"stopped"}]}'),
    ]);

    RunDdevOperationAction::run(DdevOperationRequest::global(DdevOperation::Poweroff));

    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/true', 'poweroff']);

    $state = app(DdevState::class);

    expect($state->operation(DdevOperationRequest::GLOBAL_TARGET)?->status)
        ->toBe(DdevOperationStatus::Succeeded)
        // The list must reflect that everything came down.
        ->and($state->snapshot()?->projects->first()?->status->isRunning())->toBeFalse();
});

it('keeps the poweroff banner separate from the project rows', function () {
    Livewire::test(DdevProjectList::class)
        ->call('powerOff')
        ->call('powerOff')
        // The reserved target must never render as if it were a project.
        ->assertSee('Stopping all projects')
        ->assertDontSee('<li wire:key="project-*"', escape: false);
});

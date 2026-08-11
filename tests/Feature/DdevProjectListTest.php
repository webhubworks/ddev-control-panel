<?php

use App\Enums\DdevOperation;
use App\Jobs\RefreshDdevProjectsJob;
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
        ['name' => 'zeta-shop', 'status' => 'running', 'status_desc' => 'running', 'type' => 'craftcms', 'primary_url' => 'https://zeta-shop.ddev.site', 'approot' => '/reps/zeta-shop', 'shortroot' => '~/reps/zeta-shop'],
        ['name' => 'alpha-site', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'laravel', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
        ['name' => 'beta-api', 'status' => 'unhealthy', 'status_desc' => 'db: stopped', 'type' => 'php', 'approot' => '/reps/beta-api', 'shortroot' => '~/reps/beta-api'],
    ]);
});

it('lists every project with its ddev status', function () {
    Livewire::test(DdevProjectList::class)
        ->assertSee('zeta-shop')
        ->assertSee('alpha-site')
        ->assertSee('beta-api')
        // The exact strings ddev itself would print.
        ->assertSee('OK')
        ->assertSee('stopped')
        ->assertSee('db: stopped')
        ->assertSee('1 / 3 running');
});

it('puts running projects first despite the alphabet', function () {
    $names = Livewire::test(DdevProjectList::class)
        ->instance()
        ->projects()
        ->pluck('name')
        ->all();

    expect($names)->toBe(['zeta-shop', 'beta-api', 'alpha-site']);
});

it('filters by name', function () {
    Livewire::test(DdevProjectList::class)
        ->set('search', 'alpha')
        ->assertSee('alpha-site')
        ->assertDontSee('zeta-shop');
});

it('filters by project type', function () {
    Livewire::test(DdevProjectList::class)
        ->set('search', 'craftcms')
        ->assertSee('zeta-shop')
        ->assertDontSee('alpha-site');
});

it('can show only running projects', function () {
    Livewire::test(DdevProjectList::class)
        ->set('runningOnly', true)
        ->assertSee('zeta-shop')
        ->assertDontSee('alpha-site')
        ->call('clearFilters')
        ->assertSee('alpha-site');
});

it('queues a start for a stopped project', function () {
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'start');

    Queue::assertPushed(RunDdevOperationJob::class, 1);

    expect(app(DdevState::class)->operation('alpha-site')?->operation)
        ->toBe(DdevOperation::Start);
});

it('requires a second click before deleting', function () {
    $component = Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'delete')
        ->assertSet('confirmingDeleteFor', 'alpha-site')
        // Nothing destructive may happen on the first click.
        ->assertSee('Your code is untouched');

    Queue::assertNotPushed(RunDdevOperationJob::class);

    $component->call('runOperation', 'alpha-site', 'delete')
        ->assertSet('confirmingDeleteFor', null);

    Queue::assertPushed(RunDdevOperationJob::class, 1);
});

it('lets the user back out of a delete', function () {
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'delete')
        ->call('cancelDelete')
        ->assertSet('confirmingDeleteFor', null);

    Queue::assertNotPushed(RunDdevOperationJob::class);
});

it('ignores a project name it does not know', function () {
    // The name reaches a command line, so an unknown one must never get there.
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'example; rm -rf /', 'start');

    Queue::assertNotPushed(RunDdevOperationJob::class);
});

it('ignores an operation that is not one of ours', function () {
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'poweroff');

    Queue::assertNotPushed(RunDdevOperationJob::class);
});

it('asks for a refresh when the snapshot has gone stale', function () {
    Livewire::test(DdevProjectList::class);

    // Fresh snapshot from beforeEach, so mounting must not trigger a scan.
    Queue::assertNotPushed(RefreshDdevProjectsJob::class);

    $this->travel(config('ddev.snapshot_ttl') + 5)->seconds();

    Livewire::test(DdevProjectList::class);

    Queue::assertPushed(RefreshDdevProjectsJob::class, 1);
});

it('refreshes on demand regardless of staleness', function () {
    Livewire::test(DdevProjectList::class)->call('refreshProjects');

    Queue::assertPushed(RefreshDdevProjectsJob::class, 1);
});

it('explains itself when ddev could not be reached', function () {
    app(DdevState::class)->putSnapshot([], 'Cannot connect to the Docker daemon');

    Livewire::test(DdevProjectList::class)
        ->assertSee('ddev could not be reached')
        ->assertSee('Cannot connect to the Docker daemon')
        ->assertSee('Check that Docker is running');
});

it('offers an empty state when no project matches the filters', function () {
    Livewire::test(DdevProjectList::class)
        ->set('search', 'nothing-matches-this')
        ->assertSee('No projects match your filters');
});

it('offers a way forward when there are no projects at all', function () {
    app(DdevState::class)->putSnapshot([]);

    Livewire::test(DdevProjectList::class)
        ->assertSee('No ddev projects found')
        ->assertSee('ddev config');
});

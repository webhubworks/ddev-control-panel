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
use Native\Desktop\Facades\Shell;

beforeEach(function () {
    Process::fake();
    Queue::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));

    app(DdevState::class)->putSnapshot([
        ['name' => 'zeta-shop', 'status' => 'running', 'status_desc' => 'running', 'type' => 'craftcms', 'primary_url' => 'https://zeta-shop.ddev.site', 'mailpit_https_url' => 'https://zeta-shop.ddev.site:8026', 'database_host_port' => 55148, 'database_container_port' => 3306, 'approot' => '/reps/zeta-shop', 'shortroot' => '~/reps/zeta-shop'],
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

it('offers site, database and mail on a running project', function () {
    Livewire::test(DdevProjectList::class)
        ->set('runningOnly', true)
        ->assertSee('Open site')
        ->assertSee('Open database')
        ->assertSee('Open mail');
});

/**
 * A project whose `.ddev` config adds a second host. The extra hostname is
 * folded into the row by the refresh, because `ddev list` does not report it.
 */
function snapshotWithAdditionalHostnames(): void
{
    app(DdevState::class)->putSnapshot([
        ['name' => 'mesh', 'status' => 'running', 'status_desc' => 'running', 'type' => 'laravel', 'primary_url' => 'https://mesh.ddev.site', 'additional_hostnames' => ['timehub-mesh'], 'approot' => '/reps/mesh', 'shortroot' => '~/reps/mesh'],
    ]);
}

it('names every host when a project answers on more than one', function () {
    snapshotWithAdditionalHostnames();

    // "Open site" cannot say which site, so a project with additional
    // hostnames lists them by host instead.
    Livewire::test(DdevProjectList::class)
        ->assertSee('mesh.ddev.site')
        ->assertSee('timehub-mesh.ddev.site')
        ->assertDontSee('Open site');
});

it('opens the host that was picked, not just the primary one', function () {
    snapshotWithAdditionalHostnames();

    Shell::shouldReceive('openExternal')
        ->once()
        ->with('https://timehub-mesh.ddev.site');

    Livewire::test(DdevProjectList::class)
        ->call('openUrl', 'mesh', 'https://timehub-mesh.ddev.site');

    Process::assertNothingRan();
});

it('falls back to the primary url rather than opening a url it does not know', function () {
    // The url arrives from the browser and ends up at Shell::openExternal, so
    // it is only ever opened when the snapshot itself lists it.
    snapshotWithAdditionalHostnames();

    Shell::shouldReceive('openExternal')
        ->once()
        ->with('https://mesh.ddev.site');

    Livewire::test(DdevProjectList::class)
        ->call('openUrl', 'mesh', 'https://example.com/phishing');
});

it('does nothing for a url on a project it has never heard of', function () {
    Shell::shouldReceive('openExternal')->never();

    Livewire::test(DdevProjectList::class)->call('openUrl', 'not-a-project', 'https://example.com');
});

it('opens the mailpit inbox straight from the snapshot', function () {
    // `ddev list` already reported the URL, so this must not cost a ddev call.
    Shell::shouldReceive('openExternal')
        ->once()
        ->with('https://zeta-shop.ddev.site:8026');

    Livewire::test(DdevProjectList::class)->call('openMailpit', 'zeta-shop');

    Process::assertNothingRan();
});

it('does nothing for a project that reports no mailpit url', function () {
    Shell::shouldReceive('openExternal')->never();

    Livewire::test(DdevProjectList::class)->call('openMailpit', 'alpha-site');
});

it('opens the database straight from the snapshot', function () {
    // The URL `ddev tableplus` would build, from the published port the refresh
    // already read off Docker. Running the command would cost a second of ddev
    // startup and a queue round trip to arrive at the same address.
    Shell::shouldReceive('openExternal')
        ->once()
        ->with('mysql://db:db@127.0.0.1:55148/db?Enviroment=local&Name=ddev-zeta-shop');

    Livewire::test(DdevProjectList::class)->call('openDatabase', 'zeta-shop');

    Process::assertNothingRan();
    Queue::assertNothingPushed();
});

it('does nothing for a project whose database container is not running', function () {
    Shell::shouldReceive('openExternal')->never();

    Livewire::test(DdevProjectList::class)->call('openDatabase', 'alpha-site');
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

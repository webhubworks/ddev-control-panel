<?php

use App\Actions\Ddev\RefreshDdevProjectsAction;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use App\Support\Docker\DockerBinary;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

beforeEach(function () {
    // The refresh asks Docker for the database ports. No test in this file may
    // reach the real daemon or this machine's actual containers.
    Process::fake();

    $this->app->instance(DockerBinary::class, new DockerBinary('/usr/bin/true'));
});

it('folds each project\'s extra hosts into the snapshot', function () {
    // `ddev list` reports only primary_url. The rest of the hosts are read off
    // disk here, once per refresh, so that a poll stays a plain cache read.
    $appRoot = sys_get_temp_dir().'/ddev-refresh-'.bin2hex(random_bytes(6));

    mkdir($appRoot.'/.ddev', recursive: true);
    file_put_contents($appRoot.'/.ddev/config.yaml', "name: mesh\nadditional_hostnames:\n  - timehub-mesh\n");

    $this->mock(DdevCli::class, fn (MockInterface $mock) => $mock->shouldReceive('listProjects')->andReturn([
        [
            'name' => 'mesh',
            'status' => 'running',
            'status_desc' => 'running',
            'type' => 'laravel',
            'primary_url' => 'https://mesh.ddev.site',
            'approot' => $appRoot,
            'shortroot' => '~/reps/mesh',
        ],
    ]));

    RefreshDdevProjectsAction::refresh();

    expect(app(DdevState::class)->snapshot()?->projects->first()?->siteUrls)->toBe([
        'mesh.ddev.site' => 'https://mesh.ddev.site',
        'timehub-mesh.ddev.site' => 'https://timehub-mesh.ddev.site',
    ]);
});

it('folds each project\'s published database port into the snapshot', function () {
    // `ddev list` reports no database at all, and `ddev describe`, which does,
    // costs about a second per project. One `docker ps` answers for all of them.
    Process::fake([
        '*' => Process::result("mesh 127.0.0.1:55148->3306/tcp\n"),
    ]);

    $this->mock(DdevCli::class, fn (MockInterface $mock) => $mock->shouldReceive('listProjects')->andReturn([
        ['name' => 'mesh', 'status' => 'running', 'status_desc' => 'running', 'approot' => '/reps/mesh'],
    ]));

    RefreshDdevProjectsAction::refresh();

    expect(app(DdevState::class)->snapshot()?->projects->first()?->databaseUrl)
        ->toBe('mysql://db:db@127.0.0.1:55148/db?Enviroment=local&Name=ddev-mesh');
});

it('leaves a project without a running database container with no url', function () {
    // A stopped project publishes nothing, and neither does one configured
    // without a database. The menu entry is disabled rather than failing.
    $this->mock(DdevCli::class, fn (MockInterface $mock) => $mock->shouldReceive('listProjects')->andReturn([
        ['name' => 'mesh', 'status' => 'stopped', 'status_desc' => 'stopped', 'approot' => '/reps/mesh'],
    ]));

    RefreshDdevProjectsAction::refresh();

    expect(app(DdevState::class)->snapshot()?->projects->first()?->databaseUrl)->toBeNull();
});

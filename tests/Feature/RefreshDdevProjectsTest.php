<?php

use App\Actions\Ddev\RefreshDdevProjectsAction;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use Mockery\MockInterface;

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

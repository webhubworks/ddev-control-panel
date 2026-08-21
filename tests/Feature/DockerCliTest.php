<?php

use App\Exceptions\DockerBinaryNotFoundException;
use App\Support\Docker\DockerBinary;
use App\Support\Docker\DockerCli;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

beforeEach(function () {
    Process::fake();

    $this->app->instance(DockerBinary::class, new DockerBinary('/usr/bin/true'));
});

it('reads the published database port of every ddev project at once', function () {
    Process::fake([
        '*' => Process::result(implode("\n", [
            'atlas 127.0.0.1:55057->3306/tcp',
            'mesh 127.0.0.1:55148->3306/tcp',
            'ledger 127.0.0.1:55201->5432/tcp',
        ])),
    ]);

    expect(app(DockerCli::class)->databasePorts())->toBe([
        'atlas' => ['host_port' => 55057, 'container_port' => 3306],
        'mesh' => ['host_port' => 55148, 'container_port' => 3306],
        'ledger' => ['host_port' => 55201, 'container_port' => 5432],
    ]);
});

it('ignores the throwaway containers ddev exec leaves in the listing', function () {
    // A one-off container carries the project's labels and publishes nothing,
    // which is what tells it apart without filtering on a compose label value.
    Process::fake([
        '*' => Process::result("mesh \nmesh 127.0.0.1:55148->3306/tcp"),
    ]);

    expect(app(DockerCli::class)->databasePorts())
        ->toBe(['mesh' => ['host_port' => 55148, 'container_port' => 3306]]);
});

it('reads the port once when docker publishes it on both stacks', function () {
    // bind_all_interfaces publishes the same port on IPv4 and IPv6.
    Process::fake([
        '*' => Process::result('mesh 0.0.0.0:55148->3306/tcp, [::]:55148->3306/tcp'),
    ]);

    expect(app(DockerCli::class)->databasePorts())
        ->toBe(['mesh' => ['host_port' => 55148, 'container_port' => 3306]]);
});

it('reports no ports rather than failing when docker cannot answer', function () {
    // Docker not running costs the menu its database entry. The list itself
    // fails on `ddev list` long before this matters.
    Process::fake([
        '*' => Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1),
    ]);

    expect(app(DockerCli::class)->databasePorts())->toBe([]);
});

it('survives docker not being installed at all', function () {
    // The refresh calls this before it builds the snapshot, so an unlocatable
    // docker has to cost the menu its database entry and nothing else.
    $this->mock(DockerBinary::class, fn (MockInterface $mock) => $mock->shouldReceive('path')
        ->andThrow(DockerBinaryNotFoundException::afterSearching(['/usr/local/bin/docker'])));

    expect(app(DockerCli::class)->databasePorts())->toBe([]);
});

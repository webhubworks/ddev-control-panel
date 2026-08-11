<?php

use App\DataTransferObjects\DdevProject;
use App\Enums\DdevProjectStatus;

/**
 * The row shape here is a verbatim entry from `ddev list --json-output` on
 * ddev v1.25.3, so these tests pin the contract we actually parse.
 */
function listRow(array $overrides = []): array
{
    return [
        'approot' => '/Users/dev/reps/example',
        'docroot' => 'web',
        'httpsurl' => 'https://example.ddev.site',
        'httpurl' => 'http://example.ddev.site',
        'mutagen_enabled' => false,
        'name' => 'example',
        'primary_url' => 'https://example.ddev.site',
        'router' => 'traefik',
        'shortroot' => '~/reps/example',
        'status' => 'stopped',
        'status_desc' => 'stopped',
        'type' => 'craftcms',
        ...$overrides,
    ];
}

it('parses a stopped project', function () {
    $project = DdevProject::fromListRow(listRow());

    expect($project->name)->toBe('example')
        ->and($project->status)->toBe(DdevProjectStatus::Stopped)
        ->and($project->type)->toBe('craftcms')
        ->and($project->shortRoot)->toBe('~/reps/example')
        ->and($project->statusLabel())->toBe('stopped')
        ->and($project->statusTone())->toBe('neutral');
});

it('hides the url unless the project is running', function () {
    expect(DdevProject::fromListRow(listRow())->primaryUrl)->toBeNull();

    $running = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
    ]));

    expect($running->primaryUrl)->toBe('https://example.ddev.site');
});

it('renders a healthy project as OK, the way ddev does', function () {
    $project = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
    ]));

    expect($project->statusLabel())->toBe('OK')
        ->and($project->statusTone())->toBe('positive');
});

it('flattens a multi service status onto one line', function () {
    // ddev reports a partial project by listing only the services that differ
    // from the web container, newline separated.
    $project = DdevProject::fromListRow(listRow([
        'status' => 'unhealthy',
        'status_desc' => "db: stopped\nrouter: exited",
    ]));

    expect($project->statusLabel())->toBe('db: stopped, router: exited')
        ->and($project->statusTone())->toBe('warning');
});

it('appends the mutagen status to a running project', function () {
    $project = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
        'mutagen_enabled' => true,
        'mutagen_status' => 'ok',
    ]));

    expect($project->statusLabel())->toBe('running (ok)');
});

it('treats broken projects as negative', function (string $statusDescription) {
    $project = DdevProject::fromListRow(listRow([
        'status' => $statusDescription,
        'status_desc' => $statusDescription,
    ]));

    expect($project->statusTone())->toBe('negative');
})->with([
    'project directory missing',
    '.ddev/config.yaml missing',
    'unhealthy',
]);

it('degrades an unrecognised docker health string instead of failing', function () {
    // ddev can pass a raw Docker health value straight through.
    $project = DdevProject::fromListRow(listRow([
        'status' => 'restarting',
        'status_desc' => 'restarting',
    ]));

    expect($project->status)->toBe(DdevProjectStatus::Unknown)
        ->and($project->statusLabel())->toBe('restarting');
});

it('knows which lifecycle commands apply to a status', function () {
    expect(DdevProjectStatus::Running->canStart())->toBeFalse()
        ->and(DdevProjectStatus::Running->canStop())->toBeTrue()
        ->and(DdevProjectStatus::Stopped->canStart())->toBeTrue()
        ->and(DdevProjectStatus::Stopped->canStop())->toBeFalse()
        // Nothing can be done to a project whose config or directory is gone.
        ->and(DdevProjectStatus::ConfigMissing->canStart())->toBeFalse()
        ->and(DdevProjectStatus::ConfigMissing->canStop())->toBeFalse()
        ->and(DdevProjectStatus::ConfigMissing->canRestart())->toBeFalse();
});

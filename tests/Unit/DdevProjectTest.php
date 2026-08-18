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
        'mailpit_https_url' => 'https://example.ddev.site:8026',
        'mailpit_url' => 'http://example.ddev.site:8025',
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

it('carries the mailpit url so the popup never has to run ddev launch', function () {
    expect(DdevProject::fromListRow(listRow())->mailpitUrl)->toBeNull();

    $running = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
    ]));

    // https is what `ddev launch -m` prefers, so the popup opens the same one.
    expect($running->mailpitUrl)->toBe('https://example.ddev.site:8026');
});

it('falls back to the plain mailpit url when the router serves no https', function () {
    $row = listRow(['status' => 'running', 'status_desc' => 'running']);
    unset($row['mailpit_https_url']);

    expect(DdevProject::fromListRow($row)->mailpitUrl)->toBe('http://example.ddev.site:8025');
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

it('offers only the primary url when the project has no additional hosts', function () {
    $project = DdevProject::fromListRow(listRow(['status' => 'running', 'status_desc' => 'running']));

    expect($project->siteUrls)->toBe(['example.ddev.site' => 'https://example.ddev.site']);
});

it('builds a url per additional hostname, under the project tld', function () {
    // What mesh does: the timehub surface answers on its own host rather than
    // on a path under mesh's, and `ddev list` reports neither of them.
    $project = DdevProject::fromListRow(listRow([
        'name' => 'mesh',
        'status' => 'running',
        'status_desc' => 'running',
        'primary_url' => 'https://mesh.ddev.site',
        'additional_hostnames' => ['timehub-mesh'],
    ]));

    expect($project->siteUrls)->toBe([
        'mesh.ddev.site' => 'https://mesh.ddev.site',
        'timehub-mesh.ddev.site' => 'https://timehub-mesh.ddev.site',
    ]);
});

it('takes an additional fqdn as it stands', function () {
    $project = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
        'additional_fqdns' => ['example.test'],
    ]));

    expect($project->siteUrls)->toBe([
        'example.ddev.site' => 'https://example.ddev.site',
        'example.test' => 'https://example.test',
    ]);
});

it('carries the primary scheme and port onto every extra host', function () {
    $project = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
        'primary_url' => 'http://example.ddev.site:8080',
        'additional_hostnames' => ['admin'],
        'additional_fqdns' => ['example.test'],
    ]));

    expect($project->siteUrls)->toBe([
        'example.ddev.site:8080' => 'http://example.ddev.site:8080',
        'admin.ddev.site:8080' => 'http://admin.ddev.site:8080',
        'example.test:8080' => 'http://example.test:8080',
    ]);
});

it('drops additional hostnames when the router is not in the picture', function () {
    // With `router_disabled` ddev publishes ports on 127.0.0.1 and there is no
    // tld for an extra label to hang off, so offering one would be a dead link.
    $project = DdevProject::fromListRow(listRow([
        'status' => 'running',
        'status_desc' => 'running',
        'primary_url' => 'https://127.0.0.1:55210',
        'additional_hostnames' => ['timehub-mesh'],
        'additional_fqdns' => ['example.test'],
    ]));

    expect($project->siteUrls)->toBe([
        '127.0.0.1:55210' => 'https://127.0.0.1:55210',
        'example.test:55210' => 'https://example.test:55210',
    ]);
});

it('offers no url at all while the project is stopped', function () {
    $project = DdevProject::fromListRow(listRow(['additional_hostnames' => ['timehub-mesh']]));

    expect($project->siteUrls)->toBe([]);
});

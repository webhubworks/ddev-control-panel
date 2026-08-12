<?php

use App\DataTransferObjects\DockerEvent;

/**
 * @param  array<string, string>  $attributes
 */
function dockerEventLine(string $action, array $attributes = [], string $type = 'container'): string
{
    return (string) json_encode([
        'Type' => $type,
        'Action' => $action,
        'Actor' => [
            'ID' => str_repeat('a', 64),
            'Attributes' => [
                'com.ddev.platform' => 'ddev',
                'com.ddev.site-name' => 'example',
                'name' => 'ddev-example-web',
                ...$attributes,
            ],
        ],
        'scope' => 'local',
        'time' => 1786517578,
    ]);
}

it('reads the project name off ddev\'s container label', function () {
    $event = DockerEvent::fromJsonLine(dockerEventLine('start'));

    expect($event?->action)->toBe('start')
        ->and($event?->projectName)->toBe('example')
        ->and($event?->isRelevant())->toBeTrue();
});

it('treats every lifecycle action as worth a refresh', function (string $action) {
    expect(DockerEvent::fromJsonLine(dockerEventLine($action))?->isRelevant())->toBeTrue();
})->with(['create', 'start', 'restart', 'pause', 'unpause', 'kill', 'stop', 'die', 'destroy']);

it('keeps health results, because a project only reaches OK once healthchecks pass', function () {
    // ddev prints `running` until the healthchecks pass and `OK` afterwards, and
    // the only thing that reports the difference is this event.
    $event = DockerEvent::fromJsonLine(dockerEventLine('health_status: healthy'));

    expect($event?->action)->toBe('health_status')
        ->and($event?->isRelevant())->toBeTrue();
});

it('ignores the healthcheck exec chatter', function (string $action) {
    // Every container runs its healthcheck through docker exec every 30
    // seconds. Left in, these alone would keep `ddev list` running forever.
    expect(DockerEvent::fromJsonLine(dockerEventLine($action))?->isRelevant())->toBeFalse();
})->with([
    'exec_create: /bin/sh -c /healthcheck.sh',
    'exec_start: /bin/sh -c /healthcheck.sh',
    'exec_die',
]);

it('ignores the throwaway containers ddev exec and ddev composer create', function () {
    // These fire a full create / start / die / destroy sequence while the
    // project's status never changes.
    $byLabel = DockerEvent::fromJsonLine(dockerEventLine('start', [
        'com.docker.compose.oneoff' => 'True',
    ]));

    $byName = DockerEvent::fromJsonLine(dockerEventLine('start', [
        'name' => 'ddev-example-web-run-01a0a62d12c7',
    ]));

    expect($byLabel?->oneOff)->toBeTrue()
        ->and($byLabel?->isRelevant())->toBeFalse()
        ->and($byName?->oneOff)->toBeTrue()
        ->and($byName?->isRelevant())->toBeFalse();
});

it('has no project name for ddev\'s shared containers', function () {
    // The router and ssh-agent carry the ddev labels with an empty site name.
    $event = DockerEvent::fromJsonLine(dockerEventLine('start', [
        'com.ddev.site-name' => '',
        'name' => 'ddev-router',
    ]));

    expect($event?->projectName)->toBeNull()
        // Still relevant: the router being down changes what every project's
        // URL is worth.
        ->and($event?->isRelevant())->toBeTrue();
});

it('skips anything that is not a readable container event', function (string $line) {
    expect(DockerEvent::fromJsonLine($line))->toBeNull();
})->with([
    'empty' => '',
    'not json' => 'Cannot connect to the Docker daemon',
    'half a line' => '{"Type":"container","Act',
    'a network event' => fn (): string => dockerEventLine('connect', type: 'network'),
    'no action' => fn (): string => dockerEventLine(''),
]);

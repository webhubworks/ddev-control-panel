<?php

use App\DataTransferObjects\DockerEvent;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevState;
use App\Support\Docker\DockerBinary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

beforeEach(function () {
    // Safety net: no test in this file may shell out to the real docker or ddev
    // and touch this machine's actual projects.
    Process::fake();

    // A real path so the locator resolves without needing docker installed.
    $this->dockerPath = '/usr/bin/true';

    $this->app->instance(DockerBinary::class, new DockerBinary($this->dockerPath));

    // Short, so the suite is not paced by the real debounce, but long enough
    // that consecutive events still count as one burst.
    config(['ddev.watch.debounce' => 150]);

    /**
     * A refresh means one `ddev list`, and the test queue runs inline, so
     * counting calls counts refreshes.
     */
    $this->expectScans = fn (int $times): mixed => $this->mock(
        DdevCli::class,
        fn (MockInterface $mock) => $mock->shouldReceive('listProjects')->times($times)->andReturn([]),
    );

    /**
     * Feed the watcher a scripted event stream, followed by enough idle ticks
     * for the debounce to expire while the stream is still open.
     *
     * @param  list<string>  $lines
     */
    $this->stream = function (array $lines): void {
        Process::fake([
            '*' => Process::describe()
                ->output($lines)
                // One iteration per line drains the stream; the rest supply the
                // quiet the debounce waits for, and the ticks the watcher wants
                // before it trusts the stream enough to resync.
                ->runsFor(iterations: count($lines) + 8),
        ]);

        $this->artisan('ddev:watch --once')->assertSuccessful();
    };
});

/**
 * @param  array<string, string>  $attributes
 */
function eventLine(string $action, array $attributes = []): string
{
    return (string) json_encode([
        'Type' => 'container',
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
        'time' => 1786517578,
    ]);
}

it('asks docker only for the ddev containers and only for lifecycle events', function () {
    ($this->expectScans)(1);

    ($this->stream)([]);

    Process::assertRan(function ($process): bool {
        // The label is what makes Docker usable as a ddev event source at all:
        // without it this would be every container on the machine.
        $filters = ['label=com.ddev.platform=ddev'];

        foreach (DockerEvent::RELEVANT_ACTIONS as $action) {
            $filters[] = 'event='.$action;
        }

        $expected = [$this->dockerPath, 'events'];

        foreach ($filters as $filter) {
            $expected[] = '--filter';
            $expected[] = $filter;
        }

        // Machine readable, not the table `docker events` prints by default.
        $expected[] = '--format';
        $expected[] = '{{json .}}';

        return $process->command === $expected;
    });
});

it('scans once as soon as it attaches', function () {
    // Docker only reports what happens from now on, so whatever changed while
    // the watcher was not listening has to be picked up on attach: the app was
    // not running, or Docker itself restarted.
    ($this->expectScans)(1);

    ($this->stream)([]);
});

it('does not scan when the stream dies on arrival', function () {
    // `docker events` exits immediately while Docker is not running, and the
    // watcher retries every few seconds for as long as that lasts. Resyncing on
    // each attempt would mean a failing `ddev list` on a loop.
    ($this->expectScans)(0);

    Process::fake(['*' => Process::describe()->output([])->runsFor(iterations: 1)]);

    $this->artisan('ddev:watch --once')->assertSuccessful();
});

it('scans again when a project changes state', function () {
    // The attach resync, then the event.
    ($this->expectScans)(2);

    ($this->stream)([eventLine('start')]);
});

it('coalesces the burst a single ddev start produces into one scan', function () {
    // `ddev list` takes seconds on a machine with dozens of projects; running it
    // per container event would keep the queue busy for minutes over one start.
    ($this->expectScans)(2);

    ($this->stream)([
        eventLine('create'),
        eventLine('start'),
        eventLine('start', ['com.docker.compose.service' => 'db']),
        eventLine('health_status: healthy'),
    ]);
});

it('ignores events that cannot have changed a project\'s status', function () {
    // Only the attach resync.
    ($this->expectScans)(1);

    ($this->stream)([
        eventLine('exec_create: /bin/sh -c /healthcheck.sh'),
        eventLine('exec_die'),
        eventLine('start', ['name' => 'ddev-example-web-run-01a0a62d12c7']),
        'Cannot connect to the Docker daemon',
    ]);
});

it('does not pile a scan on top of one already running', function () {
    // The scan runs on the queue, out of the watcher's sight, so the state flag
    // is the only signal that one is in flight.
    $this->app->instance(DdevState::class, new class(Cache::store()) extends DdevState
    {
        public function isRefreshing(): bool
        {
            return true;
        }
    });

    // Only the attach resync: the event stays pending instead of stacking a
    // second scan behind the first.
    ($this->expectScans)(1);

    ($this->stream)([eventLine('start')]);
});

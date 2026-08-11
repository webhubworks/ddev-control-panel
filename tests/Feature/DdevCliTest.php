<?php

use App\Exceptions\DdevBinaryNotFoundException;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevCommandFailedException;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // A real path so the locator resolves without needing ddev installed.
    $this->binaryPath = '/usr/bin/true';

    $this->app->instance(DdevBinary::class, new DdevBinary($this->binaryPath));
});

it('finds the ddev binary at a configured path', function () {
    expect((new DdevBinary($this->binaryPath))->path())->toBe($this->binaryPath)
        ->and((new DdevBinary($this->binaryPath))->isAvailable())->toBeTrue();
});

it('falls back to the known install locations when the configured path is wrong', function () {
    // A stale DDEV_BINARY_PATH must not break an otherwise working install, so
    // detection continues past it rather than giving up.
    $binary = new DdevBinary('/nope/ddev');

    if (! is_executable('/opt/homebrew/bin/ddev') && ! is_executable('/usr/local/bin/ddev')) {
        expect($binary->isAvailable())->toBeFalse();

        return;
    }

    expect($binary->path())->not->toBe('/nope/ddev')
        ->and($binary->isAvailable())->toBeTrue();
});

it('names the searched locations when ddev cannot be found', function () {
    // The message is the only diagnostic a user gets, so it has to list where
    // we looked.
    $exception = DdevBinaryNotFoundException::afterSearching([
        '/opt/homebrew/bin/ddev',
        '/usr/local/bin/ddev',
    ]);

    expect($exception->getMessage())
        ->toContain('/opt/homebrew/bin/ddev')
        ->toContain('/usr/local/bin/ddev')
        ->toContain('DDEV_BINARY_PATH');
});

it('extracts the raw payload from ddev log-wrapped json output', function () {
    // `ddev list -j` emits newline delimited log records; only one carries data.
    Process::fake([
        '*' => Process::result(implode("\n", [
            '{"level":"info","msg":"some banner","time":"2026-08-11T09:00:00+02:00"}',
            json_encode([
                'level' => 'info',
                'msg' => 'a rendered ascii table nobody wants to parse',
                'raw' => [
                    ['name' => 'alpha', 'status' => 'running', 'status_desc' => 'running'],
                    ['name' => 'beta', 'status' => 'stopped', 'status_desc' => 'stopped'],
                ],
                'time' => '2026-08-11T09:00:00+02:00',
            ]),
        ])),
    ]);

    $rows = app(DdevCli::class)->listProjects();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('alpha')
        ->and($rows[1]['name'])->toBe('beta');
});

it('asks ddev for json and never lets it prompt', function () {
    Process::fake(['*' => Process::result('{"raw":[]}')]);

    app(DdevCli::class)->listProjects();

    Process::assertRan(function ($process) {
        return $process->command === [$this->binaryPath, 'list', '--json-output']
            // Without this ddev can block forever waiting on a confirmation
            // or a sudo password that no GUI user can answer.
            && ($process->environment['DDEV_NONINTERACTIVE'] ?? null) === 'true';
    });
});

it('returns no projects when ddev reports none', function () {
    Process::fake(['*' => Process::result('{"level":"info","msg":"No projects found"}')]);

    expect(app(DdevCli::class)->listProjects())->toBe([]);
});

it('raises a failure carrying ddev output when the command fails', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: 'Failed to connect to the Docker daemon',
            exitCode: 1,
        ),
    ]);

    expect(fn () => app(DdevCli::class)->listProjects())
        ->toThrow(DdevCommandFailedException::class, 'Failed to connect to the Docker daemon');
});

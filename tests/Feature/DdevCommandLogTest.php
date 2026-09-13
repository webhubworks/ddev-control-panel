<?php

use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevCli;
use App\Support\Ddev\DdevCommandLog;
use App\Support\Ddev\DdevTranscript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

beforeEach(function () {
    Process::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));

    // Keep the channel, drop its file handler: these tests read the records
    // rather than writing a log into storage.
    $this->handler = new TestHandler;

    Log::channel('ddev')->getLogger()->setHandlers([$this->handler]);

    $this->transcript = fn (): string => collect($this->handler->getRecords())
        ->map(fn (LogRecord $record): string => $record->message)
        ->implode("\n");
});

it('records the command line and how it ended', function () {
    Process::fake([
        '*' => Process::result(output: 'Successfully started example', exitCode: 0),
    ]);

    app(DdevCli::class)->run(['start', '--skip-confirmation', 'example']);

    expect(($this->transcript)())
        ->toContain('$ ddev start --skip-confirmation example')
        ->toContain('ddev start --skip-confirmation example exited 0 after');
});

it('records what a failed command printed', function () {
    // The popup has room for one line of this. Everything ddev said about a
    // failure has to survive somewhere it can be read afterwards.
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: "Failed to start example\ndocker daemon is not running",
            exitCode: 1,
        ),
    ]);

    app(DdevCli::class)->run(['start', 'example']);

    expect(($this->transcript)())
        ->toContain('exited 1 after')
        ->toContain('docker daemon is not running');
});

it('streams a command line by line while it runs', function () {
    // A command stuck on a post-start hook never exits, so a transcript
    // written after the process would never be written at all. The timestamp
    // on the last line is what says where it stopped.
    $log = new DdevCommandLog(['start', 'example'], DdevTranscript::for('example'));
    $sink = $log->sink();

    $sink('out', "Starting example...\n");
    $sink('out', "\e[32mContainer ddev-example-web  Started\e[0m\n");

    // Chunks break wherever the pipe happened to break, so a line can arrive
    // in two of them.
    $sink('out', 'Running post-start hook: ');
    $sink('out', "npm run serve\n");

    expect(($this->transcript)())
        ->toContain('Starting example...')
        // ANSI colour and the spinner's carriage returns read as nothing in a
        // log file, so they are stripped rather than stored.
        ->toContain('Container ddev-example-web  Started')
        ->not->toContain("\e[32m")
        ->toContain('Running post-start hook: npm run serve');
});

it('streams a lifecycle command rather than holding its output', function () {
    // `ddev list` is the one command worth buffering: it prints a JSON blob on
    // every refresh and would flush the interesting runs out of the log.
    expect((new DdevCommandLog(['start', 'example'], DdevTranscript::for('example')))->sink())->not->toBeNull()
        ->and((new DdevCommandLog(['list', '--json-output']))->sink())->toBeNull();
});

it('records a command that produced no result at all', function () {
    // A missing binary, or a process the timeout cut short. Without this the
    // transcript would simply stop, which reads like the command is still
    // running.
    (new DdevCommandLog(['start', 'example'], DdevTranscript::for('example')))
        ->abort('The process "ddev start example" exceeded the timeout of 900 seconds.');

    expect(($this->transcript)())
        ->toContain('ddev start example aborted after')
        ->toContain('exceeded the timeout');
});

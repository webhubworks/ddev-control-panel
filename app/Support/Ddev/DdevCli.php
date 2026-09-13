<?php

namespace App\Support\Ddev;

use App\DataTransferObjects\DdevProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Thin wrapper around the ddev executable.
 *
 * Every invocation is written to the `ddev` log channel, which is the only
 * record of what a command printed: the popup has room for one line of it.
 */
class DdevCli
{
    public function __construct(private readonly DdevBinary $binary) {}

    /**
     * Run a ddev command and wait for it.
     *
     * Passing a transcript logs each line as it arrives rather than after the
     * process exits, and puts it where the popup can follow it. That is what
     * makes a command that never returns readable.
     *
     * @param  list<string>  $arguments
     */
    public function run(array $arguments, int $timeout = 300, ?DdevTranscript $transcript = null): DdevProcessResult
    {
        $log = new DdevCommandLog($arguments, $transcript);

        try {
            $result = Process::timeout($timeout)
                // ddev resolves its global config out of the home directory, and a
                // GUI-launched app may start in "/".
                ->path((string) (getenv('HOME') ?: sys_get_temp_dir()))
                ->env([
                    // Without this ddev can block forever on a confirmation prompt
                    // or a sudo password that no one is there to answer.
                    'DDEV_NONINTERACTIVE' => 'true',
                ])
                ->run([$this->binary->path(), ...$arguments], $log->sink());
        } catch (Throwable $exception) {
            // A missing binary, or a timeout that cut the process short. Either
            // way the transcript has to say so, or the log ends mid-command.
            $log->abort($exception->getMessage());

            throw $exception;
        }

        $result = new DdevProcessResult(
            successful: $result->successful(),
            exitCode: $result->exitCode(),
            output: $result->output(),
            errorOutput: $result->errorOutput(),
        );

        $log->finish($result);

        return $result;
    }

    /**
     * Every known project with its current status.
     *
     * This is slow: it inspects containers for each project, which on a machine
     * with dozens of projects takes several seconds. It must never run inside a
     * request that the UI is waiting on.
     *
     * @return list<array<string, mixed>>
     */
    public function listProjects(): array
    {
        return $this->rawJson(['list'], timeout: 180);
    }

    /**
     * Run a command with `--json-output` and return the structured `raw` payload.
     *
     * ddev emits newline-delimited JSON log records, only one of which carries
     * the data, so every line has to be inspected.
     *
     * @param  list<string>  $arguments
     * @return list<array<string, mixed>>
     */
    public function rawJson(array $arguments, int $timeout = 300): array
    {
        $result = $this->run([...$arguments, '--json-output'], $timeout);

        if (! $result->successful) {
            throw new DdevCommandFailedException($result);
        }

        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, associative: true);

            if (is_array($decoded) && isset($decoded['raw']) && is_array($decoded['raw'])) {
                return array_values($decoded['raw']);
            }
        }

        // A valid run with nothing to report (no projects at all).
        return [];
    }
}

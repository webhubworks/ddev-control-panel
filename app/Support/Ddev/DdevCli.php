<?php

namespace App\Support\Ddev;

use App\DataTransferObjects\DdevProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around the ddev executable.
 */
class DdevCli
{
    public function __construct(private readonly DdevBinary $binary) {}

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments, int $timeout = 300): DdevProcessResult
    {
        $result = Process::timeout($timeout)
            // ddev resolves its global config out of the home directory, and a
            // GUI-launched app may start in "/".
            ->path((string) (getenv('HOME') ?: sys_get_temp_dir()))
            ->env([
                // Without this ddev can block forever on a confirmation prompt
                // or a sudo password that no one is there to answer.
                'DDEV_NONINTERACTIVE' => 'true',
            ])
            ->run([$this->binary->path(), ...$arguments]);

        return new DdevProcessResult(
            successful: $result->successful(),
            exitCode: $result->exitCode(),
            output: $result->output(),
            errorOutput: $result->errorOutput(),
        );
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

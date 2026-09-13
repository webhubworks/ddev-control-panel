<?php

namespace App\Support\Ddev;

use App\DataTransferObjects\DdevProcessResult;
use App\Enums\DdevTranscriptLineKind;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * A transcript of one ddev invocation, written to the `ddev` log channel.
 *
 * Every command this app runs is slow, and one of them can block for good: a
 * `post-start` hook that never returns (a dev server started through `exec`
 * rather than `web_extra_daemons`) leaves `ddev start` attached to it forever.
 * Holding the output until the process exits would hide exactly the run
 * someone needs to read, so a streamed command writes each line as it arrives
 * and the timestamp on the last line says where it stopped.
 *
 * A command that is not streamed (`ddev list`, on every refresh) logs its own
 * line plus a result line, and its output only when it fails, so the
 * interesting runs are not flushed out by a JSON blob every few seconds.
 */
final class DdevCommandLog
{
    private readonly LoggerInterface $logger;

    private readonly float $startedAt;

    private string $buffer = '';

    private readonly bool $streaming;

    /**
     * A transcript makes the command a streamed one: its lines are for reading
     * while it runs, so holding them back until it exits would defeat both.
     *
     * @param  list<string>  $arguments
     */
    public function __construct(
        private readonly array $arguments,
        private readonly ?DdevTranscript $transcript = null,
    ) {
        $this->streaming = $transcript !== null;
        $this->logger = Log::channel('ddev');
        $this->startedAt = microtime(true);

        $this->logger->log(
            $this->streaming ? 'info' : 'debug',
            '$ '.$this->commandLine(),
        );

        $this->transcript?->append('$ '.$this->commandLine(), DdevTranscriptLineKind::Command);
    }

    /**
     * The output callback to hand to the process, or null when this command's
     * output is not worth streaming.
     *
     * @return (callable(string, string): void)|null
     */
    public function sink(): ?callable
    {
        if (! $this->streaming) {
            return null;
        }

        return function (string $type, string $chunk): void {
            $this->write($chunk);
        };
    }

    /**
     * Record how the command ended, flushing anything the last chunk left
     * without a trailing newline.
     */
    public function finish(DdevProcessResult $result): void
    {
        $this->flush();

        $line = sprintf(
            '%s exited %d after %s',
            $this->commandLine(),
            $result->exitCode ?? -1,
            $this->elapsed(),
        );

        if ($result->successful) {
            $this->logger->log($this->streaming ? 'info' : 'debug', $line);
            $this->transcript?->append($line, DdevTranscriptLineKind::Result);
            $this->transcript?->flush();

            return;
        }

        $this->logger->error($line);
        $this->transcript?->append($line, DdevTranscriptLineKind::Failure);
        $this->transcript?->flush();

        // A streamed command has already written every line of this.
        if (! $this->streaming && filled($result->combinedOutput())) {
            $this->logger->error(self::clean($result->combinedOutput()));
        }
    }

    /**
     * Record a command that never produced a result at all: a missing binary,
     * or a process the timeout cut short.
     */
    public function abort(string $message): void
    {
        $this->flush();

        $line = sprintf(
            '%s aborted after %s: %s',
            $this->commandLine(),
            $this->elapsed(),
            $message,
        );

        $this->logger->error($line);
        $this->transcript?->append($line, DdevTranscriptLineKind::Failure);
        $this->transcript?->flush();
    }

    /**
     * Buffer a chunk and log every complete line in it. Chunks arrive split
     * wherever the pipe happened to break, so a line can span two of them.
     */
    private function write(string $chunk): void
    {
        $this->buffer .= $chunk;

        while (($break = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $break);
            $this->buffer = substr($this->buffer, $break + 1);

            $this->log($line);
        }
    }

    private function flush(): void
    {
        $remainder = $this->buffer;
        $this->buffer = '';

        $this->log($remainder);
    }

    private function log(string $line): void
    {
        $line = self::clean($line);

        if (filled($line)) {
            $this->logger->info($line);
            $this->transcript?->append($line, DdevTranscriptLineKind::Output);
        }
    }

    private function commandLine(): string
    {
        return 'ddev '.implode(' ', $this->arguments);
    }

    private function elapsed(): string
    {
        return sprintf('%.1fs', microtime(true) - $this->startedAt);
    }

    /**
     * ddev draws progress with ANSI colour and redraws a spinner with carriage
     * returns, neither of which reads as anything in a log file.
     */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $text);

        return trim(Str::of($text)->replace("\r", "\n")->explode("\n")
            ->map(fn (string $line): string => rtrim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n"));
    }
}

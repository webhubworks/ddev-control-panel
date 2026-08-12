<?php

namespace App\Console\Commands;

use App\DataTransferObjects\DockerEvent;
use App\Exceptions\DockerBinaryNotFoundException;
use App\Jobs\RefreshDdevProjectsJob;
use App\Support\Ddev\DdevState;
use App\Support\Docker\DockerEventStream;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the cached project list current by listening to Docker.
 *
 * Runs for the lifetime of the app as a supervised child process (see
 * NativeAppServiceProvider), so that opening the popup shows the machine's
 * actual state instead of whatever `ddev list` last reported.
 */
class WatchDdevEventsCommand extends Command
{
    protected $signature = 'ddev:watch
                            {--once : Attach to Docker once and return when the stream ends, instead of reconnecting}';

    protected $description = 'Refresh the cached project list whenever Docker reports a ddev container changing state';

    /**
     * How long the stream has to stay up before it is trusted enough to be
     * worth a catch-up scan, counted in drain ticks.
     */
    private const TICKS_BEFORE_RESYNC = 4;

    public function handle(DockerEventStream $stream, DdevState $state): int
    {
        do {
            $this->watch($stream, $state);

            if ($this->option('once')) {
                break;
            }

            // Docker being unavailable is not an error worth dying over: the
            // daemon may simply not be running yet, or be restarting.
            sleep(max(1, (int) config('ddev.watch.reconnect_delay')));
        } while (true);

        return self::SUCCESS;
    }

    /**
     * Follow one event stream until it ends, which happens when the Docker
     * daemon stops or restarts.
     */
    private function watch(DockerEventStream $stream, DdevState $state): void
    {
        try {
            $process = $stream->start();
        } catch (DockerBinaryNotFoundException $exception) {
            Log::warning('ddev:watch could not start: '.$exception->getMessage());

            return;
        }

        $debounce = max(0, (int) config('ddev.watch.debounce'));
        $tick = $this->tickMicroseconds($debounce);

        $buffer = '';
        $lastEventAt = null;
        $ticks = 0;

        while ($process->running()) {
            $ticks++;

            $buffer .= $process->latestOutput();

            foreach ($this->takeCompleteLines($buffer) as $line) {
                $event = DockerEvent::fromJsonLine($line);

                if ($event?->isRelevant()) {
                    $lastEventAt = microtime(true);

                    $this->line($event->describe());
                }
            }

            // Docker only reports what happens from now on, so anything that
            // changed while we were not attached has to be picked up once, up
            // front. It waits for the stream to prove itself alive: with Docker
            // not running, `docker events` exits straight away, and resyncing on
            // each attempt would mean a failing `ddev list` every few seconds
            // for as long as Docker stays closed.
            if ($ticks === self::TICKS_BEFORE_RESYNC) {
                $this->requestRefresh('attached to the docker event stream');
            }

            // Only once the events have gone quiet, and never on top of a scan
            // that is already running. An event seen during one keeps the
            // marker set, so it is picked up on a later tick rather than lost.
            if ($lastEventAt !== null && $this->hasGoneQuiet($lastEventAt, $debounce) && ! $state->isRefreshing()) {
                $this->requestRefresh('docker reported a state change');

                $lastEventAt = null;
            }

            usleep($tick);
        }

        Log::info('ddev:watch lost the docker event stream', [
            'error' => trim($process->latestErrorOutput()) ?: null,
        ]);
    }

    /**
     * A single `ddev start` fires dozens of events over several seconds, and
     * `ddev list` costs seconds of its own, so a scan is only worth running
     * once the burst has settled. The clusters within a start are far enough
     * apart that the interesting steps still each get a refresh: containers
     * coming up, then the healthchecks passing some seconds later.
     */
    private function hasGoneQuiet(float $lastEventAt, int $debounce): bool
    {
        return (microtime(true) - $lastEventAt) * 1000 >= $debounce;
    }

    /**
     * How often the stream is drained: often enough to notice a burst ending
     * promptly, rarely enough to be free. Derived from the debounce so it never
     * overshoots it.
     */
    private function tickMicroseconds(int $debounce): int
    {
        return (int) max(50_000, min(200_000, $debounce * 1000 / 3));
    }

    private function requestRefresh(string $reason): void
    {
        RefreshDdevProjectsJob::dispatch();

        $this->line('refreshing: '.$reason);
    }

    /**
     * A chunk read off the process can end mid line, so the remainder stays in
     * the buffer until the rest of it arrives.
     *
     * @return list<string>
     */
    private function takeCompleteLines(string &$buffer): array
    {
        $lines = explode("\n", $buffer);

        $buffer = (string) array_pop($lines);

        return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
    }
}

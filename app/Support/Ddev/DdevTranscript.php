<?php

namespace App\Support\Ddev;

use App\DataTransferObjects\DdevTranscriptLine;
use App\Enums\DdevTranscriptLineKind;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;

/**
 * The tail of what ddev printed for one project, in the cache, so the popup can
 * follow a command while it runs.
 *
 * The durable record is the `ddev` log channel, which keeps every project's
 * commands for a week. This is the slice the UI reads, and it lives in the
 * cache for the same reason the snapshot does: a poll must never cost more
 * than a cache read, and parsing a day of mixed log file on every tick would.
 */
final class DdevTranscript
{
    private const KEY_PREFIX = 'ddev.transcript.';

    /**
     * Lines kept per project, across however many commands produced them. Long
     * enough for a `ddev start` and the run before it, short enough that a
     * chatty hook cannot grow the cache entry without bound.
     */
    private const MAX_LINES = 300;

    private const RETENTION_DAYS = 7;

    /**
     * A running command is written to while it prints, and a hook that logs a
     * request per line would otherwise mean a cache write per line. Lines are
     * held this long instead and written in batches.
     */
    private const FLUSH_AFTER_SECONDS = 0.25;

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    private float $flushedAt = 0;

    private function __construct(
        private readonly string $projectName,
        private readonly Repository $cache,
    ) {}

    public static function for(string $projectName): self
    {
        return new self($projectName, app(Repository::class));
    }

    public function append(string $text, DdevTranscriptLineKind $kind): void
    {
        $this->pending[] = (new DdevTranscriptLine(CarbonImmutable::now(), $text, $kind))->toCache();

        if (microtime(true) - $this->flushedAt >= self::FLUSH_AFTER_SECONDS) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $lines = [...$this->stored(), ...$this->pending];

        $this->pending = [];
        $this->flushedAt = microtime(true);

        $this->cache->put(
            $this->key(),
            array_slice($lines, -self::MAX_LINES),
            now()->addDays(self::RETENTION_DAYS),
        );
    }

    /**
     * @return Collection<int, DdevTranscriptLine>
     */
    public function lines(): Collection
    {
        return collect($this->stored())
            ->map(fn (mixed $payload): ?DdevTranscriptLine => is_array($payload)
                ? DdevTranscriptLine::fromCache($payload)
                : null)
            ->filter()
            ->values();
    }

    public function clear(): void
    {
        $this->pending = [];

        $this->cache->forget($this->key());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        $lines = $this->cache->get($this->key(), []);

        return is_array($lines) ? array_values($lines) : [];
    }

    private function key(): string
    {
        // ddev project names match ^[\w-.]+$ and the poweroff target is "*",
        // neither of which every cache store accepts in a key verbatim.
        return self::KEY_PREFIX.md5($this->projectName);
    }
}

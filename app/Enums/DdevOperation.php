<?php

namespace App\Enums;

/**
 * The ddev commands the popup can run. All but Poweroff act on a single
 * project; Poweroff stops everything at once.
 */
enum DdevOperation: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';
    case Delete = 'delete';
    case Poweroff = 'poweroff';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Start',
            self::Stop => 'Stop',
            self::Restart => 'Restart',
            self::Delete => 'Delete',
            self::Poweroff => 'Stop all',
        };
    }

    /**
     * Present continuous, shown while the operation is in flight.
     */
    public function activeLabel(): string
    {
        return match ($this) {
            self::Start => 'Starting',
            self::Stop => 'Stopping',
            self::Restart => 'Restarting',
            self::Delete => 'Deleting',
            self::Poweroff => 'Stopping all projects',
        };
    }

    /**
     * Acts on every project (and ddev's shared containers) rather than on one,
     * so it takes no project name.
     */
    public function isGlobal(): bool
    {
        return $this === self::Poweroff;
    }

    /**
     * The ddev arguments for this operation.
     *
     * `delete` keeps ddev's default database snapshot, so a mis-click is
     * recoverable via `ddev snapshot restore`.
     *
     * @return list<string>
     */
    public function arguments(string $projectName): array
    {
        return match ($this) {
            self::Start => ['start', '--skip-confirmation', $projectName],
            self::Stop => ['stop', $projectName],
            self::Restart => ['restart', '--skip-confirmation', $projectName],
            self::Delete => ['delete', '--yes', $projectName],
            // `ddev poweroff` takes no arguments at all: it stops every project
            // plus the router and ssh-agent.
            self::Poweroff => ['poweroff'],
        };
    }

    /**
     * Seconds to allow before the operation is treated as hung. Container
     * pulls on a cold cache make `start` and `restart` genuinely slow, and a
     * poweroff has to bring down every running project in turn.
     */
    public function timeout(): int
    {
        return match ($this) {
            self::Start, self::Restart => 900,
            self::Poweroff => 600,
            self::Stop, self::Delete => 300,
        };
    }

    /**
     * Destroys data rather than just changing run state. Poweroff is not
     * destructive: it stops containers and leaves databases alone.
     */
    public function isDestructive(): bool
    {
        return $this === self::Delete;
    }
}

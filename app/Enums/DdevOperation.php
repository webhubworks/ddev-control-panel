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
    case OpenDatabase = 'tableplus';
    case Poweroff = 'poweroff';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Start',
            self::Stop => 'Stop',
            self::Restart => 'Restart',
            self::Delete => 'Delete',
            self::OpenDatabase => 'Open database',
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
            self::OpenDatabase => 'Opening database',
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
     * Scoped to a project by the working directory rather than by a project
     * name on the command line.
     *
     * `ddev tableplus` is not a ddev subcommand: it is one of ddev's *host*
     * commands, a shell script in `~/.ddev/commands/host/`. Those take no
     * project argument and ddev refuses to run them outside a project
     * directory ("Command 'tableplus' cannot be used outside the project
     * directory"), so the approot has to be the cwd.
     */
    public function runsInProjectDirectory(): bool
    {
        return $this === self::OpenDatabase;
    }

    /**
     * Whether the project is in a different state once this has run, and the
     * cached `ddev list` snapshot is therefore stale.
     *
     * A launcher only hands a URL to another application, so following it with
     * a five second `ddev list` would be pure waste.
     */
    public function changesProjectState(): bool
    {
        return $this !== self::OpenDatabase;
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
            // A host command: scoped by the cwd, and it rejects a project name.
            self::OpenDatabase => ['tableplus'],
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
            // Reads the project's config and hands a URL to `open`.
            self::OpenDatabase => 60,
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

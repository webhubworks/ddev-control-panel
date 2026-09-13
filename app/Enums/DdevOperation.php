<?php

namespace App\Enums;

use App\DataTransferObjects\DdevProject;
use App\DataTransferObjects\DdevProjectSnapshot;

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
     * Whether the snapshot shows the project already in the state this
     * operation drives it to.
     *
     * The ddev process exiting is not the only proof an operation finished,
     * and on some projects it never arrives: a `post-start` hook that starts a
     * dev server through `exec` rather than `web_extra_daemons` keeps
     * `ddev start` attached to it for good, long after the containers are up
     * and healthy. So a refresh settles a pending operation whose target state
     * the snapshot reports, and the project stops reading as "Starting" while
     * it is serving.
     *
     * On its own this says nothing about whether the operation did anything:
     * a restart ends where it began. `DdevOperationState::$observedDeparture`
     * is what makes it conclusive.
     */
    public function isSatisfiedBy(DdevProjectSnapshot $snapshot, string $projectName): bool
    {
        $project = $snapshot->projects->first(
            fn (DdevProject $project): bool => $project->name === $projectName
        );

        return match ($this) {
            self::Start, self::Restart => $project?->status->isRunning() ?? false,
            // ddev drops a deleted project from the list entirely, and a
            // stopped one it cannot find is stopped as far as anyone can tell.
            self::Stop => $project === null || $project->status === DdevProjectStatus::Stopped,
            self::Delete => $project === null,
            self::Poweroff => ! $snapshot->projects->contains(
                fn (DdevProject $project): bool => $project->status->isRunning()
            ),
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

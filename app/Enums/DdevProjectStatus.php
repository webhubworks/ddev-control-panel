<?php

namespace App\Enums;

/**
 * Mirrors the Site* status constants returned by ddev's `SiteStatus()`.
 *
 * @see https://github.com/ddev/ddev/blob/master/pkg/ddevapp/ddevapp.go
 */
enum DdevProjectStatus: string
{
    case Running = 'running';
    case Starting = 'starting';
    case Stopped = 'stopped';
    case Paused = 'paused';
    case Unhealthy = 'unhealthy';
    case DirectoryMissing = 'project directory missing';
    case ConfigMissing = '.ddev/config.yaml missing';
    case Unknown = 'unknown';

    /**
     * ddev may return a raw Docker health string (e.g. "exited", "restarting")
     * that is not one of its own constants, so anything unrecognised has to
     * degrade to Unknown rather than blow up the whole list.
     */
    public static function fromDdev(?string $status): self
    {
        return self::tryFrom(trim((string) $status)) ?? self::Unknown;
    }

    public function isRunning(): bool
    {
        return $this === self::Running;
    }

    /**
     * ddev is mid-transition, so the UI should keep polling for a settled state.
     */
    public function isTransitioning(): bool
    {
        return $this === self::Starting;
    }

    /**
     * The project has no usable .ddev config or its directory is gone, so no
     * lifecycle command can succeed against it.
     */
    public function isOrphaned(): bool
    {
        return in_array($this, [self::DirectoryMissing, self::ConfigMissing], strict: true);
    }

    public function canStart(): bool
    {
        return ! $this->isOrphaned() && ! $this->isRunning();
    }

    public function canStop(): bool
    {
        return ! $this->isOrphaned() && $this !== self::Stopped;
    }

    public function canRestart(): bool
    {
        return ! $this->isOrphaned();
    }
}

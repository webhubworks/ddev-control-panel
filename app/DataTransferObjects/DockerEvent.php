<?php

namespace App\DataTransferObjects;

use Illuminate\Support\Str;

/**
 * One container event from `docker events`.
 *
 * ddev has no event bus of its own: it is a one shot CLI with no daemon to
 * subscribe to, and its hooks are per project (`.ddev/config.yaml`), so they
 * would have to be written into every repo on the machine and would still miss
 * anything that did not go through ddev. Docker is the only source that reports
 * every state change, and it is usable here because ddev labels every container
 * it creates with `com.ddev.site-name`.
 */
final readonly class DockerEvent
{
    /**
     * Container actions that can change what `ddev list` reports.
     *
     * `health_status` is in here because a project only moves from `running` to
     * `OK` once its healthchecks pass, which happens seconds after `start`.
     * Everything else Docker emits is noise: each container runs its healthcheck
     * through `docker exec` every 30 seconds, so the `exec_*` actions alone
     * arrive hundreds of times an hour and never mean anything changed.
     *
     * @var list<string>
     */
    public const RELEVANT_ACTIONS = [
        'create',
        'start',
        'restart',
        'pause',
        'unpause',
        'kill',
        'stop',
        'die',
        'destroy',
        'health_status',
    ];

    public function __construct(
        public string $action,
        public ?string $projectName,
        public ?string $containerName,
        public bool $oneOff,
    ) {}

    /**
     * Hydrate from one line of `docker events --format '{{json .}}'`, or null if
     * the line is not a container event we can read.
     *
     * The legacy top level `status` / `id` / `from` keys are gone from the
     * current engine API, so only `Type`, `Action` and `Actor` are read.
     */
    public static function fromJsonLine(string $line): ?self
    {
        $decoded = json_decode(trim($line), associative: true);

        if (! is_array($decoded) || ($decoded['Type'] ?? null) !== 'container') {
            return null;
        }

        $action = (string) ($decoded['Action'] ?? '');

        if ($action === '') {
            return null;
        }

        // Health results arrive as `health_status: healthy`, so the outcome has
        // to come off before the action can be matched.
        $action = trim(Str::before($action, ':'));

        /** @var array<string, string> $attributes */
        $attributes = $decoded['Actor']['Attributes'] ?? [];

        $projectName = trim((string) ($attributes['com.ddev.site-name'] ?? ''));
        $containerName = trim((string) ($attributes['name'] ?? ''));

        return new self(
            action: $action,
            // ddev's own router, ssh-agent and short lived helper containers
            // carry the ddev labels without belonging to a project.
            projectName: $projectName === '' ? null : $projectName,
            containerName: $containerName === '' ? null : $containerName,
            oneOff: self::isOneOff($attributes, $containerName),
        );
    }

    /**
     * Whether this event describes a state change worth a refresh.
     */
    public function isRelevant(): bool
    {
        return ! $this->oneOff && in_array($this->action, self::RELEVANT_ACTIONS, strict: true);
    }

    public function describe(): string
    {
        return $this->action.' '.($this->containerName ?? $this->projectName ?? 'unknown');
    }

    /**
     * `ddev exec`, `ddev composer` and friends run a throwaway container that
     * carries the project's labels, so it fires a full create / start / die /
     * destroy sequence without the project's status ever changing. Compose
     * labels those containers, and also names them `<service>-run-<hash>`;
     * both are checked because a missed one only costs a wasted `ddev list`.
     *
     * @param  array<string, string>  $attributes
     */
    private static function isOneOff(array $attributes, string $containerName): bool
    {
        return strtolower((string) ($attributes['com.docker.compose.oneoff'] ?? '')) === 'true'
            || (bool) preg_match('/-run-[0-9a-f]+$/', $containerName);
    }
}

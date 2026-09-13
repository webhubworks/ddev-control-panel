<?php

namespace App\DataTransferObjects;

use App\Enums\DdevOperation;
use App\Enums\DdevOperationStatus;
use Carbon\CarbonImmutable;

/**
 * Tracks one in-flight (or just-finished) ddev command for a single project.
 */
final readonly class DdevOperationState
{
    public function __construct(
        public string $projectName,
        public DdevOperation $operation,
        public DdevOperationStatus $status,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt = null,
        public ?string $message = null,
        /**
         * Whether the project has been seen away from the state this operation
         * drives it to, which is what lets a later snapshot settle it.
         *
         * Restart is why this exists: it ends in the state it began in, so a
         * snapshot showing the project running proves nothing until the
         * project has been seen going down. Start, stop and delete are asked
         * for precisely because the project is not in the target state, so
         * they carry it from the moment they are queued.
         */
        public bool $observedDeparture = false,
    ) {}

    public static function queued(
        string $projectName,
        DdevOperation $operation,
        ?DdevProjectSnapshot $snapshot = null,
    ): self {
        return new self(
            projectName: $projectName,
            operation: $operation,
            status: DdevOperationStatus::Queued,
            startedAt: CarbonImmutable::now(),
            observedDeparture: $snapshot !== null && ! $operation->isSatisfiedBy($snapshot, $projectName),
        );
    }

    public function running(): self
    {
        return new self(
            projectName: $this->projectName,
            operation: $this->operation,
            status: DdevOperationStatus::Running,
            startedAt: $this->startedAt,
            observedDeparture: $this->observedDeparture,
        );
    }

    /**
     * Records that the project has been seen away from its target state, so
     * the next snapshot reporting that state is proof the operation finished.
     */
    public function departed(): self
    {
        return new self(
            projectName: $this->projectName,
            operation: $this->operation,
            status: $this->status,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            message: $this->message,
            observedDeparture: true,
        );
    }

    public function settled(DdevOperationStatus $status, ?string $message = null): self
    {
        return new self(
            projectName: $this->projectName,
            operation: $this->operation,
            status: $status,
            startedAt: $this->startedAt,
            finishedAt: CarbonImmutable::now(),
            message: $message,
            observedDeparture: $this->observedDeparture,
        );
    }

    /**
     * Null for an entry this build can no longer read.
     *
     * The cache outlives the code: an operation that was in flight, or merely
     * still on screen, when an app version dropped its enum case is still in
     * there on the next start. Rehydrating it strictly would throw out of the
     * component's first render, which is a 500 on the whole popup over a row
     * that was about to expire anyway.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromCache(array $payload): ?self
    {
        $operation = DdevOperation::tryFrom($payload['operation'] ?? '');
        $status = DdevOperationStatus::tryFrom($payload['status'] ?? '');

        if ($operation === null || $status === null || blank($payload['project_name'] ?? null)) {
            return null;
        }

        return new self(
            projectName: $payload['project_name'],
            operation: $operation,
            status: $status,
            startedAt: CarbonImmutable::createFromTimestamp($payload['started_at']),
            finishedAt: isset($payload['finished_at'])
                ? CarbonImmutable::createFromTimestamp($payload['finished_at'])
                : null,
            message: $payload['message'] ?? null,
            observedDeparture: (bool) ($payload['observed_departure'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return [
            'project_name' => $this->projectName,
            'operation' => $this->operation->value,
            'status' => $this->status->value,
            'started_at' => $this->startedAt->getTimestamp(),
            'finished_at' => $this->finishedAt?->getTimestamp(),
            'message' => $this->message,
            'observed_departure' => $this->observedDeparture,
        ];
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function failed(): bool
    {
        return $this->status === DdevOperationStatus::Failed;
    }

    /**
     * Settled results are kept around briefly so the user actually sees the
     * outcome, then they stop cluttering the list.
     */
    public function isExpired(int $keepSettledForSeconds): bool
    {
        if ($this->finishedAt === null) {
            return false;
        }

        return $this->finishedAt->addSeconds($keepSettledForSeconds)->isPast();
    }
}

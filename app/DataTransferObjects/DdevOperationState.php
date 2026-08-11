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
    ) {}

    public static function queued(string $projectName, DdevOperation $operation): self
    {
        return new self(
            projectName: $projectName,
            operation: $operation,
            status: DdevOperationStatus::Queued,
            startedAt: CarbonImmutable::now(),
        );
    }

    public function running(): self
    {
        return new self(
            projectName: $this->projectName,
            operation: $this->operation,
            status: DdevOperationStatus::Running,
            startedAt: $this->startedAt,
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
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCache(array $payload): self
    {
        return new self(
            projectName: $payload['project_name'],
            operation: DdevOperation::from($payload['operation']),
            status: DdevOperationStatus::from($payload['status']),
            startedAt: CarbonImmutable::createFromTimestamp($payload['started_at']),
            finishedAt: isset($payload['finished_at'])
                ? CarbonImmutable::createFromTimestamp($payload['finished_at'])
                : null,
            message: $payload['message'] ?? null,
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

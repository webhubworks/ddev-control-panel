<?php

namespace App\DataTransferObjects;

use App\Enums\DdevOperation;

final readonly class DdevOperationRequest
{
    /**
     * Stand-in project name for a machine wide command such as `ddev poweroff`.
     * ddev project names must match `^[\w-.]+$`, so this can never collide with
     * a real one.
     */
    public const GLOBAL_TARGET = '*';

    public function __construct(
        public string $projectName,
        public DdevOperation $operation,
    ) {}

    public static function global(DdevOperation $operation): self
    {
        return new self(self::GLOBAL_TARGET, $operation);
    }

    public function isGlobal(): bool
    {
        return $this->projectName === self::GLOBAL_TARGET;
    }
}

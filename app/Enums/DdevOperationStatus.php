<?php

namespace App\Enums;

enum DdevOperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The operation has not settled yet, so the UI must keep polling.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Running], strict: true);
    }
}

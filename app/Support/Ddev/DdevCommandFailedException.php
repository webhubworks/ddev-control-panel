<?php

namespace App\Support\Ddev;

use App\DataTransferObjects\DdevProcessResult;
use RuntimeException;

class DdevCommandFailedException extends RuntimeException
{
    public function __construct(public readonly DdevProcessResult $result)
    {
        parent::__construct($result->summaryLine() ?: 'The ddev command failed.', $result->exitCode ?? 1);
    }
}

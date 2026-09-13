<?php

namespace App\Enums;

/**
 * What one line of a project's ddev transcript is, which is all the popup needs
 * to colour it.
 */
enum DdevTranscriptLineKind: string
{
    case Command = 'command';
    case Output = 'output';
    case Result = 'result';
    case Failure = 'failure';

    /**
     * A line this build does not recognise is still a line worth reading, so an
     * unknown kind degrades to plain output rather than being dropped. The
     * transcript cache outlives the code that wrote it.
     */
    public static function fromCache(?string $kind): self
    {
        return self::tryFrom((string) $kind) ?? self::Output;
    }
}

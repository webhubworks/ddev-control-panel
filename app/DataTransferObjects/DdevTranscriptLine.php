<?php

namespace App\DataTransferObjects;

use App\Enums\DdevTranscriptLineKind;
use Carbon\CarbonImmutable;

/**
 * One line of a project's ddev transcript, with the time it was printed.
 *
 * The timestamp is the point of the whole panel: on a command that never
 * returns, the last line and its age are what say where it stopped.
 */
final readonly class DdevTranscriptLine
{
    public function __construct(
        public CarbonImmutable $at,
        public string $text,
        public DdevTranscriptLineKind $kind,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCache(array $payload): ?self
    {
        $text = (string) ($payload['text'] ?? '');

        if ($text === '') {
            return null;
        }

        return new self(
            at: CarbonImmutable::createFromTimestamp($payload['at'] ?? 0),
            text: $text,
            kind: DdevTranscriptLineKind::fromCache($payload['kind'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return [
            'at' => $this->at->getTimestamp(),
            'text' => $this->text,
            'kind' => $this->kind->value,
        ];
    }
}

<?php

namespace App\DataTransferObjects;

use Illuminate\Support\Str;

final readonly class DdevProcessResult
{
    public function __construct(
        public bool $successful,
        public ?int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}

    /**
     * ddev writes progress to stdout and warnings to stderr, so a useful
     * message for the UI needs both.
     */
    public function combinedOutput(): string
    {
        return trim($this->output."\n".$this->errorOutput);
    }

    /**
     * The most relevant line to surface in a popup that has no room for a log.
     */
    public function summaryLine(int $limit = 240): string
    {
        $lines = collect(preg_split('/\R/', $this->combinedOutput()))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        $line = $this->successful
            ? (string) $lines->last()
            : (string) $lines->first(
                fn (string $line): bool => (bool) preg_match('/fail|error|unable|cannot/i', $line),
                $lines->last() ?? ''
            );

        return Str::limit($line, $limit);
    }
}

<?php

namespace App\DataTransferObjects;

use App\Enums\DdevProjectStatus;

final readonly class DdevProject
{
    public function __construct(
        public string $name,
        public DdevProjectStatus $status,
        public string $statusDescription,
        public string $type,
        public ?string $primaryUrl,
        public ?string $mailpitUrl,
        public string $appRoot,
        public string $shortRoot,
        public bool $mutagenEnabled,
        public ?string $mutagenStatus,
    ) {}

    /**
     * Hydrate from one entry of the `raw` array emitted by `ddev list --json-output`.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromListRow(array $row): self
    {
        $status = DdevProjectStatus::fromDdev($row['status'] ?? null);

        return new self(
            name: (string) ($row['name'] ?? ''),
            status: $status,
            statusDescription: trim((string) ($row['status_desc'] ?? '')),
            type: (string) ($row['type'] ?? ''),
            // ddev only treats the URL as meaningful while the router is up.
            primaryUrl: $status->isRunning() ? ($row['primary_url'] ?? null) : null,
            // What `ddev launch -m` opens. It is already in the list output, so
            // the popup opens it directly rather than paying a second of ddev
            // startup for a value it is holding. https when the router can
            // serve it, which is how ddev picks between the two itself.
            mailpitUrl: $status->isRunning()
                ? ($row['mailpit_https_url'] ?? $row['mailpit_url'] ?? null)
                : null,
            appRoot: (string) ($row['approot'] ?? ''),
            shortRoot: (string) ($row['shortroot'] ?? ''),
            mutagenEnabled: (bool) ($row['mutagen_enabled'] ?? false),
            mutagenStatus: $row['mutagen_status'] ?? null,
        );
    }

    /**
     * The status text ddev itself would print in the STATUS column.
     *
     * Replicates `RenderAppRow()` + `FormatSiteStatus()`: the mutagen suffix is
     * appended before the "running" -> "OK" substitution, exactly as ddev does,
     * so what we show matches `ddev list` verbatim.
     */
    public function statusLabel(): string
    {
        $label = $this->statusDescription;

        if ($this->status->isRunning() && $this->mutagenEnabled) {
            $label = sprintf('%s (%s)', $label, $this->mutagenStatus ?? 'not enabled');
        }

        if ($label === DdevProjectStatus::Running->value) {
            $label = 'OK';
        }

        // A multi-service mismatch arrives newline separated ("db: stopped").
        return str_replace("\n", ', ', $label);
    }

    /**
     * Colour bucket for the status pill.
     *
     * This deliberately diverges from ddev's own colouring on one point: ddev
     * prints "stopped" in red, but a stopped project is a normal resting state,
     * and on a machine with dozens of projects a wall of red pills reads as
     * "everything is broken". Stopped is therefore neutral, and red is reserved
     * for states that actually need attention.
     */
    public function statusTone(): string
    {
        $label = $this->statusLabel();

        if ($label === 'OK') {
            return 'positive';
        }

        if ($label === DdevProjectStatus::Stopped->value) {
            return 'neutral';
        }

        $broken = [
            DdevProjectStatus::DirectoryMissing->value,
            DdevProjectStatus::ConfigMissing->value,
            DdevProjectStatus::Unhealthy->value,
            'exited',
        ];

        if (in_array($label, $broken, strict: true)) {
            return 'negative';
        }

        // Paused, starting, or a part-running project such as "db: stopped".
        return 'warning';
    }
}

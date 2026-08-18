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
        /** @var array<string, string> Host (with port, when non-standard) => URL, primary first. */
        public array $siteUrls,
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
        $name = (string) ($row['name'] ?? '');

        // ddev only treats the URL as meaningful while the router is up.
        $primaryUrl = $status->isRunning() ? ($row['primary_url'] ?? null) : null;

        return new self(
            name: $name,
            status: $status,
            statusDescription: trim((string) ($row['status_desc'] ?? '')),
            type: (string) ($row['type'] ?? ''),
            primaryUrl: $primaryUrl,
            // A project can answer on more than one host: `additional_hostnames`
            // and `additional_fqdns` in its `.ddev` config, which the refresh
            // folds into the row because `ddev list` does not report them.
            siteUrls: self::siteUrls(
                $primaryUrl,
                $name,
                $row['additional_hostnames'] ?? [],
                $row['additional_fqdns'] ?? [],
            ),
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
     * Every host the project answers on, keyed by the host as it should be
     * labelled, with the primary URL first.
     *
     * ddev serves an additional hostname as one more label under the project's
     * TLD, on the same scheme and port as the primary URL, so all of that is
     * taken from `primary_url` rather than looked up a second time. When the
     * primary URL is not the project's own host under a TLD, the router is out
     * of the picture (`router_disabled` publishes ports on 127.0.0.1 instead)
     * and the extra hostnames do not resolve to anything, so they are dropped.
     * An additional FQDN is a complete host and is always kept.
     *
     * @param  list<string>|mixed  $hostnames
     * @param  list<string>|mixed  $fqdns
     * @return array<string, string>
     */
    private static function siteUrls(?string $primaryUrl, string $name, mixed $hostnames, mixed $fqdns): array
    {
        if (blank($primaryUrl)) {
            return [];
        }

        $parts = parse_url($primaryUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        $tld = str_starts_with($host, $name.'.') ? substr($host, strlen($name) + 1) : null;

        $extraHosts = [
            ...($tld === null ? [] : array_map(
                fn (string $hostname): string => $hostname.'.'.$tld,
                is_array($hostnames) ? $hostnames : [],
            )),
            ...(is_array($fqdns) ? $fqdns : []),
        ];

        $urls = [$host.$port => $primaryUrl];

        foreach ($extraHosts as $extraHost) {
            $urls[$extraHost.$port] ??= $scheme.'://'.$extraHost.$port;
        }

        return $urls;
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

<?php

namespace App\Support\Ddev;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The extra hosts a project answers on, read straight from its `.ddev` directory.
 *
 * `ddev list --json-output` reports only `primary_url`. The full set of hosts
 * lives in `ddev describe`, which costs about a second per project and is
 * therefore unusable for a list of dozens. The values it derives them from are
 * plain config keys, so we read those instead: a couple of small file reads per
 * project, which is free next to the `ddev list` they accompany.
 */
final readonly class DdevProjectConfig
{
    /**
     * @param  list<string>  $additionalHostnames  Bare labels, to be suffixed with the project TLD.
     * @param  list<string>  $additionalFqdns  Complete host names, used as they stand.
     */
    public function __construct(
        public array $additionalHostnames = [],
        public array $additionalFqdns = [],
    ) {}

    /**
     * ddev reads `config.yaml` and then every `config.*.yaml` beside it, in
     * name order, merging each into what came before. A file that sets
     * `override_config` replaces the keys it declares instead of adding to
     * them, which is the documented way to empty an inherited list.
     */
    public static function read(string $appRoot): self
    {
        $directory = rtrim($appRoot, '/').'/.ddev';

        $hostnames = [];
        $fqdns = [];

        foreach (self::configFiles($directory) as $file) {
            $parsed = self::parse($file);

            if ($parsed === null) {
                continue;
            }

            $override = ($parsed['override_config'] ?? false) === true;

            $hostnames = self::mergeList($hostnames, $parsed['additional_hostnames'] ?? null, $override);
            $fqdns = self::mergeList($fqdns, $parsed['additional_fqdns'] ?? null, $override);
        }

        return new self($hostnames, $fqdns);
    }

    /**
     * @return list<string>
     */
    private static function configFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $extras = glob($directory.'/config.*.y*ml') ?: [];

        sort($extras);

        return array_values(array_filter(
            [$directory.'/config.yaml', ...$extras],
            is_file(...),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parse(string $file): ?array
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (ParseException) {
            // A config we cannot read costs the project its extra hosts, not
            // its row in the list.
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param  list<string>  $carried
     * @return list<string>
     */
    private static function mergeList(array $carried, mixed $incoming, bool $override): array
    {
        if (! is_array($incoming)) {
            return $carried;
        }

        $incoming = array_values(array_filter(
            array_map(fn (mixed $value): string => trim((string) $value), $incoming),
            fn (string $value): bool => $value !== '',
        ));

        return $override ? $incoming : array_values(array_unique([...$carried, ...$incoming]));
    }
}

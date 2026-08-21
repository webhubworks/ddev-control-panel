<?php

namespace App\Support\Docker;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * One shot `docker` calls, as opposed to the long lived event stream.
 *
 * Docker is asked directly because ddev cannot answer cheaply: the published
 * database port is in `ddev describe`, which costs about a second per project,
 * while one `docker ps` reports it for every project at once in well under a
 * tenth of that.
 */
class DockerCli
{
    /**
     * ddev's own compose service name for the database container.
     */
    private const DATABASE_SERVICE = 'db';

    public function __construct(private readonly DockerBinary $binary) {}

    /**
     * The published host port of every running ddev database container, keyed
     * by project name, alongside the container port it maps to.
     *
     * Docker is not asked to exclude the throwaway containers that `ddev exec`
     * and `ddev composer` leave in the listing: they publish no ports, so the
     * parse drops them on its own, which beats filtering on a label value whose
     * spelling is compose's to change.
     *
     * @return array<string, array{host_port: int, container_port: int}>
     */
    public function databasePorts(): array
    {
        try {
            $result = Process::timeout(15)
                // docker resolves its context out of the home directory, and a
                // GUI-launched app may start in "/".
                ->path((string) (getenv('HOME') ?: sys_get_temp_dir()))
                ->run([
                    $this->binary->path(),
                    'ps',
                    '--filter',
                    'label=com.ddev.platform=ddev',
                    '--filter',
                    'label=com.docker.compose.service='.self::DATABASE_SERVICE,
                    '--format',
                    '{{.Label "com.ddev.site-name"}} {{.Ports}}',
                ]);
        } catch (Throwable) {
            // Docker missing or unreachable costs the menu its database entry,
            // not the list its projects.
            return [];
        }

        if (! $result->successful()) {
            return [];
        }

        return self::parse($result->output());
    }

    /**
     * @return array<string, array{host_port: int, container_port: int}>
     */
    private static function parse(string $output): array
    {
        $ports = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$name, $mappings] = array_pad(explode(' ', trim($line), 2), 2, '');

            // A container with nothing published is a throwaway one, and a
            // mapping this app has no use for is not worth guessing at.
            if ($name === '' || ! preg_match('/(\d+)->(3306|5432)\/tcp/', $mappings, $matches)) {
                continue;
            }

            $ports[$name] = [
                'host_port' => (int) $matches[1],
                'container_port' => (int) $matches[2],
            ];
        }

        return $ports;
    }
}

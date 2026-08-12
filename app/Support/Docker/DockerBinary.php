<?php

namespace App\Support\Docker;

use App\Exceptions\DockerBinaryNotFoundException;
use App\Support\BinaryLocator;
use RuntimeException;

/**
 * Locates the `docker` executable.
 *
 * Only needed for the event stream: ddev talks to Docker itself for everything
 * else. The CLI is preferred over the engine socket because it already knows
 * how to find the daemon, which differs between Docker Desktop, OrbStack and
 * Colima and is not something this app should have to work out.
 */
class DockerBinary extends BinaryLocator
{
    protected function executableName(): string
    {
        return 'docker';
    }

    /**
     * Docker Desktop's symlink first, since that is the common case. OrbStack
     * installs over the same symlink, and Colima users get the CLI from
     * Homebrew. The last two are per-user install locations that never make it
     * onto a Finder-launched app's PATH.
     *
     * @return list<string>
     */
    protected function candidates(): array
    {
        $home = (string) getenv('HOME');

        return array_values(array_filter([
            '/usr/local/bin/docker',
            '/opt/homebrew/bin/docker',
            '/Applications/Docker.app/Contents/Resources/bin/docker',
            '/usr/bin/docker',
            $home === '' ? null : $home.'/.docker/bin/docker',
            $home === '' ? null : $home.'/.orbstack/bin/docker',
        ]));
    }

    /**
     * @param  list<string>  $searchedPaths
     */
    protected function notFound(array $searchedPaths): RuntimeException
    {
        return DockerBinaryNotFoundException::afterSearching($searchedPaths);
    }
}

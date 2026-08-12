<?php

namespace App\Support\Ddev;

use App\Exceptions\DdevBinaryNotFoundException;
use App\Support\BinaryLocator;
use RuntimeException;

/**
 * Locates the `ddev` executable.
 */
class DdevBinary extends BinaryLocator
{
    protected function executableName(): string
    {
        return 'ddev';
    }

    /**
     * Where ddev lands for the supported install methods: Homebrew on Apple
     * Silicon, Homebrew on Intel / the install script, and a manual install.
     *
     * @return list<string>
     */
    protected function candidates(): array
    {
        return [
            '/opt/homebrew/bin/ddev',
            '/usr/local/bin/ddev',
            '/usr/bin/ddev',
        ];
    }

    /**
     * @param  list<string>  $searchedPaths
     */
    protected function notFound(array $searchedPaths): RuntimeException
    {
        return DdevBinaryNotFoundException::afterSearching($searchedPaths);
    }
}

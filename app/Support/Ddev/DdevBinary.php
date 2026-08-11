<?php

namespace App\Support\Ddev;

use App\Exceptions\DdevBinaryNotFoundException;

/**
 * Locates the `ddev` executable.
 *
 * A packaged NativePHP app is launched by Finder, not by a login shell, so the
 * PATH it inherits cannot be relied on. Electron's `fix-path` improves this but
 * is not guaranteed, so an explicit search of the known install locations is
 * the only dependable route.
 */
class DdevBinary
{
    /**
     * Where ddev lands for the supported install methods: Homebrew on Apple
     * Silicon, Homebrew on Intel / the install script, and a manual install.
     *
     * @var list<string>
     */
    private const CANDIDATES = [
        '/opt/homebrew/bin/ddev',
        '/usr/local/bin/ddev',
        '/usr/bin/ddev',
    ];

    private ?string $resolved = null;

    public function __construct(private readonly ?string $configuredPath = null) {}

    /**
     * @throws DdevBinaryNotFoundException
     */
    public function path(): string
    {
        return $this->resolved ??= $this->locate();
    }

    public function isAvailable(): bool
    {
        try {
            $this->path();

            return true;
        } catch (DdevBinaryNotFoundException) {
            return false;
        }
    }

    /**
     * @throws DdevBinaryNotFoundException
     */
    private function locate(): string
    {
        $searched = [];

        foreach ($this->searchPaths() as $candidate) {
            $searched[] = $candidate;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw DdevBinaryNotFoundException::afterSearching($searched);
    }

    /**
     * An explicit override wins, then the known install locations, then
     * whatever PATH we did inherit.
     *
     * @return list<string>
     */
    private function searchPaths(): array
    {
        $paths = [];

        if (filled($this->configuredPath)) {
            $paths[] = $this->configuredPath;
        }

        $paths = [...$paths, ...self::CANDIDATES];

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if (blank($directory)) {
                continue;
            }

            $paths[] = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'ddev';
        }

        return array_values(array_unique($paths));
    }
}

<?php

namespace App\Support;

use RuntimeException;

/**
 * Locates an executable this app shells out to.
 *
 * A packaged NativePHP app is launched by Finder, not by a login shell, so the
 * PATH it inherits cannot be relied on. Electron's `fix-path` improves this but
 * is not guaranteed, so an explicit search of the known install locations is
 * the only dependable route.
 */
abstract class BinaryLocator
{
    private ?string $resolved = null;

    public function __construct(private readonly ?string $configuredPath = null) {}

    /**
     * @throws RuntimeException
     */
    public function path(): string
    {
        return $this->resolved ??= $this->resolve() ?? throw $this->notFound($this->searchPaths());
    }

    public function isAvailable(): bool
    {
        return $this->resolved !== null || $this->resolve() !== null;
    }

    /**
     * The file name to look for inside each directory of PATH.
     */
    abstract protected function executableName(): string;

    /**
     * The install locations to try before falling back to PATH.
     *
     * @return list<string>
     */
    abstract protected function candidates(): array;

    /**
     * @param  list<string>  $searchedPaths
     */
    abstract protected function notFound(array $searchedPaths): RuntimeException;

    private function resolve(): ?string
    {
        foreach ($this->searchPaths() as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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

        $paths = [...$paths, ...$this->candidates()];

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if (blank($directory)) {
                continue;
            }

            $paths[] = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->executableName();
        }

        return array_values(array_unique($paths));
    }
}

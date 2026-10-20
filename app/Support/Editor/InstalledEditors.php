<?php

namespace App\Support\Editor;

/**
 * Finds the editors on this machine that a project could be opened in.
 *
 * Looked up by bundle name rather than by asking LaunchServices which apps
 * open folders: almost every app claims that, and a list of forty is no help.
 * Anything not on the list is still reachable through the file dialog.
 */
class InstalledEditors
{
    /**
     * App bundle names worth offering, in the order they are listed.
     */
    private const KNOWN = [
        'PhpStorm',
        'Visual Studio Code',
        'Cursor',
        'Zed',
        'Sublime Text',
        'Nova',
        'Windsurf',
        'IntelliJ IDEA',
        'IntelliJ IDEA Ultimate',
        'WebStorm',
        'Fleet',
        'BBEdit',
        'TextMate',
    ];

    /**
     * @param  list<string>|null  $directories
     */
    public function __construct(private readonly ?array $directories = null) {}

    /**
     * Each editor found, keyed by the path of its app bundle.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $editors = [];

        foreach (self::KNOWN as $name) {
            foreach ($this->directories() as $directory) {
                $path = $directory.'/'.$name.'.app';

                if (is_dir($path)) {
                    $editors[$path] = $name;

                    break;
                }
            }
        }

        return $editors;
    }

    public static function nameOf(string $path): string
    {
        return basename($path, '.app');
    }

    /**
     * JetBrains Toolbox installs per user into `~/Applications`, and Setapp
     * keeps its apps in a folder of its own.
     *
     * @return list<string>
     */
    private function directories(): array
    {
        if ($this->directories !== null) {
            return $this->directories;
        }

        $home = (string) getenv('HOME');

        return array_values(array_filter([
            '/Applications',
            $home === '' ? null : $home.'/Applications',
            '/Applications/Setapp',
        ]));
    }
}

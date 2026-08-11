# DDEV Control Panel

A macOS menu bar app that lists the [ddev](https://ddev.com) projects on your machine and
runs lifecycle commands against them. No dock icon and no main window: the menu bar item is
the only entry point.

Built with [NativePHP](https://nativephp.com) (Laravel, Livewire and Electron).

## What it does

- Lists every project `ddev list` knows about, with its status and primary URL.
- Start, stop, restart and delete a project from its row.
- `ddev poweroff` for everything at once.
- Search, for when the list runs to dozens of projects.

Status text mirrors `ddev list` exactly, including the `running` to `OK` substitution and
the mutagen suffix. Colour deliberately differs: ddev prints `stopped` in red, which reads
as "everything is broken" once you have a few dozen projects, so stopped is neutral here and
red is reserved for states that actually need attention.

Deleting a project never passes `--omit-snapshot`, so a mis-click stays recoverable with
`ddev snapshot restore`.

## Requirements

- macOS
- ddev on the machine (Homebrew or the install script; the path is auto-detected)
- PHP 8.3 or newer, Composer, and Node 22 for development

## Development

This project *drives* ddev, it is not itself a ddev project, so everything runs against the
host toolchain. Electron needs a GUI and the macOS binaries the app shells out to.

Node 22 is required and pinned in `.nvmrc`. Node 24 silently truncates the zip extractions
NativePHP depends on, and every symptom of that looks like an unrelated bug.

```bash
nvm use
composer install
npm install
cp .env.example .env && php artisan key:generate

npm run dev              # separate terminal, for Vite
php artisan native:run   # needs a TTY
```

PHP changes need `native:run` restarted. Only JS and CSS hot-reload through Vite.

Tests:

```bash
php artisan test
```

### Why everything slow goes through the queue

`ddev list` inspects every project's containers and takes seconds on a machine with many
projects. NativePHP serves the app with a single-worker `php -S`, so any blocking call
freezes the whole popup. No ddev command ever runs inside a request: jobs write a snapshot
to the cache, and the Livewire component only ever reads it.

### Icons

```bash
php resources/icons/build-icons.php    # then restart native:run
```

`public/` is the source of truth. `resources/icons/app-icon.png` produces `public/icon.png`,
and `resources/icons/ddev-mark.svg` produces the menu bar template images.

## Building a release

```bash
export GITHUB_TOKEN=$(gh auth token)
php artisan native:build mac --publish
```

Releases are created as drafts on GitHub and have to be published by hand.

## License

MIT.

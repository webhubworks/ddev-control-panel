# DDEV Control

A macOS **menu bar only** app (NativePHP + Electron) that lists the machine's ddev
projects and runs lifecycle commands against them.

Read the **`nativephp`** skill before touching the toolchain. It covers the traps that
present as unrelated bugs (Node 24 truncating extractions, migrations not applying,
`public/hot` shipping an unstyled build, TCC attribution). Only what is specific to
*this* project is written down here.

## Ground rules

- **Node 22, never 24.** `nvm use` (pinned in `.nvmrc`) before any npm or artisan command.
- **No ddev in this repo**, despite the standing webhub rule. Electron needs a GUI and the
  host toolchain. Use the host `php`, `composer` and `npm` directly. (This project *drives*
  ddev; it is not itself a ddev project.)
- `php artisan native:run` **needs a TTY** and cannot be launched from a tool call. Run it
  yourself, ideally as `! php artisan native:run` so the output lands in the conversation.
- **PHP changes require restarting `native:run`.** Only JS and CSS hot-reload through Vite.

## Running it

```bash
nvm use
npm run dev                  # separate terminal, for Vite
php artisan native:run       # needs a TTY
```

There is no dock icon and no main window: the tray item is the only entry point.

## Why everything slow goes through the queue

`ddev list` inspects every project's containers. **On this machine, with 82 projects, it
takes about 5 seconds.** NativePHP serves the app with a single-worker `php -S` (it never
sets `PHP_CLI_SERVER_WORKERS`), so *any* blocking call freezes the entire popup, including
Livewire's own polling.

So no ddev command ever runs inside a request:

- `RefreshDdevProjectsJob` runs `ddev list` and writes a snapshot to the cache.
- `RunDdevOperationJob` runs one lifecycle command and records its state.
- The Livewire component only ever reads the cache, and polls **only while something is in
  flight** (`isBusy()`).

`CACHE_STORE=file` and `SESSION_DRIVER=file` deliberately keep the hot path off SQLite,
which the queue worker is writing to at the same time.

The queue worker is auto-started by NativePHP (`nativephp.queue_workers`) and only when not
running in console. If the popup sits on its loading skeleton forever, the worker is the
first thing to check: look for `ChildProcess\ProcessSpawned` in the log.

## Where the logs and cache actually live

Not in the repo. NativePHP redirects storage to `app.getPath('userData')`:

- **dev:** `~/Library/Application Support/nativephp/` (the published Electron project's
  `package.json` is named `nativephp`, and `extraMetadata` only applies to a packaged build)
- **packaged:** `~/Library/Application Support/DDEV Control/`

The snapshot and operation state are plain cache files under
`<userData>/storage/framework/cache/data`, which is the fastest way to see what the app
believes about ddev.

## ddev parity, and the one place we diverge

Status text is reproduced exactly as `ddev list` prints it, including the `running` -> `OK`
substitution and the mutagen suffix (see `DdevProject::statusLabel()`, which mirrors ddev's
`RenderAppRow()` + `FormatSiteStatus()`).

**Colour deliberately differs:** ddev prints `stopped` in red. With dozens of projects a
wall of red reads as "everything is broken", so stopped is neutral here and red is reserved
for states that need attention (`unhealthy`, `exited`, missing directory or config).

`status_desc` arrives newline separated when services disagree (`"db: stopped"`), and is
flattened onto one line for the row.

## Command flags are pinned by tests

`ddev start` and `ddev restart` take `--skip-confirmation`; `ddev delete` takes `--yes`.
Getting this wrong does not fail loudly, it hangs on a prompt nobody can answer.
`tests/Unit/DdevOperationTest.php` pins the flags, asserts no `--all` ever leaks in, and
asserts `delete` never passes `--omit-snapshot` so a mis-click stays recoverable via
`ddev snapshot restore`.

`DDEV_NONINTERACTIVE=true` is set on every invocation for the same reason.

`ddev poweroff` is the one machine wide command. It is tracked under the reserved target
`*` (ddev project names match `^[\w-.]+$`, so it cannot collide with a real one) and is
unreachable from a project row. Its button is intentionally **never disabled**, even when
the snapshot says nothing is running: that count can be seconds stale, and this is the
button someone reaches for precisely when ddev is in a state the list does not reflect.

## The Electron project is ours, and composer will fight for it

`nativephp/electron/` is committed. Composer's `post-update-cmd` runs
`native:install --publish`, which mirrors the vendor copy over ours with override + delete,
and removing the script does not help because `InstallCommand` re-adds it.

`tests/Feature/ElectronProjectTest.php` asserts the settings that would otherwise be lost
silently:

- `productName: appName` in `electron-builder.mjs` — decides `userData`, so losing it makes
  the app look freshly installed. **Never change this after release.**
- `com.apple.security.cs.disable-library-validation` in the entitlements — required while
  builds are ad-hoc signed, or the app dies before `main()`. Drop it once a real Developer
  ID certificate is in place.
**After any `composer update`, run `git diff nativephp/electron`.**

## Icons live in `public/`, and nowhere else is durable

```bash
php resources/icons/build-icons.php    # then restart native:run
```

Sources are `resources/icons/ddev-mark.svg` (the official ddev mark) and
`resources/icons/tray-glyph.svg`. The generator has no dependencies: macOS QuickLook
rasterises the SVG onto white, and the coverage is recovered from the red channel, because
no SVG rasteriser (`rsvg-convert`, ImageMagick, sharp) is installed and a build must not
require one.

**`public/` is the source of truth.** NativePHP's `InstallsAppIcon` trait copies
`public/icon.png` and `public/IconTemplate*.png` into the vendor runtime directory
(`vendor/nativephp/desktop/resources/build/`) on every `native:run` and `native:build`.
Writing into that directory by hand does not survive, and writing only into
`nativephp/electron/build/` has no effect on the tray at all: that was the cause of the
menu bar showing NativePHP's own logo.

Two exceptions worth knowing:

- `nativephp/electron/build/icon.png` is also committed, because it is electron-builder's
  `buildResources` directory and the source of the packaged `.icns`. The trait means to
  copy it there but resolves `electronPath('build/icon.png')`, which looks for a
  `package.json` inside `build/`, fails, and falls back to the vendor copy.
- The tray needs `IconTemplate.png`; NativePHP ships its own, so a missing file does not
  look broken, it looks like the app is still branded NativePHP.

The tray glyph is a **deliberate simplification** of the mark, not the mark itself. At 16pt
the mark's seven concentric traces are sub-pixel and collapse into a grey blob, so the
glyph keeps only the bowl, two traces and their contact dots. Template images must stay
black plus alpha so macOS can recolour them for the light and dark menu bar.

## Layout lives at `resources/views/layouts/app.blade.php`

Livewire 4, not 3: the default is `component_layout => 'layouts::app'`, a namespaced view,
and the namespace is only registered if `resources/views/layouts/` exists. The Livewire 3
path (`views/components/layouts/`) fails with `No hint path defined for [layouts]`.

`Livewire::test()` does not render the layout, so it cannot catch this.
`tests/Feature/PopupPageTest.php` hits the route over HTTP instead.

## Adding a migration

Bump `NATIVEPHP_APP_VERSION`, or a packaged build keeps the old schema and shows a bare
`500 Server Error`. In dev run `php artisan native:migrate`, not `migrate`: plain `migrate`
targets `database/database.sqlite`, which nothing reads.

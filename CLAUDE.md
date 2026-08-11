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

| Source | Produces | Notes |
|---|---|---|
| `resources/icons/app-icon.png` | `public/icon.png` | The finished artwork (dark plate, blue ddev mark), used as-is apart from the inset below |
| `resources/icons/ddev-mark.svg` | `public/tray.png` (+`@2x`) | The mark in its blue: the menu bar icon actually used |
| `resources/icons/ddev-mark.svg` | `public/IconTemplate.png` (+`@2x`) | The same mark in black: the template fallback |

The generator needs no SVG rasteriser (`rsvg-convert`, ImageMagick and sharp are not
installed, and regenerating an icon must not require one): QuickLook rasterises onto white
and the coverage is recovered from the red channel.

### The menu bar icon is a colour icon, and its filename is load bearing

**macOS tints any image whose name ends in `Template` as a mask**, which throws the blue
away. So the icon is `tray.png`, not `IconTemplate.png`, and it has to be passed explicitly,
because NativePHP otherwise falls back to `IconTemplate.png`:

```php
MenuBar::create()->icon(config('ddev.tray_icon'))   // public/tray.png
```

Electron finds `tray@2x.png` alongside the 1x path by itself. Retina renders the 44px one,
which is the only size most machines will ever show.

The trade is that a colour icon does **not** adapt to the light and dark menu bar, does not
invert while the popup is open, and ignores system tinting. That is the accepted cost of
keeping the mark on brand. `public/IconTemplate.png` is still generated, in black, so the
fallback path is the ddev mark rather than the NativePHP logo.

The blue is `#3F92FF`, **sampled from `app-icon.png`** rather than ddev's official
`#02a8e2`, so the menu bar matches the app icon it sits beside.

Sizes are not free choices: **22x22 and 44x44** are upstream's own tray sizes, and anything
else is scaled by the system and looks soft. At 22px (non-retina) the mark is genuinely
dense; that is the cost of using the real logo instead of a simplification.

### The app icon is inset, because the artwork is full bleed

The supplied artwork spans all 1024px, which is the iOS convention. macOS insets app icons
(the plate occupies 824 of 1024), so the generator scales it into that box; without it the
icon renders visibly larger than everything else in the Dock. `PLATE_SIZE = ICON_SIZE` gives
edge-to-edge instead.

**In dev you never see the app icon.** `native:run` launches unbundled Electron and this app
hides its dock icon, so `icon.png` only appears in a packaged build. The menu bar is the only
icon dev can tell you anything about.

`config('ddev.tray_icon')` resolves through `public_path()`, so a packaged build reads it
from inside the bundle. Worth confirming on the first real `native:build` that `public/` is
bundled (skill section 9).

**`public/` is the source of truth, and there is no icon config.** NativePHP's
`InstallsAppIcon` trait copies by filename out of `public/` into the vendor runtime
directory (`vendor/nativephp/desktop/resources/build/`) on every `native:run` and
`native:build`. Every one of those copies is `@`-suppressed, so a file that is missing or
misnamed fails silently and the app keeps NativePHP's logo with nothing in the build output
to say so. Writing into the runtime directory by hand does not survive the next copy, and
writing only into `nativephp/electron/build/` does nothing for the tray at all: that was
why the menu bar stayed branded NativePHP.

One exception: `nativephp/electron/build/icon.png` is committed and kept byte-identical to
`public/icon.png`, because it is electron-builder's `buildResources` directory and the
source of the packaged `.icns`. The trait means to refresh it but resolves
`electronPath('build/icon.png')`, which looks for a `package.json` inside `build/`, fails,
and falls back to the vendor copy. `native:install --publish` puts the NativePHP logo back
there, so a test compares the two hashes.

The tray glyph is a **deliberate simplification** of the mark, not the mark itself. At 22pt
the mark's seven concentric traces are sub-pixel and collapse into a grey blob, so the glyph
keeps only the bowl, two traces and their contact dots.

Because a wrong icon is invisible to the build, `tests/Feature/ElectronProjectTest.php` is
the only cheap place to catch it: it asserts the `public/` files exist at the right sizes,
that the templates are black over transparency and not a full-frame blob, and that the
published copy still matches `public/icon.png`.

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

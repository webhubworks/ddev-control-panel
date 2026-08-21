# DDEV Control Panel

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
- The Livewire component only ever reads the cache. It polls slowly while open and faster
  while something is in flight (`isBusy()`), and a poll never costs more than a cache read.

`CACHE_STORE=file` and `SESSION_DRIVER=file` deliberately keep the hot path off SQLite,
which the queue worker is writing to at the same time.

## Docker is the event source, because ddev has none

Start a project in a terminal and this app has to find out somehow. ddev cannot tell it:

- It is a one shot CLI. No daemon, no socket, nothing to subscribe to.
- Its hooks (`post-start`, `post-stop`, ...) are **per project**, in each
  `.ddev/config.yaml`. There is no global hooks key, so they would have to be written into
  every repo on the machine, and they would still only fire for changes that went through
  ddev.
- `ddev list --continuous` is a real stream, but it re-scans everything each interval:
  seconds of wall clock and CPU, forever. Not something a menu bar app should do.

Docker is the one thing that reports every change, and it is usable here because **ddev
labels every container it creates** (`com.ddev.site-name`, `com.ddev.approot`,
`com.ddev.platform=ddev`). So `ddev:watch` (`WatchDdevEventsCommand`) follows
`docker events` filtered to that label, and asks for a refresh when the events go quiet.
`NativeAppServiceProvider` runs it as a **persistent child process**, so it is supervised
and restarted like the queue worker.

Four things about that stream are worth knowing before touching it:

- **`health_status` has to be in the filter.** A project prints `running` until its
  healthchecks pass and `OK` afterwards, and that event is the only thing that reports the
  difference. It arrives seconds after `start`.
- **The `exec_*` actions are pure noise.** Every container runs its healthcheck through
  `docker exec` every 30 seconds. Both the Docker side filter and `DockerEvent::isRelevant()`
  drop them.
- **`ddev exec` / `ddev composer` fire a full create/start/die/destroy sequence** for a
  throwaway container that carries the project's labels while nothing about the project
  changes. `DockerEvent` drops those by the compose `oneoff` label and the `-run-<hash>`
  name.
- **Docker's replay buffer is tiny**, and the healthcheck chatter flushes it within minutes.
  `docker events --since 1h` being empty says nothing about the live stream.

The watcher refreshes **on attach** as well, not only on events: Docker reports what happens
next, never what already happened, so anything that changed while the app was closed or
Docker was restarting would otherwise never be noticed. That resync waits a few ticks for the
stream to prove itself alive, because `docker events` exits immediately while Docker is not
running and the watcher retries every few seconds: resyncing per attempt would mean a failing
`ddev list` on a loop for as long as Docker stays closed.

It also skips a refresh while one is already in flight, and keeps the event pending rather
than dropping it, so a change that lands mid scan is picked up by the next one.

If the list is stale, `ddev:watch` is the thing to check. It logs when it cannot start
(`docker` not found) and when it loses the stream (Docker not running), and it prints each
event it acts on, which lands in the app's child process output.

## The popup has to place its own focus

The menubar window is hidden, not destroyed, so reopening it fires no navigation. Two things
hang off that, both handled by `opened()` on the component's root element:

- It asks for a fresh snapshot itself, since no navigation happens to do it. The Docker
  watcher usually got there first, so this is the fallback for a watcher that is off or
  cannot reach Docker.
- It moves focus into the search field. Left alone the window hands focus to the first
  focusable element, which is a **header button**: it picks up a `:focus-visible` ring
  (focus did not arrive by mouse) and a stray Space or Enter fires it, with delete one tab
  away. Autofocusing search removes the hazard and is the useful default with this many
  projects.

The queue worker is auto-started by NativePHP (`nativephp.queue_workers`) and only when not
running in console. If the popup sits on its loading skeleton forever, the worker is the
first thing to check: look for `ChildProcess\ProcessSpawned` in the log.

## Where the logs and cache actually live

Not in the repo. NativePHP redirects storage to `app.getPath('userData')`:

- **dev:** `~/Library/Application Support/nativephp/` (the published Electron project's
  `package.json` is named `nativephp`, and `extraMetadata` only applies to a packaged build)
- **packaged:** `~/Library/Application Support/DDEV Control Panel/`

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

## The open menu, and why none of its entries run ddev

The external-link button on a running row is a menu: **open site**, **open database**,
**open mail**. All three are `Shell::openExternal()` on a URL the snapshot already holds,
inside the Livewire request itself, so they are instant.

`ddev list --json-output` gives two of those URLs directly: `primary_url`, and
`mailpit_https_url` / `mailpit_url`, which is exactly what `ddev launch -m` opens. Shelling
out would buy a second of ddev startup and a queue round trip to arrive at the same address.

The database URL is not in `ddev list`, and it used to be worth `ddev tableplus` for. It is
not: that command is a **host command**, a shell script in `~/.ddev/commands/host/`, and
running it cost the queue's sleep interval plus a second of ddev startup, so opening a
database took seconds while opening a site was instant. Everything the script needs is
either fixed or cheap:

- **Credentials are always `db`** (user, password, database), for every ddev project.
- **The published host port** is not in `ddev list` and is a second per project in
  `ddev describe`, but it is in `docker ps`, for every project at once, in about 70 ms. So
  `DockerCli::databasePorts()` reads it there and `RefreshDdevProjectsAction` folds it into
  each row, next to the extra hostnames and next to a `ddev list` that already spent seconds
  inspecting the same containers.
- **The driver** follows from the container port: 3306 is mysql (mariadb speaks the same
  protocol, which is why ddev's own script makes the same two way choice), 5432 is postgres.

`DdevProject::databaseUrl()` then builds ddev's URL verbatim, **including the misspelled
`Enviroment=local` query key**, so this app and `ddev tableplus` address the same saved
TablePlus connection rather than leaving two behind.

Handing `mysql://` to `Shell::openExternal()` opens whichever client registered the scheme,
the same way open site uses the default browser. TablePlus registers `mysql` and `postgres`
(check with `plutil -extract CFBundleURLTypes json -o - /Applications/TablePlus.app/Contents/Info.plist`),
so it is what opens on a machine that has it, and no Setapp path handling is needed.

Only a **running** database container publishes a port, so a stopped project, or one with
`omit_containers: [db]`, has no `databaseUrl` and the entry is disabled. That is better than
what `ddev tableplus` gave: without TablePlus installed the script's `HostBinaryExists` gate
made ddev answer `unknown command`, which surfaced as a failed operation on the row.

The consequence for the queue is that **every operation left in `DdevOperation` changes
project state**, so `RunDdevOperationAction` always follows one with a refresh, and nothing
runs ddev outside the project-name form any more.

### A project can answer on more than one host, and `ddev list` will not say so

`additional_hostnames` and `additional_fqdns` in a project's `.ddev` config give it extra
hosts through the same router (mesh serves its timehub surface on `timehub-mesh.ddev.site`,
because a DNS wildcard matches one label and `timehub.mesh.ddev.site` would need an
`/etc/hosts` edit on every machine). **`ddev list --json-output` reports only
`primary_url`.** The complete set is in `ddev describe`, which is a second per project and
therefore unusable for a list of dozens.

So `DdevProjectConfig` reads those two keys straight out of `.ddev/config.yaml` (plus every
`config.*.yaml` beside it, merged in name order, with `override_config: true` replacing
rather than appending, which is ddev's own rule). That happens in
**`RefreshDdevProjectsAction`**, not in `DdevProject::fromListRow()`: a snapshot is
rehydrated from cache on every poll, and reading config files that often would put file I/O
back on the hot path we moved ddev off.

`DdevProject::siteUrls()` then builds a URL per host, taking the scheme, TLD and port from
`primary_url` rather than looking any of them up again. If the primary URL is not the
project's own name under a TLD, the router is out of the picture (`router_disabled`
publishes on 127.0.0.1) and the extra hostnames are dropped, since they would resolve to
nothing; an additional FQDN is a complete host and is kept either way.

The menu shows **"Open site"** only while there is one host. With more it lists each by
host name, because "Open site" cannot say which site. `openUrl()` takes the URL back from
the browser and opens it only if the snapshot itself lists it, falling back to the primary
URL: it is an untrusted string on its way to `Shell::openExternal()`.

This is the one place the popup reads a project's files rather than asking ddev. It costs a
couple of small reads per project per refresh, next to a `ddev list` that already takes five
seconds.

The menu itself is a **native `popover` with CSS anchor positioning**, no JS. The project
list is an `overflow-y-auto` container, so an ordinary absolutely positioned dropdown would
be clipped; the top layer is not, and light dismiss plus Escape come for free. The anchor
name is keyed by a hash of the project name because ddev allows dots in a name and a CSS
ident does not. `PopupPageTest` renders a dotted name and asserts the `popovertarget` names
an element that exists, since a mismatch renders a button that silently does nothing.

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
| `resources/icons/ddev-mark.svg` | `public/IconTemplate.png` (+`@2x`) | The real ddev mark as a macOS template image, for the menu bar |

The generator needs no SVG rasteriser (`rsvg-convert`, ImageMagick and sharp are not
installed, and regenerating an icon must not require one): QuickLook rasterises onto white
and the coverage is recovered from the red channel.

### The menu bar uses a template image, which is how the mark comes out white

A macOS template image is a **mask**: only the alpha channel is read, and the system paints
it in the menu bar's label colour. That gives white on a dark menu bar, near-black on a
light one, and an inverted icon while the popup is open. The RGB in the file is irrelevant,
and is written black by convention.

This is why there is **no `MenuBar::icon()` call** in `NativeAppServiceProvider`: NativePHP
resolves the tray image to `build/IconTemplate.png` on its own, and that is exactly what we
want. Passing a coloured PNG instead would pin one colour and lose the tinting, and it could
not be named `*Template.png` either, since macOS would mask it and throw the colour away.

Sizes are not free choices: **22x22 and 44x44** are upstream's own tray sizes, and anything
else is scaled by the system and looks soft. Retina renders the 44px one, which is the only
size most machines will ever show. At 22px the mark is genuinely dense; that is the cost of
using the real logo rather than a simplified glyph.

### The app icon is inset, because the artwork is full bleed

The supplied artwork spans all 1024px, which is the iOS convention. macOS insets app icons
(the plate occupies 824 of 1024), so the generator scales it into that box; without it the
icon renders visibly larger than everything else in the Dock. `PLATE_SIZE = ICON_SIZE` gives
edge-to-edge instead.

**In dev you never see the app icon.** `native:run` launches unbundled Electron and this app
hides its dock icon, so `icon.png` only appears in a packaged build. The menu bar is the only
icon dev can tell you anything about.

The tray image is read from the vendor runtime build directory, which `extraResources` copies
into the packaged app, so it needs nothing from `public/` at runtime.

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

## Releasing

```bash
nvm use
export GITHUB_TOKEN=$(gh auth token)
php artisan native:build mac arm64 --publish
```

Name the architecture. Leaving it off only prompts for it, but answering `all` keeps
`buildOS` at `mac`, which runs the `publish:mac` script, which is `publish:mac-arm64 --
--x64` and builds both slices as separate ~150 MB artifacts. `arm64` is the only one worth
shipping until someone asks for Intel.

Four things are load bearing and none of them fail loudly:

- **The publish policy must reach electron-builder exactly once.** Upstream's
  `publish:mac-arm64` passes `-p always` twice, yargs collapses a repeated option into an
  array, and `GitHubPublisher` compares `options.publish === "always"` against
  `["always", "always"]`. The policy silently falls back to `onTagOrDraft`, the whole app
  builds, and then every artifact is `skipped publishing`. `native:install --publish` puts
  the duplicate back on every `composer update`, so `ElectronProjectTest` asserts it.
- **`NATIVEPHP_UPDATER_ENABLED` gates the publish target itself.** `electron-builder.mjs`
  spreads `publish` into its config only when the updater is on, so with it off `--publish`
  runs a build that has nowhere to upload to. It is not only a runtime toggle.
- **`GH_TOKEN` comes from `GITHUB_TOKEN`** via the updater provider. `GITHUB_*` is in
  `cleanup_env_keys`, so it is stripped from the packaged `.env`; prefer exporting it for
  the build over writing it into `.env` at all.
- **`native:build` never builds the Laravel assets.** It copies `public/` as it stands, so
  a leftover `public/hot` from `npm run dev` ships an app that points at a Vite server that
  is not running: unstyled serif page, no JS. `config/nativephp.php`'s `prebuild` runs
  `npm run build` for exactly this, but a failing pre-process command is reported and then
  **ignored**, so watch that step.

Releases are created as drafts (`GITHUB_RELEASE_TYPE`), so nothing goes public on its own.

The version that ships is `NATIVEPHP_APP_VERSION`, not a git tag, and `vPrefixedTagName` is
on, so `0.0.1` uploads to a `v0.0.1` release.

**The build is ad-hoc signed until `NATIVEPHP_APPLE_TEAM_ID` is set.** It runs here, but on
any other Mac Gatekeeper reports it as damaged, and there is no way around that from the
receiving side other than stripping the quarantine attribute by hand. A real release needs
a Developer ID certificate plus `NATIVEPHP_APPLE_ID` and `NATIVEPHP_APPLE_ID_PASS` for
notarization. `build/notarize.js` skips silently when they are missing.

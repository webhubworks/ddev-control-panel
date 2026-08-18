@php
    $snapshot = $this->snapshot();
    $projects = $this->projects();
    $operations = $this->operations();
    $busy = $this->isBusy();

    $toneClasses = [
        'positive' => 'bg-emerald-500/10 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'warning' => 'bg-amber-500/10 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
        'negative' => 'bg-red-500/10 text-red-700 dark:bg-red-400/10 dark:text-red-300',
        'neutral' => 'bg-zinc-500/10 text-zinc-600 dark:bg-zinc-400/10 dark:text-zinc-400',
    ];

    $dotClasses = [
        'positive' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'negative' => 'bg-red-500',
        'neutral' => 'bg-zinc-400 dark:bg-zinc-600',
    ];
@endphp

<div
    class="flex h-screen flex-col"
    {{--
        Always polling, faster while something is in flight. A poll only reads
        the cache, and it is how an open popup picks up the snapshot the Docker
        watcher writes from outside the request. Livewire stops polling on its
        own once the window is hidden.
    --}}
    wire:poll.{{ $busy ? $pollInterval : $idlePollInterval }}ms
    {{--
        opened() runs every time the popup comes back to the front. The menubar
        window is hidden rather than destroyed, so it has to ask for fresh data
        itself, and it has to place focus deliberately: left alone, the window
        hands focus to the first focusable element, which is a header button. It
        picks up a focus ring for no reason, and a stray Space or Enter fires it
        with delete one tab away. The search field is the harmless target, and
        the one actually worth typing into with this many projects.

        **The focus is placed a frame late, and that is the whole of it working.**
        Chromium assigns the window's own focus after the focus event has been
        dispatched, so a focus() called straight from this handler is overwritten
        by the very control it is there to keep the ring off - which is what the
        first enabled header button wearing a blue ring on every open was. A
        frame later there is nothing left to overwrite it.
    --}}
    x-data="{ opened() { $wire.refreshProjects(true); requestAnimationFrame(() => $refs.search?.focus()) } }"
    x-on:visibilitychange.document="if (! document.hidden) opened()"
    x-on:focus.window="opened()"
>
    <header class="flex items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-800">
        <div class="flex min-w-0 items-center gap-2">
            <h1 class="truncate font-semibold">DDEV Control Panel</h1>

            @if ($snapshot !== null && ! $snapshot->failed())
                <span class="rounded-full bg-zinc-500/10 px-2 py-0.5 text-xs font-medium tabular-nums text-zinc-600 dark:bg-zinc-400/10 dark:text-zinc-400">
                    {{ $this->runningCount() }} / {{ $snapshot->projects->count() }} running
                </span>
            @endif
        </div>

        <div class="flex items-center gap-0.5">
            <button
                type="button"
                wire:click="refreshProjects"
                @disabled($busy)
                class="cursor-pointer rounded-md p-1.5 text-zinc-500 transition-colors duration-150 hover:bg-zinc-500/10 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-40 dark:hover:text-zinc-100"
                title="Refresh project list"
                aria-label="Refresh project list"
            >
                <x-icon name="refresh" @class(['motion-safe:animate-spin' => $busy]) />
            </button>

            {{--
                Deliberately never disabled: the running count comes from a
                snapshot that may be seconds old, and disabling on a stale count
                would hide the one button someone reaches for when ddev is in a
                bad state. `ddev poweroff` is harmless when nothing is running.
            --}}
            <button
                type="button"
                wire:click="powerOff"
                @class([
                    'cursor-pointer rounded-md p-1.5 transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none',
                    'bg-amber-500 text-white' => $this->confirmingPowerOff,
                    'text-zinc-500 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400' => ! $this->confirmingPowerOff,
                ])
                title="Stop all projects (ddev poweroff)"
                aria-label="Stop all projects"
            >
                <x-icon name="power" />
            </button>

            <button
                type="button"
                wire:click="quit"
                class="cursor-pointer rounded-md p-1.5 text-zinc-500 transition-colors duration-150 hover:bg-red-500/10 hover:text-red-600 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none dark:hover:text-red-400"
                title="Quit DDEV Control Panel"
                aria-label="Quit DDEV Control Panel"
            >
                <x-icon name="logout" />
            </button>
        </div>
    </header>

    @if ($this->confirmingPowerOff)
        <div class="flex items-start gap-2 border-b border-amber-500/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300" role="alert">
            <x-icon name="warning" class="mt-0.5 size-3.5" />

            <div class="min-w-0 flex-1">
                <p>
                    Stop all
                    <span class="font-semibold tabular-nums">{{ $this->runningCount() }}</span>
                    running {{ \Illuminate\Support\Str::plural('project', $this->runningCount()) }},
                    plus ddev's router and ssh-agent? No data is removed.
                </p>

                <div class="mt-1.5 flex items-center gap-1.5">
                    <button
                        type="button"
                        wire:click="powerOff"
                        class="cursor-pointer rounded bg-amber-600 px-2 py-1 font-medium text-white transition-colors duration-150 hover:bg-amber-700 focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:outline-none"
                    >
                        Stop all
                    </button>

                    <button
                        type="button"
                        wire:click="cancelPowerOff"
                        class="cursor-pointer rounded px-2 py-1 font-medium text-zinc-600 transition-colors duration-150 hover:bg-zinc-500/10 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none dark:text-zinc-400"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($powerOff = $this->powerOffOperation())
        <div
            @class([
                'flex items-start gap-2 border-b px-3 py-2 text-xs',
                'border-blue-500/20 bg-blue-500/10 text-blue-700 dark:text-blue-300' => $powerOff->isPending(),
                'border-red-500/20 bg-red-500/10 text-red-700 dark:text-red-300' => $powerOff->failed(),
                'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ! $powerOff->isPending() && ! $powerOff->failed(),
            ])
            role="status"
        >
            @if ($powerOff->isPending())
                <x-icon name="spinner" class="mt-0.5 size-3.5 motion-safe:animate-spin" />
                <p class="font-medium">{{ $powerOff->operation->activeLabel() }}</p>
            @else
                <x-icon name="{{ $powerOff->failed() ? 'warning' : 'check' }}" class="mt-0.5 size-3.5" />
                <p class="min-w-0 break-words">
                    {{ $powerOff->failed() ? $powerOff->message : 'All projects stopped.' }}
                </p>
            @endif
        </div>
    @endif

    <div class="flex items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
        <label class="relative flex-1">
            <span class="sr-only">Search projects</span>

            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-zinc-400" />

            <input
                type="search"
                x-ref="search"
                autofocus
                wire:model.live.debounce.200ms="search"
                placeholder="Search projects"
                autocomplete="off"
                class="w-full rounded-md border border-zinc-200 bg-zinc-50 py-1.5 pr-2 pl-7 text-sm placeholder:text-zinc-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800"
            >
        </label>

        <button
            type="button"
            wire:click="$toggle('runningOnly')"
            @class([
                'cursor-pointer rounded-md px-2 py-1.5 text-xs font-medium whitespace-nowrap transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none',
                'bg-blue-600 text-white' => $this->runningOnly,
                'bg-zinc-500/10 text-zinc-600 hover:bg-zinc-500/20 dark:text-zinc-400' => ! $this->runningOnly,
            ])
            aria-pressed="{{ $this->runningOnly ? 'true' : 'false' }}"
        >
            Running only
        </button>
    </div>

    @if ($snapshot?->failed())
        <div class="flex items-start gap-2 border-b border-red-500/20 bg-red-500/10 px-3 py-2 text-xs text-red-700 dark:text-red-300" role="alert">
            <x-icon name="warning" class="mt-0.5 size-3.5" />

            <div class="min-w-0">
                <p class="font-medium">ddev could not be reached</p>
                <p class="mt-0.5 break-words opacity-90">{{ $snapshot->error }}</p>
                <p class="mt-1 opacity-75">Check that Docker is running, then refresh.</p>
            </div>
        </div>
    @endif

    <div class="flex-1 overflow-y-auto overscroll-contain">
        @if ($snapshot === null)
            {{-- First run: the background scan has not reported yet. --}}
            <div class="space-y-1 p-3" aria-busy="true" aria-label="Loading projects">
                @foreach (range(1, 6) as $placeholder)
                    <div class="flex items-center gap-2 rounded-lg px-2 py-2.5">
                        <div class="size-2 rounded-full bg-zinc-200 motion-safe:animate-pulse dark:bg-zinc-800"></div>
                        <div class="h-3 flex-1 rounded bg-zinc-200 motion-safe:animate-pulse dark:bg-zinc-800"></div>
                        <div class="h-3 w-12 rounded bg-zinc-200 motion-safe:animate-pulse dark:bg-zinc-800"></div>
                    </div>
                @endforeach
            </div>
        @elseif ($projects->isEmpty())
            <div class="flex h-full flex-col items-center justify-center gap-2 px-6 text-center">
                <x-icon name="inbox" class="size-6 text-zinc-300 dark:text-zinc-700" />

                @if (filled($this->search) || $this->runningOnly)
                    <p class="text-zinc-500 dark:text-zinc-400">No projects match your filters.</p>

                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="cursor-pointer text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                    >
                        Clear filters
                    </button>
                @else
                    <p class="text-zinc-500 dark:text-zinc-400">No ddev projects found.</p>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">
                        Run <code class="rounded bg-zinc-500/10 px-1 py-0.5 font-mono">ddev config</code> in a project to add one.
                    </p>
                @endif
            </div>
        @else
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800/70">
                @foreach ($projects as $project)
                    @php
                        $operation = $operations->get($project->name);
                        $pending = $operation?->isPending() ?? false;
                        $confirming = $this->confirmingDeleteFor === $project->name;
                        $tone = $project->statusTone();
                    @endphp

                    <li
                        wire:key="project-{{ $project->name }}"
                        class="group px-3 py-2 transition-colors duration-150 hover:bg-zinc-500/5"
                    >
                        <div class="flex items-center gap-2">
                            <span class="relative flex size-2 shrink-0" aria-hidden="true">
                                @if ($pending)
                                    <span class="absolute inline-flex size-full rounded-full bg-blue-500 opacity-75 motion-safe:animate-ping"></span>
                                @endif
                                <span @class(['relative inline-flex size-2 rounded-full', $pending ? 'bg-blue-500' : $dotClasses[$tone]])></span>
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium" title="{{ $project->shortRoot }}">{{ $project->name }}</p>

                                <div class="mt-0.5 flex items-center gap-1.5 text-xs">
                                    @if ($pending)
                                        <span class="flex items-center gap-1 font-medium text-blue-600 dark:text-blue-400">
                                            <x-icon name="spinner" class="size-3 motion-safe:animate-spin" />
                                            {{ $operation->operation->activeLabel() }}
                                        </span>
                                    @else
                                        <span class="rounded px-1.5 py-0.5 font-medium {{ $toneClasses[$tone] }}">
                                            {{ $project->statusLabel() }}
                                        </span>
                                    @endif

                                    <span class="truncate text-zinc-400 dark:text-zinc-500">{{ $project->type }}</span>
                                </div>
                            </div>

                            {{--
                                Actions stay visible (dimmed) rather than appearing only on
                                hover, so they are discoverable and keyboard reachable.
                            --}}
                            <div class="flex items-center gap-0.5 opacity-70 transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
                                @if ($project->status->isRunning())
                                    {{--
                                        A native popover, so the menu lives in the top layer
                                        and is not clipped by the scrolling list, and so
                                        light dismiss and Escape come for free. It is placed
                                        by CSS anchor positioning, which follows the row as
                                        the list scrolls without any JS.

                                        Keyed by a hash of the project name: ddev allows
                                        dots in a name and a CSS ident does not.
                                    --}}
                                    @php
                                        $menuId = 'open-menu-'.substr(md5($project->name), 0, 8);
                                        $menuAnchor = '--anchor-'.substr(md5($project->name), 0, 8);
                                        $menuItemClasses = 'flex w-full cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left transition-colors duration-150 hover:bg-zinc-500/10 focus-visible:bg-zinc-500/10 focus-visible:outline-none disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent';
                                    @endphp

                                    <button
                                        type="button"
                                        popovertarget="{{ $menuId }}"
                                        style="anchor-name: {{ $menuAnchor }}"
                                        @disabled($pending)
                                        class="flex cursor-pointer items-center rounded p-1 text-zinc-500 transition-colors duration-150 hover:bg-zinc-500/10 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-30 dark:hover:text-zinc-100"
                                        title="Open {{ $project->name }}"
                                        aria-label="Open {{ $project->name }}"
                                        aria-haspopup="menu"
                                    >
                                        <x-icon name="external-link" class="size-3.5" />
                                        <x-icon name="chevron-down" class="-mr-0.5 size-2.5 opacity-60" />
                                    </button>

                                    <div
                                        id="{{ $menuId }}"
                                        popover
                                        role="menu"
                                        {{--
                                            The UA stylesheet centres a popover with
                                            `inset: 0; margin: auto`, which has to be undone
                                            before the anchor can place it.
                                        --}}
                                        style="position-anchor: {{ $menuAnchor }}; position-area: bottom span-left; position-try-fallbacks: flip-block; inset: auto; margin: 4px 0 0 0; width: max-content;"
                                        class="rounded-lg border border-zinc-200 bg-white p-1 text-sm text-zinc-900 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                    >
                                        <button
                                            type="button"
                                            role="menuitem"
                                            wire:click="openUrl(@js($project->name))"
                                            popovertarget="{{ $menuId }}"
                                            popovertargetaction="hide"
                                            @disabled(blank($project->primaryUrl))
                                            class="{{ $menuItemClasses }}"
                                            title="{{ $project->primaryUrl }}"
                                        >
                                            <x-icon name="external-link" class="size-3.5 text-zinc-400" />
                                            Open site
                                        </button>

                                        {{--
                                            `ddev tableplus` is a host command: it reads the
                                            project's published database port and hands
                                            TablePlus a connection URL. It is not instant, so
                                            it goes through the queue like every other ddev
                                            call, and its row reports "Opening database".
                                        --}}
                                        <button
                                            type="button"
                                            role="menuitem"
                                            wire:click="runOperation(@js($project->name), 'tableplus')"
                                            popovertarget="{{ $menuId }}"
                                            popovertargetaction="hide"
                                            class="{{ $menuItemClasses }}"
                                            title="Open the database in TablePlus (ddev tableplus)"
                                        >
                                            <x-icon name="database" class="size-3.5 text-zinc-400" />
                                            Open database
                                        </button>

                                        <button
                                            type="button"
                                            role="menuitem"
                                            wire:click="openMailpit(@js($project->name))"
                                            popovertarget="{{ $menuId }}"
                                            popovertargetaction="hide"
                                            @disabled(blank($project->mailpitUrl))
                                            class="{{ $menuItemClasses }}"
                                            title="{{ $project->mailpitUrl ?? 'Mailpit is not available' }}"
                                        >
                                            <x-icon name="mail" class="size-3.5 text-zinc-400" />
                                            Open mail
                                        </button>
                                    </div>
                                @endif

                                <button
                                    type="button"
                                    wire:click="revealInFinder(@js($project->name))"
                                    @disabled($pending)
                                    class="cursor-pointer rounded p-1 text-zinc-500 transition-colors duration-150 hover:bg-zinc-500/10 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-30 dark:hover:text-zinc-100"
                                    title="Reveal {{ $project->shortRoot }} in Finder"
                                    aria-label="Reveal {{ $project->name }} in Finder"
                                >
                                    <x-icon name="folder" class="size-3.5" />
                                </button>

                                @if ($project->status->canStop())
                                    <button
                                        type="button"
                                        wire:click="runOperation(@js($project->name), 'stop')"
                                        @disabled($pending)
                                        class="cursor-pointer rounded p-1 text-zinc-500 transition-colors duration-150 hover:bg-zinc-500/10 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-30 dark:hover:text-zinc-100"
                                        title="Stop {{ $project->name }}"
                                        aria-label="Stop {{ $project->name }}"
                                    >
                                        <x-icon name="stop" class="size-3.5" />
                                    </button>
                                @endif

                                @if ($project->status->canRestart())
                                    <button
                                        type="button"
                                        wire:click="runOperation(@js($project->name), 'restart')"
                                        @disabled($pending)
                                        class="cursor-pointer rounded p-1 text-zinc-500 transition-colors duration-150 hover:bg-zinc-500/10 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-30 dark:hover:text-zinc-100"
                                        title="Restart {{ $project->name }}"
                                        aria-label="Restart {{ $project->name }}"
                                    >
                                        <x-icon name="restart" class="size-3.5" />
                                    </button>
                                @endif

                                @if ($project->status->canStart())
                                    <button
                                        type="button"
                                        wire:click="runOperation(@js($project->name), 'start')"
                                        @disabled($pending)
                                        class="cursor-pointer rounded p-1 text-emerald-600 transition-colors duration-150 hover:bg-emerald-500/10 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-30 dark:text-emerald-400"
                                        title="Start {{ $project->name }}"
                                        aria-label="Start {{ $project->name }}"
                                    >
                                        <x-icon name="play" class="size-3.5" />
                                    </button>
                                @endif

                                <button
                                    type="button"
                                    wire:click="runOperation(@js($project->name), 'delete')"
                                    @disabled($pending)
                                    @class([
                                        'cursor-pointer rounded p-1 transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none disabled:cursor-default disabled:opacity-30',
                                        'bg-red-600 text-white' => $confirming,
                                        'text-zinc-500 hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400' => ! $confirming,
                                    ])
                                    title="Delete {{ $project->name }}"
                                    aria-label="Delete {{ $project->name }}"
                                >
                                    <x-icon name="trash" class="size-3.5" />
                                </button>
                            </div>
                        </div>

                        @if ($confirming)
                            <div class="mt-2 flex items-start gap-2 rounded-md bg-red-500/10 px-2 py-1.5 text-xs text-red-700 dark:text-red-300" role="alert">
                                <x-icon name="warning" class="mt-0.5 size-3.5" />

                                <div class="min-w-0 flex-1">
                                    <p>
                                        Remove containers and the database for
                                        <span class="font-semibold">{{ $project->name }}</span>?
                                        Your code is untouched and ddev keeps a database snapshot.
                                    </p>

                                    <div class="mt-1.5 flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="runOperation(@js($project->name), 'delete')"
                                            class="cursor-pointer rounded bg-red-600 px-2 py-1 font-medium text-white transition-colors duration-150 hover:bg-red-700 focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:outline-none"
                                        >
                                            Delete
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="cancelDelete"
                                            class="cursor-pointer rounded px-2 py-1 font-medium text-zinc-600 transition-colors duration-150 hover:bg-zinc-500/10 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none dark:text-zinc-400"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($operation !== null && ! $pending && filled($operation->message))
                            <div
                                @class([
                                    'mt-1.5 flex items-start gap-1.5 rounded-md px-2 py-1.5 text-xs',
                                    'bg-red-500/10 text-red-700 dark:text-red-300' => $operation->failed(),
                                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ! $operation->failed(),
                                ])
                                role="status"
                            >
                                <x-icon name="{{ $operation->failed() ? 'warning' : 'check' }}" class="mt-0.5 size-3.5" />
                                <p class="min-w-0 break-words">{{ $operation->message }}</p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <footer class="flex items-center justify-between gap-2 border-t border-zinc-200 px-3 py-1.5 text-xs text-zinc-400 dark:border-zinc-800 dark:text-zinc-500">
        <span class="tabular-nums">
            {{ $projects->count() }} {{ \Illuminate\Support\Str::plural('project', $projects->count()) }}
        </span>

        @if ($busy)
            <span class="flex items-center gap-1">
                <x-icon name="spinner" class="size-3 motion-safe:animate-spin" />
                Working
            </span>
        @elseif ($snapshot !== null)
            <span title="{{ $snapshot->refreshedAt->toDateTimeString() }}">
                Updated {{ $snapshot->refreshedAt->diffForHumans(short: true) }}
            </span>
        @endif
    </footer>
</div>

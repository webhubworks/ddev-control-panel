<?php

namespace App\Livewire;

use App\Actions\Ddev\QueueDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\DataTransferObjects\DdevProject;
use App\DataTransferObjects\DdevProjectSnapshot;
use App\DataTransferObjects\DdevTranscriptLine;
use App\Enums\DdevOperation;
use App\Jobs\RefreshDdevProjectsJob;
use App\Support\Ddev\DdevState;
use App\Support\Ddev\DdevTranscript;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Shell;

class DdevProjectList extends Component
{
    public string $search = '';

    public bool $runningOnly = false;

    /**
     * Delete destroys the project's database, so it takes a second click.
     */
    public ?string $confirmingDeleteFor = null;

    /**
     * Poweroff stops every project on the machine, so it does too.
     */
    public bool $confirmingPowerOff = false;

    /**
     * The project whose ddev transcript is on screen, if any. The panel takes
     * over the list rather than sitting beside it: at 440px there is no room
     * for both, and a log is read a line at a time.
     */
    public ?string $viewingLogsFor = null;

    public function mount(): void
    {
        $this->refreshProjects(onlyIfStale: true);
    }

    /**
     * Ask the queue worker for a new `ddev list`. Never runs ddev inline: it
     * takes seconds and would block the single-worker PHP server.
     */
    public function refreshProjects(bool $onlyIfStale = false): void
    {
        $state = app(DdevState::class);

        if ($state->isRefreshing()) {
            return;
        }

        if ($onlyIfStale && ! $this->snapshotIsStale()) {
            return;
        }

        RefreshDdevProjectsJob::dispatch();
    }

    public function runOperation(string $projectName, string $operation): void
    {
        $operation = DdevOperation::tryFrom($operation);

        // Both arguments arrive from the browser, so neither is trusted: the
        // project name ends up on a command line. A machine wide command has
        // its own confirmed entry point and must not be reachable from a row.
        if ($operation === null || $operation->isGlobal() || ! $this->knowsProject($projectName)) {
            return;
        }

        if ($operation->isDestructive() && $this->confirmingDeleteFor !== $projectName) {
            $this->confirmingDeleteFor = $projectName;

            return;
        }

        $this->confirmingDeleteFor = null;

        QueueDdevOperationAction::queue(new DdevOperationRequest($projectName, $operation));

        // From here the command's output is the interesting thing, and it is
        // the only place a start that stalls says what it stalled on.
        $this->viewingLogsFor = $projectName;

        unset($this->operations);
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteFor = null;
    }

    /**
     * `ddev poweroff`: stop every project at once, plus ddev's shared router
     * and ssh-agent containers.
     */
    public function powerOff(): void
    {
        if (! $this->confirmingPowerOff) {
            $this->confirmingPowerOff = true;

            return;
        }

        $this->confirmingPowerOff = false;

        QueueDdevOperationAction::queue(DdevOperationRequest::global(DdevOperation::Poweroff));

        unset($this->operations);
    }

    public function cancelPowerOff(): void
    {
        $this->confirmingPowerOff = false;
    }

    /**
     * Show what ddev printed for one project, most recent command last.
     */
    public function showLogs(string $projectName): void
    {
        // The name arrives from the browser and picks a cache key, so it is
        // only ever one the snapshot itself lists.
        if ($this->knowsProject($projectName)) {
            $this->viewingLogsFor = $projectName;
        }
    }

    public function hideLogs(): void
    {
        $this->viewingLogsFor = null;
    }

    /**
     * Reveal the day's ddev log, which holds every project and a week of them.
     *
     * The panel shows one project's last few hundred lines; anything older, or
     * from another project, is in the file.
     */
    public function revealLogFile(): void
    {
        // Not in the checkout: NativePHP points storage_path() at the user data
        // directory whenever it is serving the app, in development too.
        $path = storage_path('logs/ddev-'.now()->format('Y-m-d').'.log');

        // Nothing has run today yet, so reveal the directory and its older days
        // rather than doing nothing at all.
        Shell::showInFolder(is_file($path) ? $path : storage_path('logs'));
    }

    /**
     * The lines of the transcript on screen, oldest first.
     *
     * @return Collection<int, DdevTranscriptLine>
     */
    #[Computed(persist: false)]
    public function transcript(): Collection
    {
        if ($this->viewingLogsFor === null) {
            return collect();
        }

        return DdevTranscript::for($this->viewingLogsFor)->lines();
    }

    /**
     * Milliseconds between polls.
     *
     * Fast while a command is in flight, and while a transcript is on screen,
     * because polling is the whole of what makes the panel a tail. A poll is a
     * cache read either way.
     */
    public function pollInterval(): int
    {
        return $this->isBusy() || $this->viewingLogsFor !== null
            ? (int) config('ddev.poll_interval')
            : (int) config('ddev.idle_poll_interval');
    }

    /**
     * The in-flight (or just-finished) poweroff, if there is one.
     */
    #[Computed(persist: false)]
    public function powerOffOperation(): ?DdevOperationState
    {
        return app(DdevState::class)->operation(DdevOperationRequest::GLOBAL_TARGET);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->runningOnly = false;
    }

    public function quit(): void
    {
        App::quit();
    }

    /**
     * Open one of the project's hosts in the browser.
     *
     * A project answers on its primary URL plus whatever `additional_hostnames`
     * and `additional_fqdns` its `.ddev` config adds, so the menu offers each
     * of them and passes back which one was picked. That argument comes from
     * the browser, so it is only ever opened when the snapshot itself lists it;
     * without a match the primary URL is used.
     */
    public function openUrl(string $projectName, ?string $url = null): void
    {
        $project = $this->project($projectName);

        if ($project === null) {
            return;
        }

        if ($url === null || ! in_array($url, $project->siteUrls, strict: true)) {
            $url = $project->primaryUrl;
        }

        if (filled($url)) {
            Shell::openExternal($url);
        }
    }

    /**
     * The project's Mailpit inbox, which is what `ddev launch -m` opens.
     *
     * Opened straight from the snapshot rather than through ddev: `ddev list`
     * already reports the URL, and shelling out would cost a second of ddev
     * startup and a queue round trip to arrive at the same address.
     */
    public function openMailpit(string $projectName): void
    {
        $url = $this->project($projectName)?->mailpitUrl;

        if (filled($url)) {
            Shell::openExternal($url);
        }
    }

    /**
     * The project's database, in whichever client the machine opens
     * `mysql://` and `postgres://` with.
     *
     * `ddev tableplus` would do the same thing, but as a host command it costs
     * a second of ddev startup plus a queue round trip, so the snapshot carries
     * the connection URL instead and this opens it the way a site is opened.
     */
    public function openDatabase(string $projectName): void
    {
        $url = $this->project($projectName)?->databaseUrl;

        if (filled($url)) {
            Shell::openExternal($url);
        }
    }

    public function revealInFinder(string $projectName): void
    {
        $project = $this->project($projectName);

        if ($project !== null && is_dir($project->appRoot)) {
            Shell::showInFolder($project->appRoot);
        }
    }

    #[Computed(persist: false)]
    public function snapshot(): ?DdevProjectSnapshot
    {
        return app(DdevState::class)->snapshot();
    }

    /**
     * @return Collection<string, DdevOperationState>
     */
    #[Computed(persist: false)]
    public function operations(): Collection
    {
        return app(DdevState::class)->operations();
    }

    /**
     * Running projects first, then alphabetically. With dozens of projects the
     * ones that are up are the ones the user came here for.
     *
     * @return Collection<int, DdevProject>
     */
    #[Computed(persist: false)]
    public function projects(): Collection
    {
        return ($this->snapshot()?->projects ?? collect())
            ->when($this->runningOnly, fn (Collection $projects): Collection => $projects->filter(
                fn (DdevProject $project): bool => $project->status->isRunning()
            ))
            ->when(filled($this->search), fn (Collection $projects): Collection => $projects->filter(
                fn (DdevProject $project): bool => Str::contains(
                    $project->name.' '.$project->type,
                    trim($this->search),
                    ignoreCase: true,
                )
            ))
            ->sortBy([
                fn (DdevProject $a, DdevProject $b): int => $this->statusRank($b) <=> $this->statusRank($a),
                fn (DdevProject $a, DdevProject $b): int => strcasecmp($a->name, $b->name),
            ])
            ->values();
    }

    #[Computed(persist: false)]
    public function runningCount(): int
    {
        return ($this->snapshot()?->projects ?? collect())
            ->filter(fn (DdevProject $project): bool => $project->status->isRunning())
            ->count();
    }

    public function isBusy(): bool
    {
        return app(DdevState::class)->isRefreshing() || $this->operations()->contains(
            fn (DdevOperationState $state): bool => $state->isPending()
        );
    }

    public function render()
    {
        return view('livewire.ddev-project-list');
    }

    /**
     * Higher sorts first. A project that is half up or broken is more
     * interesting than one that is deliberately stopped, so it ranks above it;
     * a project whose directory or config is gone ranks last.
     */
    private function statusRank(DdevProject $project): int
    {
        return match (true) {
            $project->status->isRunning() => 4,
            $project->status->isTransitioning() => 3,
            $project->status->isOrphaned() => 0,
            in_array($project->statusTone(), ['warning', 'negative'], strict: true) => 2,
            default => 1,
        };
    }

    private function project(string $projectName): ?DdevProject
    {
        return ($this->snapshot()?->projects ?? collect())
            ->first(fn (DdevProject $project): bool => $project->name === $projectName);
    }

    private function knowsProject(string $projectName): bool
    {
        return $this->project($projectName) !== null;
    }

    private function snapshotIsStale(): bool
    {
        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return true;
        }

        return $snapshot->refreshedAt
            ->addSeconds((int) config('ddev.snapshot_ttl'))
            ->isPast();
    }
}

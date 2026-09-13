<?php

use App\Actions\Ddev\RunDdevOperationAction;
use App\DataTransferObjects\DdevOperationRequest;
use App\DataTransferObjects\DdevOperationState;
use App\Enums\DdevOperation;
use App\Enums\DdevTranscriptLineKind;
use App\Livewire\DdevProjectList;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevState;
use App\Support\Ddev\DdevTranscript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Process::fake();
    Queue::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));

    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha-site', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'laravel', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
    ]);
});

it('keeps a line per project with the time it was printed', function () {
    $transcript = DdevTranscript::for('alpha-site');

    $transcript->append('$ ddev start alpha-site', DdevTranscriptLineKind::Command);
    $transcript->append('Starting alpha-site...', DdevTranscriptLineKind::Output);
    $transcript->flush();

    $lines = $transcript->lines();

    expect($lines)->toHaveCount(2)
        ->and($lines->first()->kind)->toBe(DdevTranscriptLineKind::Command)
        ->and($lines->last()->text)->toBe('Starting alpha-site...')
        // The timestamp is the whole point: on a command that never returns it
        // is what says where it stopped.
        ->and($lines->last()->at->isToday())->toBeTrue();
});

it('keeps one project\'s lines out of another\'s', function () {
    DdevTranscript::for('alpha-site')->append('alpha only', DdevTranscriptLineKind::Output);
    DdevTranscript::for('alpha-site')->flush();

    expect(DdevTranscript::for('beta-api')->lines())->toBeEmpty();
});

it('caps a transcript so a chatty hook cannot grow it without bound', function () {
    $transcript = DdevTranscript::for('alpha-site');

    // A dev server left running by a post-start hook prints for as long as it
    // runs, and every line of it arrives here.
    foreach (range(1, 400) as $number) {
        $transcript->append("line {$number}", DdevTranscriptLineKind::Output);
    }

    $transcript->flush();

    $lines = $transcript->lines();

    expect($lines->count())->toBeLessThanOrEqual(300)
        // The newest lines are the ones worth keeping.
        ->and($lines->last()->text)->toBe('line 400');
});

it('survives a transcript line left by an older build', function () {
    // The cache outlives the code. An unrecognised kind is still a line worth
    // reading, so it degrades rather than disappearing.
    Cache::put('ddev.transcript.'.md5('alpha-site'), [
        ['at' => now()->getTimestamp(), 'text' => 'from an older build', 'kind' => 'tableplus'],
        ['at' => now()->getTimestamp(), 'text' => '', 'kind' => 'output'],
        'not even an array',
    ], now()->addDay());

    $lines = DdevTranscript::for('alpha-site')->lines();

    expect($lines)->toHaveCount(1)
        ->and($lines->first()->kind)->toBe(DdevTranscriptLineKind::Output)
        ->and($lines->first()->text)->toBe('from an older build');
});

it('records what a lifecycle command printed', function () {
    Process::fake([
        '*' => Process::result(output: 'Successfully started alpha-site', exitCode: 0),
    ]);

    RunDdevOperationAction::run(new DdevOperationRequest('alpha-site', DdevOperation::Start));

    expect(DdevTranscript::for('alpha-site')->lines()->pluck('text')->implode("\n"))
        ->toContain('$ ddev start --skip-confirmation alpha-site')
        ->toContain('exited 0 after');
});

it('shows a project\'s transcript in the popup', function () {
    $transcript = DdevTranscript::for('alpha-site');

    $transcript->append('$ ddev start --skip-confirmation alpha-site', DdevTranscriptLineKind::Command);
    $transcript->append('Running post-start hook: npm run serve', DdevTranscriptLineKind::Output);
    $transcript->flush();

    Livewire::test(DdevProjectList::class)
        ->call('showLogs', 'alpha-site')
        ->assertSet('viewingLogsFor', 'alpha-site')
        ->assertSee('$ ddev start --skip-confirmation alpha-site')
        ->assertSee('Running post-start hook: npm run serve')
        // The panel takes over the list rather than sitting beside it.
        ->assertDontSee('Running only')
        ->call('hideLogs')
        ->assertSet('viewingLogsFor', null)
        ->assertSee('Running only');
});

it('says so when a project has printed nothing yet', function () {
    Livewire::test(DdevProjectList::class)
        ->call('showLogs', 'alpha-site')
        ->assertSee('Nothing recorded for this project yet.');
});

it('shows how long a command has been stuck', function () {
    // A command still attached to a post-start hook prints nothing more, so the
    // panel has to say that it is still going rather than look finished.
    $transcript = DdevTranscript::for('alpha-site');
    $transcript->append('Running post-start hook: npm run serve', DdevTranscriptLineKind::Output);
    $transcript->flush();

    app(DdevState::class)->putOperation(
        DdevOperationState::queued('alpha-site', DdevOperation::Start)->running()
    );

    Livewire::test(DdevProjectList::class)
        ->call('showLogs', 'alpha-site')
        ->assertSee('Starting for');
});

it('opens the transcript of a project the snapshot lists, and no other', function () {
    // The name arrives from the browser and picks a cache key.
    Livewire::test(DdevProjectList::class)
        ->call('showLogs', '../../etc/passwd')
        ->assertSet('viewingLogsFor', null);
});

it('polls fast enough to read as a tail while the panel is open', function () {
    $component = Livewire::test(DdevProjectList::class);

    expect($component->instance()->pollInterval())->toBe((int) config('ddev.idle_poll_interval'));

    $component->call('showLogs', 'alpha-site');

    expect($component->instance()->pollInterval())->toBe((int) config('ddev.poll_interval'));
});

it('opens the log view when a command is run from a row', function () {
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'start')
        ->assertSet('viewingLogsFor', 'alpha-site')
        // The command has printed nothing yet, and "nothing recorded" would be
        // the wrong thing to say about a project that is starting.
        ->assertDontSee('Nothing recorded for this project yet.')
        ->assertSee('Starting for');
});

it('opens the log view only once a delete is confirmed', function () {
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'delete')
        ->assertSet('confirmingDeleteFor', 'alpha-site')
        // The first click asks the question, and the question is on the row.
        ->assertSet('viewingLogsFor', null)
        ->call('runOperation', 'alpha-site', 'delete')
        ->assertSet('viewingLogsFor', 'alpha-site');
});

it('leaves the log view alone for a command it refuses to run', function () {
    Livewire::test(DdevProjectList::class)
        ->call('runOperation', 'alpha-site', 'poweroff')
        ->assertSet('viewingLogsFor', null);
});

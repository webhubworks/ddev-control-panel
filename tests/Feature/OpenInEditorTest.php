<?php

use App\Livewire\DdevProjectList;
use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevState;
use App\Support\Editor\InstalledEditors;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Native\Desktop\Facades\Settings;

beforeEach(function () {
    Process::fake();
    Queue::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));

    $this->root = sys_get_temp_dir().'/open-in-editor-'.bin2hex(random_bytes(6));
    $this->applications = $this->root.'/Applications';
    $this->phpStorm = $this->applications.'/PhpStorm.app';
    $this->project = $this->root.'/alpha-site';

    mkdir($this->phpStorm, recursive: true);
    mkdir($this->applications.'/Zed.app');
    mkdir($this->project);

    $this->app->instance(InstalledEditors::class, new InstalledEditors([$this->applications]));

    // Stopped on purpose: an editor does not need the containers.
    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha-site', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'laravel', 'primary_url' => 'https://alpha-site.ddev.site', 'approot' => $this->project, 'shortroot' => '~/alpha-site'],
    ]);
});

function withEditor(?string $path): void
{
    Settings::shouldReceive('get')->with('editor')->andReturn($path);
}

it('offers the open menu on a stopped project, with only the editor entry usable', function () {
    $html = Livewire::test(DdevProjectList::class)
        ->assertSee('Open in IDE…')
        ->html();

    expect($html)
        ->toMatch('/wire:click="openInEditor\(\'alpha-site\'\)"(?:(?!<\/button>).)*?class=/s')
        ->and($html)->toMatch('/wire:click="openUrl\(\'alpha-site\'\)"[^>]*disabled/s');
});

it('names the chosen editor in the menu', function () {
    withEditor($this->phpStorm);

    Livewire::test(DdevProjectList::class)
        ->assertSee('Open in PhpStorm')
        ->assertDontSee('Open in IDE…');
});

it('opens the project directory in the chosen editor', function () {
    withEditor($this->phpStorm);

    Livewire::test(DdevProjectList::class)
        ->call('openInEditor', 'alpha-site')
        ->assertSet('showingSettings', false);

    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/open', '-a', $this->phpStorm, $this->project]);
    Queue::assertNothingPushed();
});

it('asks for an editor first, then opens the project once one is chosen', function () {
    Settings::shouldReceive('set')->once()->with('editor', $this->phpStorm);

    $component = Livewire::test(DdevProjectList::class)
        ->call('openInEditor', 'alpha-site')
        ->assertSet('showingSettings', true)
        ->assertSet('openAfterChoosingEditor', 'alpha-site')
        ->assertSee('PhpStorm')
        ->assertSee('Zed');

    Process::assertNothingRan();

    $component->call('chooseEditor', $this->phpStorm)
        ->assertSet('editor', $this->phpStorm)
        ->assertSet('showingSettings', false)
        ->assertSet('openAfterChoosingEditor', null);

    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/open', '-a', $this->phpStorm, $this->project]);
});

it('asks again when the chosen editor has been deleted', function () {
    withEditor($this->applications.'/Uninstalled.app');

    Livewire::test(DdevProjectList::class)
        ->assertSee('Open in IDE…')
        ->call('openInEditor', 'alpha-site')
        ->assertSet('showingSettings', true);

    Process::assertNothingRan();
});

it('stays on the settings panel when the editor is changed from there', function () {
    Settings::shouldReceive('set')->once()->with('editor', $this->phpStorm);

    Livewire::test(DdevProjectList::class)
        ->call('showSettings')
        ->call('chooseEditor', $this->phpStorm)
        ->assertSet('showingSettings', true);

    Process::assertNothingRan();
});

it('only accepts an editor it offered', function () {
    // The path arrives from the browser and ends up on a command line.
    Settings::shouldReceive('set')->never();

    Livewire::test(DdevProjectList::class)
        ->call('chooseEditor', '/System/Applications/Terminal.app')
        ->assertSet('editor', null);
});

it('never lets the browser write the editor directly', function () {
    Livewire::test(DdevProjectList::class)->set('editor', '/System/Applications/Terminal.app');
})->throws(CannotUpdateLockedPropertyException::class);

it('takes any other app from the file dialog', function () {
    $other = $this->applications.'/Custom Editor.app';
    mkdir($other);

    Http::fake(['*dialog/open' => Http::response(['result' => [$other]])]);
    Settings::shouldReceive('set')->once()->with('editor', $other);

    Livewire::test(DdevProjectList::class)
        ->call('showSettings')
        ->call('chooseOtherEditor')
        ->assertSet('editor', $other)
        // Not on the detected list, but it is the current choice, so it is shown.
        ->assertSee('Custom Editor');
});

it('ignores a dialog that was cancelled or returned something other than an app', function (array $result) {
    Http::fake(['*dialog/open' => Http::response(['result' => $result])]);
    Settings::shouldReceive('set')->never();

    Livewire::test(DdevProjectList::class)
        ->call('chooseOtherEditor')
        ->assertSet('editor', null);
})->with([
    'cancelled' => [[]],
    'not an app' => [['/etc/hosts']],
]);

it('forgets the editor on reset', function () {
    withEditor($this->phpStorm);
    Settings::shouldReceive('forget')->once()->with('editor');

    Livewire::test(DdevProjectList::class)
        ->call('forgetEditor')
        ->assertSet('editor', null)
        ->assertSee('Open in IDE…');
});

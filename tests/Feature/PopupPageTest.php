<?php

use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevState;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Livewire::test() renders the component without its layout, so it cannot catch
 * a broken layout. These hit the route the popup actually loads.
 */
beforeEach(function () {
    Process::fake();
    Queue::fake();

    $this->app->instance(DdevBinary::class, new DdevBinary('/usr/bin/true'));
});

it('renders the popup page through its layout', function () {
    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha-site', 'status' => 'running', 'status_desc' => 'running', 'type' => 'laravel', 'primary_url' => 'https://alpha-site.ddev.site', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('DDEV Control')
        ->assertSee('alpha-site')
        ->assertSee('OK')
        // The layout has to actually wrap the component.
        ->assertSee('<!DOCTYPE html>', escape: false)
        ->assertSee('</body>', escape: false);
});

it('renders before any snapshot exists', function () {
    // First launch: the background scan has not answered yet and the page still
    // has to come up rather than 500.
    $this->get('/')->assertOk()->assertSee('DDEV Control');
});

it('renders the icons as inline svg rather than emoji', function () {
    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha-site', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'laravel', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
    ]);

    $response = $this->get('/')->assertOk();

    expect($response->content())
        ->toContain('<svg')
        ->toContain('aria-label="Start alpha-site"')
        ->toContain('aria-label="Delete alpha-site"');
});

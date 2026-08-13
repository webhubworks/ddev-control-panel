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
        ->assertSee('DDEV Control Panel')
        ->assertSee('alpha-site')
        ->assertSee('OK')
        // The layout has to actually wrap the component.
        ->assertSee('<!DOCTYPE html>', escape: false)
        ->assertSee('</body>', escape: false);
});

it('renders before any snapshot exists', function () {
    // First launch: the background scan has not answered yet and the page still
    // has to come up rather than 500.
    $this->get('/')->assertOk()->assertSee('DDEV Control Panel');
});

it('opens with focus in the search field, not on an action button', function () {
    // The header comes first in the DOM, so left alone the window focuses the
    // refresh button: it shows a focus ring for no reason, and a stray Space or
    // Enter fires it with delete one tab away.
    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha-site', 'status' => 'stopped', 'status_desc' => 'stopped', 'type' => 'laravel', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
    ]);

    $content = $this->get('/')->assertOk()->content();

    // Exactly one autofocus target, and it is the search input.
    expect(substr_count($content, 'autofocus'))->toBe(1);

    preg_match('/<input\b[^>]*type="search"[^>]*>/', $content, $matches);

    expect($matches)->not->toBeEmpty()
        ->and($matches[0])->toContain('autofocus')
        ->and($matches[0])->toContain('x-ref="search"');

    // And focus is re-placed every time the popup comes back to the front,
    // because the menubar window is hidden rather than destroyed.
    expect($content)
        ->toContain('$refs.search?.focus()')
        ->toContain('x-on:focus.window');
});

it('wires the open menu to a popover that actually exists', function () {
    // A popovertarget that names no element renders a button which silently
    // does nothing, and the dot in the project name is the way to break it:
    // it is legal in a ddev name and illegal in a CSS ident, so the anchor
    // cannot simply be the name.
    app(DdevState::class)->putSnapshot([
        ['name' => 'alpha.site', 'status' => 'running', 'status_desc' => 'running', 'type' => 'laravel', 'primary_url' => 'https://alpha.site.ddev.site', 'approot' => '/reps/alpha-site', 'shortroot' => '~/reps/alpha-site'],
    ]);

    $content = $this->get('/')->assertOk()->content();

    preg_match('/popovertarget="([^"]+)"/', $content, $target);

    expect($target)->not->toBeEmpty()
        ->and($content)->toContain('id="'.$target[1].'"');

    preg_match('/anchor-name: (--[^;"]+)/', $content, $anchor);

    expect($anchor)->not->toBeEmpty()
        ->and($anchor[1])->toMatch('/^--[\w-]+$/')
        ->and($content)->toContain('position-anchor: '.$anchor[1]);
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

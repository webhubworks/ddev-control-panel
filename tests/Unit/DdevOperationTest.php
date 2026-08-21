<?php

use App\Enums\DdevOperation;

/**
 * These flags are verified against `ddev <command> --help` on v1.25.3. Getting
 * one wrong does not fail loudly: ddev either blocks on a prompt that nobody
 * can answer or rejects the flag, so pin them.
 */
it('uses the confirmation flag each ddev subcommand actually accepts', function () {
    expect(DdevOperation::Start->arguments('example'))
        ->toBe(['start', '--skip-confirmation', 'example'])
        ->and(DdevOperation::Restart->arguments('example'))
        ->toBe(['restart', '--skip-confirmation', 'example'])
        // `ddev delete` spells it --yes, not --skip-confirmation.
        ->and(DdevOperation::Delete->arguments('example'))
        ->toBe(['delete', '--yes', 'example'])
        // `ddev stop` has no confirmation prompt to skip.
        ->and(DdevOperation::Stop->arguments('example'))
        ->toBe(['stop', 'example']);
});

it('never passes --omit-snapshot, so a delete stays recoverable', function () {
    expect(DdevOperation::Delete->arguments('example'))
        ->not->toContain('--omit-snapshot')
        ->not->toContain('-O');
});

it('never lets a command act on every project by accident', function (DdevOperation $operation) {
    // -a / --all would act on every project on the machine.
    expect($operation->arguments('example'))
        ->not->toContain('--all')
        ->not->toContain('-a');
})->with(DdevOperation::cases());

it('scopes every per-project command to a single project', function (DdevOperation $operation) {
    expect($operation->arguments('example'))->toContain('example');
})->with(array_filter(
    DdevOperation::cases(),
    fn (DdevOperation $operation): bool => ! $operation->isGlobal(),
));

it('runs poweroff with no arguments at all', function () {
    // `ddev poweroff` takes no project and no flags; passing one would error.
    expect(DdevOperation::Poweroff->arguments('example'))->toBe(['poweroff'])
        ->and(DdevOperation::Poweroff->isGlobal())->toBeTrue()
        // It stops containers, it does not remove data.
        ->and(DdevOperation::Poweroff->isDestructive())->toBeFalse();
});

it('treats only poweroff as machine wide', function () {
    $global = array_values(array_filter(
        DdevOperation::cases(),
        fn (DdevOperation $operation): bool => $operation->isGlobal(),
    ));

    expect($global)->toBe([DdevOperation::Poweroff]);
});

it('allows container pulls enough time on start and restart', function () {
    expect(DdevOperation::Start->timeout())->toBeGreaterThanOrEqual(600)
        ->and(DdevOperation::Restart->timeout())->toBeGreaterThanOrEqual(600);
});

it('marks only delete as destructive', function () {
    expect(DdevOperation::Delete->isDestructive())->toBeTrue()
        ->and(DdevOperation::Stop->isDestructive())->toBeFalse()
        ->and(DdevOperation::Start->isDestructive())->toBeFalse()
        ->and(DdevOperation::Restart->isDestructive())->toBeFalse();
});

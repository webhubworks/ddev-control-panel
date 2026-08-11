<?php

/**
 * The published Electron project under nativephp/electron is ours to edit, but
 * composer's post-update-cmd runs `native:install --publish`, which mirrors the
 * vendor copy over it with override + delete. Removing the script from
 * composer.json does not help: InstallCommand re-adds it whenever
 * nativephp/electron/package.json exists.
 *
 * Losing any of these settings is silent, so they are asserted here. If one of
 * these tests fails after a `composer update`, run `git diff nativephp/electron`
 * and restore the file.
 */
function electronPath(string $relative): string
{
    return base_path('nativephp/electron/'.$relative);
}

it('keeps the productName that decides where user data lives', function () {
    $builder = file_get_contents(electronPath('electron-builder.mjs'));

    // Electron prefers productName over name for app.getName(), and
    // app.getName() is app.getPath('userData'). Without this the database, logs
    // and recorded migrated_version move to a different directory and the app
    // looks freshly installed.
    expect($builder)->toContain('productName: appName');
});

it('keeps the entitlement an ad-hoc signed build needs to launch', function () {
    $entitlements = file_get_contents(electronPath('build/entitlements.mac.plist'));

    // Hardened runtime enables Library Validation, which requires every loaded
    // library to share the main executable's Team ID. An ad-hoc signature has
    // none, so without this the app dies before main().
    expect($entitlements)->toContain('com.apple.security.cs.disable-library-validation');
})->skip(
    fn (): bool => filled(env('NATIVEPHP_APPLE_TEAM_ID')),
    'Signed with a real Developer ID, so library validation can stay on.'
);

it('ships the icons in public, which is where NativePHP reads them from', function () {
    // InstallsAppIcon copies these out of public/ on every native:run and
    // native:build, into the Electron project and into the vendor runtime build
    // directory. Anything written directly to those directories is overwritten,
    // so public/ is the only place worth asserting. Without IconTemplate.png the
    // tray silently falls back to NativePHP's own logo.
    expect(public_path('icon.png'))->toBeFile()
        ->and(public_path('IconTemplate.png'))->toBeFile()
        ->and(public_path('IconTemplate@2x.png'))->toBeFile();
});

it('keeps the app icon at the size electron-builder needs', function () {
    $image = imagecreatefrompng(public_path('icon.png'));

    expect(imagesx($image))->toBe(1024)
        ->and(imagesy($image))->toBe(1024);

    // Also committed into the published project: that is electron-builder's
    // buildResources directory and the source of the packaged .icns.
    expect(electronPath('build/icon.png'))->toBeFile();
});

it('keeps the tray icons as macOS template images', function (string $file, int $size) {
    // A template image is a black-plus-alpha mask that macOS recolours for the
    // light and dark menu bar. Without an alpha channel it renders as a block.
    $image = imagecreatefrompng(public_path($file));

    expect(imagesx($image))->toBe($size)
        ->and(imagesy($image))->toBe($size);

    // The corners sit outside the glyph and must be fully transparent.
    expect((imagecolorat($image, 0, 0) >> 24) & 0x7F)->toBe(127);

    // And the glyph itself has to actually be there.
    $opaque = 0;

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 40) {
                $opaque++;
            }
        }
    }

    expect($opaque)->toBeGreaterThan($size);
})->with([
    ['IconTemplate.png', 16],
    ['IconTemplate@2x.png', 32],
]);

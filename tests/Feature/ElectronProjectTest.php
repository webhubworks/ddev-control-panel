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
});

it('keeps the published app icon in step with public/icon.png', function () {
    // electron-builder derives the packaged .icns from the published project's
    // buildResources directory. InstallsAppIcon means to refresh it but resolves
    // electronPath('build/icon.png'), which looks for a package.json inside
    // build/, fails, and falls back to the vendor copy. So this file is ours to
    // keep in sync, and `native:install --publish` puts the NativePHP logo back.
    // A build does not fail when that happens: it just ships the wrong icon.
    expect(electronPath('build/icon.png'))->toBeFile();

    expect(md5_file(electronPath('build/icon.png')))
        ->toBe(md5_file(public_path('icon.png')));
});

it('keeps the tray icons as macOS template images', function (string $file, int $size) {
    // 22x22 and 44x44 are the upstream tray sizes; anything else is scaled by
    // the system and looks soft.
    $image = imagecreatefrompng(public_path($file));

    expect(imagesx($image))->toBe($size)
        ->and(imagesy($image))->toBe($size);

    // A template image is read through its alpha channel only and tinted by the
    // system, so it must be black over transparency with no background plate.
    // Downscaling the app icon into it yields an opaque square instead.
    expect((imagecolorat($image, 0, 0) >> 24) & 0x7F)->toBe(127);

    $opaque = 0;
    $coloured = 0;

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $pixel = imagecolorat($image, $x, $y);

            if ((($pixel >> 24) & 0x7F) > 110) {
                continue;
            }

            $opaque++;

            if ((($pixel >> 16) & 0xFF) > 32 || (($pixel >> 8) & 0xFF) > 32 || ($pixel & 0xFF) > 32) {
                $coloured++;
            }
        }
    }

    // The glyph is present, does not fill the whole frame (it needs the same
    // margin upstream leaves), and is black rather than tinted.
    expect($opaque)->toBeGreaterThan($size)
        ->and($opaque)->toBeLessThan($size * $size * 0.75)
        ->and($coloured)->toBe(0);
})->with([
    ['IconTemplate.png', 22],
    ['IconTemplate@2x.png', 44],
]);

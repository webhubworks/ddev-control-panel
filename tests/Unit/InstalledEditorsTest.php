<?php

use App\Support\Editor\InstalledEditors;

function applicationsFolder(array $apps): string
{
    $directory = sys_get_temp_dir().'/installed-editors-'.bin2hex(random_bytes(6));

    foreach ($apps as $app) {
        mkdir($directory.'/'.$app, recursive: true);
    }

    return $directory;
}

it('lists the known editors that are installed, in a fixed order', function () {
    $applications = applicationsFolder(['Zed.app', 'PhpStorm.app', 'Safari.app']);

    expect((new InstalledEditors([$applications]))->all())->toBe([
        $applications.'/PhpStorm.app' => 'PhpStorm',
        $applications.'/Zed.app' => 'Zed',
    ]);
});

it('takes an editor from the first folder that has it', function () {
    $system = applicationsFolder(['PhpStorm.app']);
    $user = applicationsFolder(['PhpStorm.app', 'Cursor.app']);

    expect((new InstalledEditors([$system, $user]))->all())->toBe([
        $system.'/PhpStorm.app' => 'PhpStorm',
        $user.'/Cursor.app' => 'Cursor',
    ]);
});

it('names an app after its bundle', function () {
    expect(InstalledEditors::nameOf('/Applications/Visual Studio Code.app'))->toBe('Visual Studio Code');
});

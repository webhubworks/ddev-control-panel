<?php

namespace App\Providers;

use App\Jobs\RefreshDdevProjectsJob;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     *
     * This app is menubar only: no Window::open() call, and no dock icon, so
     * the tray item is the single entry point.
     */
    public function boot(): void
    {
        // No ->icon() call: NativePHP resolves the tray image to
        // build/IconTemplate.png, which InstallsAppIcon copies out of public/.
        // As a template image macOS paints it in the menu bar's label colour, so
        // it is white on a dark bar, dark on a light one, and inverts while the
        // popup is open.
        MenuBar::create()
            ->label('')
            ->tooltip('DDEV Control Panel')
            ->width(440)
            ->height(560)
            ->showDockIcon(false)
            ->withContextMenu(
                Menu::make(
                    Menu::label('DDEV Control Panel'),
                    Menu::separator(),
                    Menu::quit('Quit DDEV Control Panel'),
                )
            );

        // Warm the project list before the user first opens the popup, so it is
        // not staring at a skeleton while `ddev list` takes its several seconds.
        RefreshDdevProjectsJob::dispatch();

        $this->watchDdevEvents();
    }

    /**
     * Listen to Docker for the rest of the app's life, so that starting a
     * project from a terminal is reflected here within seconds.
     *
     * Persistent, so Electron restarts it if it dies. It costs nothing while
     * idle: the process sits on a blocking `docker events` stream and does no
     * work until Docker has something to report.
     */
    private function watchDdevEvents(): void
    {
        if (! config('ddev.watch.enabled')) {
            return;
        }

        ChildProcess::artisan(
            'ddev:watch',
            alias: 'ddev-watch',
            persistent: true,
            // The stream never ends on purpose, so the default execution limit
            // would kill the watcher a minute in.
            iniSettings: ['max_execution_time' => '0'],
        );
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            // ddev commands are shelled out to and can run for minutes; the
            // queue worker must not be cut short mid-start.
            'max_execution_time' => '0',
        ];
    }
}

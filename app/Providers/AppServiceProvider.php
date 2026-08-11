<?php

namespace App\Providers;

use App\Support\Ddev\DdevBinary;
use App\Support\Ddev\DdevState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the binary is located once per process, not per call.
        $this->app->singleton(
            DdevBinary::class,
            fn (): DdevBinary => new DdevBinary(config('ddev.binary_path')),
        );

        $this->app->singleton(
            DdevState::class,
            fn (): DdevState => new DdevState(Cache::store()),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

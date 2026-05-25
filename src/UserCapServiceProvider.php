<?php

namespace Webpatser\ResonateUserCap;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the user-cap plugin into a host Laravel application.
 *
 * The {@see PresenceCapPlugin} itself is not bound here: Resonate instantiates
 * it from the `plugins` array in `config/reverb.php`.
 */
class UserCapServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/resonate-user-cap.php', 'resonate-user-cap');
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/resonate-user-cap.php' => $this->app->configPath('resonate-user-cap.php'),
            ], 'resonate-user-cap-config');
        }
    }
}

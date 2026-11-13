<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // exists / unique validation rules are scoped to the current academy.
        $this->app->extend('validation.presence', fn ($v, $app) => new \App\Validation\TenantPresenceVerifier($app['db']));
        $this->app['validator']->setPresenceVerifier(new \App\Validation\TenantPresenceVerifier($this->app['db']));
    }
}

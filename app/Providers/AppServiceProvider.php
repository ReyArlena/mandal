<?php

namespace App\Providers;

use App\Ssh\PhpseclibSshClient;
use App\Ssh\SshClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SshClient::class, PhpseclibSshClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('setup', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }
}

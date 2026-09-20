<?php

namespace App\Providers;

use App\Ssh\PhpseclibSshClient;
use App\Ssh\SshClient;
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
        //
    }
}

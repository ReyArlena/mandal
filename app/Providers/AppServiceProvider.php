<?php

namespace App\Providers;

use App\Mandal\ComposeImport;
use App\Ssh\PhpseclibSshClient;
use App\Ssh\SshClient;
use BladeUI\Icons\Factory as IconFactory;
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

        // Mandal's own icons, such as mandal-laravel and mandal-docker.
        $this->callAfterResolving(IconFactory::class, fn (IconFactory $icons) => $icons->add('mandal', [
            'path' => resource_path('svg'),
            'prefix' => 'mandal',
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(30)->by($request->route('token') ?? $request->ip()));

        // Backup imports are the panel's only uploads; Livewire's own limit
        // (12 MB, five minutes) would refuse them long before PHP does.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.intdiv(ComposeImport::configuredUploadBytes(), 1024)],
            'livewire.temporary_file_upload.max_upload_time' => 30,
        ]);
    }
}

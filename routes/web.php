<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ServerSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The panel itself is served by Filament at the site root (see
| App\Providers\Filament\AdminPanelProvider). The routes here are the few
| that servers and source providers call without a signed-in user.
|
*/

Route::middleware('throttle:setup')->prefix('setup')->name('setup.')->group(function (): void {
    Route::get('{server}/{token}', [ServerSetupController::class, 'script'])->name('script');
    Route::get('{server}/{token}/cli.tar.gz', [ServerSetupController::class, 'cli'])->name('cli');
});

Route::post('webhooks/github/{token}', GitHubWebhookController::class)
    ->middleware('throttle:webhooks')
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('webhooks.github');

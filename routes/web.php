<?php

use App\Http\Controllers\DetailsPageController;
use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The panel itself is served by Filament at the site root (see
| App\Providers\Filament\AdminPanelProvider), which also registers the app
| backup download route inside its authenticated group so it carries the
| panel's sign-in and multi-factor checks. The routes here are the few
| reached without a signed-in user: webhooks from source providers and
| the server details pages shown to players.
|
*/

Route::post('webhooks/github/{token}', GitHubWebhookController::class)
    ->middleware('throttle:webhooks')
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('webhooks.github');

Route::get('p/{slug}', DetailsPageController::class)
    ->middleware('throttle:details-pages')
    ->where('slug', '[a-z0-9-]{1,100}')
    ->name('details-pages.show');

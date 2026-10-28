<?php

namespace App\Providers\Filament;

use App\Filament\Catppuccin;
use App\Filament\Pages\Settings;
use App\Http\Controllers\AppBackupDownloadController;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->brandName(config('app.name'))
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->login()
            ->profile()
            ->databaseNotifications()
            ->userMenuItems([
                Action::make('settings')
                    ->label('Settings')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => Settings::getUrl()),
            ])
            ->navigation(false)
            ->breadcrumbs(false)
            ->renderHook(PanelsRenderHook::PAGE_START, fn (): View => view('filament.server-navigation'))
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn (): View => view('filament.server-switcher'))
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            // A plain download link cannot be a Livewire action, so it is a
            // route inside the panel's authenticated group, behind the same
            // sign-in and multi-factor middleware as every page.
            ->authenticatedRoutes(fn (Panel $panel) => Route::get('servers/{server}/apps/{app}/backups/{file}', AppBackupDownloadController::class)
                ->middleware($panel->isMultiFactorAuthenticationRequired() ? [$panel->getMultiFactorAuthenticationRequiredMiddleware()] : [])
                ->where(['app' => '[a-z][a-z0-9-]{1,31}', 'file' => '[a-z][a-z0-9-]{1,31}-[0-9]{14}\.tar\.zst'])
                ->name('servers.apps.backups.download'))
            ->colors([
                'primary' => Catppuccin::Red,
                'danger' => Catppuccin::Red,
                'gray' => Catppuccin::Mocha,
                'info' => Catppuccin::Sapphire,
                'success' => Catppuccin::Green,
                'warning' => Catppuccin::Peach,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

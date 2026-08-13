<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RecordVisit;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // The profile page is where a user turns two-factor on or off for
            // their own account, so enabling MFA without it leaves the feature
            // unreachable. isSimple: false keeps the panel chrome around it
            // rather than rendering it as a bare standalone page.
            ->profile(isSimple: false)
            // isRequired stays false: each user decides for themselves. Flip it
            // to true and everyone without a secret is redirected to set one up
            // before they can reach any page.
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    // Without recovery codes a lost phone locks the account out
                    // permanently — and for the last super admin there is no one
                    // left who can clear it.
                    ->recoverable(),
                isRequired: false,
            )
            ->colors([
                'primary' => Color::Amber,
            ])
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
                // The panel does not use the `web` middleware group, so the
                // visit recorder has to be listed here as well or nothing
                // under /admin is ever tracked. It sits in the base stack
                // rather than authMiddleware() so anonymous hits on the login
                // page are recorded too.
                RecordVisit::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

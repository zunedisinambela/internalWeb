<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
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
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\View\View;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // The panel is the whole app, so it owns the root path rather than
            // sitting under a segment. `id` stays 'admin' — it names the panel
            // and its route names (filament.admin.*), not the URL. Nothing may
            // claim `/` in routes/web.php while this is empty.
            ->path('')
            // The custom page accepts a username as well as an email address.
            // It sits outside app/Filament/Pages so it stays independent of
            // whether a given auth page is a Page subclass discovery would
            // pick up — see the class docblock.
            ->login(Login::class)
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
            // The cash book export runs on the queue, so the file is finished
            // after the request that asked for it has ended. A flash message
            // cannot reach the user by then; a database notification can, and
            // it survives a closed tab. See App\Jobs\ExportCashBook.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
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
                // inside the panel is ever tracked. It sits in the base stack
                // rather than authMiddleware() so anonymous hits on the login
                // page are recorded too.
                RecordVisit::class,
            ])
            // Panel CSS cannot go through the app's Vite build — Filament serves
            // its own compiled stylesheet. STYLES_AFTER puts these rules after
            // it, so they win on order, and a render hook has no publish step a
            // deploy could skip. Currently: horizontal table scrolling on
            // narrow screens.
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): View => view('filament.panel-styles'),
            )
            // Click-to-zoom for image entries. It hangs off a `data-lightbox`
            // attribute rather than a custom entry class, so opting a screen in
            // is one ->extraAttributes() call. BODY_END puts it after Filament's
            // own scripts, which is where Alpine has already been booted.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('filament.lightbox'),
            )
            // Manifest, icons and the service worker registration, which turn
            // the panel into something installable to an Android or iOS home
            // screen. HEAD_END rather than BODY_END: a manifest discovered
            // after the page has rendered is a manifest the install prompt has
            // already decided without. See docs/pwa.md.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('filament.pwa'),
            )
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

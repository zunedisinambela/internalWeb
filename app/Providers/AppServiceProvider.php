<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        $this->registerLogViewerGate();
    }

    /**
     * Gate access to /log-viewer.
     *
     * Without this gate opcodesio/log-viewer only locks itself down when
     * APP_ENV is exactly "production" (see AuthorizeLogViewer middleware),
     * leaving staging and any other environment wide open.
     *
     * The rule matches User::canAccessPanel() on purpose. Raw log files carry
     * stack traces, request payloads and email addresses, so anyone refused by
     * the admin panel must be refused here too — otherwise the weaker gate
     * becomes the way around the stronger one.
     */
    protected function registerLogViewerGate(): void
    {
        Gate::define('viewLogViewer', fn (?User $user): bool => (bool) $user?->is_admin);
    }
}

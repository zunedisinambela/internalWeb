<?php

namespace App\Providers;

use App\Listeners\LogRoleChange;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

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
        $this->registerVendorModelPolicies();
        $this->registerLogViewerGate();
        $this->registerRoleChangeAuditing();
    }

    /**
     * Roles are the privilege system, so grants and revocations belong in the
     * audit log. They are not model attributes, so LogsActivity cannot see
     * them; spatie/laravel-permission emits events instead (only when
     * permission.events_enabled is true).
     */
    protected function registerRoleChangeAuditing(): void
    {
        Event::listen(RoleAttachedEvent::class, [LogRoleChange::class, 'attached']);
        Event::listen(RoleDetachedEvent::class, [LogRoleChange::class, 'detached']);
    }

    /**
     * Laravel discovers policies by mapping App\Models\X to App\Policies\XPolicy.
     * Activity lives in a vendor namespace, so its Shield-generated policy is
     * never found automatically and every permission check on it would silently
     * pass. Register it explicitly.
     */
    protected function registerVendorModelPolicies(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);
    }

    /**
     * Gate access to /log-viewer.
     *
     * Without this gate opcodesio/log-viewer only locks itself down when
     * APP_ENV is exactly "production" (see AuthorizeLogViewer middleware),
     * leaving staging and any other environment wide open.
     *
     * The rule matches User::canAccessPanel() on purpose — holding any role.
     * Raw log files carry stack traces, request payloads and email addresses,
     * so anyone refused by the admin panel must be refused here too, otherwise
     * the weaker gate becomes the way around the stronger one.
     */
    protected function registerLogViewerGate(): void
    {
        Gate::define('viewLogViewer', fn (?User $user): bool => (bool) $user?->roles()->exists());
    }
}

<?php

namespace App\Providers;

use App\Listeners\LogRoleChange;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $this->setCarbonLocale();
        $this->registerVendorModelPolicies();
        $this->registerLogViewerGate();
        $this->registerRoleChangeAuditing();
        $this->registerActivityDeletionLogging();
    }

    /**
     * Writes every activity log deletion to the application log.
     *
     * The activity log is where deletions on the other monitoring screens are
     * recorded, so once entries here became deletable there had to be somewhere
     * one tier further up for that to land. Recording it back into the activity
     * log would be circular — the record could be deleted with the same button.
     * The log file cannot be touched from the panel, which makes it the end of
     * the chain; it is readable at /log-viewer under the same role check.
     *
     * Logged at warning level so it stands out among request noise. Hooked on
     * the model rather than the Filament action so tinker and console deletes
     * are covered too; a query builder delete still fires no events, which is
     * why PruneMonitoring writes its own file log line when it expires entries.
     */
    protected function registerActivityDeletionLogging(): void
    {
        Activity::deleted(function (Activity $activity): void {
            Log::warning('Activity log entry deleted', [
                'activity_id' => $activity->getKey(),
                'log_name' => $activity->log_name,
                'event' => $activity->event,
                'description' => $activity->description,
                'subject' => $activity->subject_type ? $activity->subject_type.'#'.$activity->subject_id : null,
                'occurred_at' => $activity->created_at?->toDateTimeString(),
                'deleted_by' => Auth::user()?->email ?? 'console',
            ]);
        });
    }

    /**
     * Carbon does not follow app.locale on its own — Laravel dispatches
     * LocaleUpdated but ships no listener for it. Without this, every
     * diffForHumans() reads "2 minutes ago" and every translatedFormat() prints
     * English month names, while the rest of the panel is Indonesian.
     */
    protected function setCarbonLocale(): void
    {
        Carbon::setLocale(config('app.locale'));
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

<?php

namespace App\Models;

use Binafy\LaravelUserMonitoring\Models\AuthenticationMonitoring as BaseAuthenticationMonitoring;

/**
 * App-side subclass of the package's authentication model.
 *
 * Exists for the same reason as VisitMonitoring: policy discovery only reaches
 * classes under App\Models.
 *
 * Rows are written by LaravelUserMonitoringEventServiceProvider, which listens
 * for Illuminate\Auth\Events\Login and Logout. Those fire for every guard, so
 * this table catches panel logins even though the panel has its own middleware
 * stack.
 */
class AuthenticationMonitoring extends BaseAuthenticationMonitoring
{
    /**
     * Records every deletion in the activity log.
     *
     * The panel can delete sign-ins, and this is the record of who had access
     * and when — so a removal has to leave something behind that the person
     * doing it cannot also remove. ActivityResource refuses create, edit and
     * delete outright, which makes the activity log that place. The properties
     * keep the user, the event and the address, so what was erased is still
     * answerable afterwards.
     *
     * Hooked to the `deleted` model event rather than to the Filament actions,
     * so tinker and console commands are covered too. Mass deletion through the
     * query builder fires no events — that is why DeleteBulkAction is pinned to
     * per-record deletes, and why PruneMonitoring writes its own summary entry
     * instead of relying on this.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $signIn): void {
            activity('monitoring')
                ->performedOn($signIn)
                ->event('sign_in_deleted')
                ->withProperties([
                    'sign_in_id' => $signIn->getKey(),
                    'action_type' => $signIn->action_type,
                    'ip' => $signIn->ip,
                    'occurred_at' => $signIn->created_at?->toDateTimeString(),
                    'user_id' => $signIn->user_id,
                ])
                ->log('Data riwayat masuk dihapus');
        });
    }

    /**
     * See VisitMonitoring::getTable().
     */
    public function getTable(): string
    {
        return config('user-monitoring.authentication_monitoring.table', parent::getTable());
    }
}

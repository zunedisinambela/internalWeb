<?php

namespace App\Models;

use Binafy\LaravelUserMonitoring\Models\VisitMonitoring as BaseVisitMonitoring;

/**
 * App-side subclass of the package's visit model.
 *
 * Laravel guesses a policy by swapping \Models\ for \Policies\ in the class
 * name, which only works for classes under App\Models. Pointing the Filament
 * resource at the vendor class instead would mean registering the policy by
 * hand in AppServiceProvider — the same trap ActivityPolicy fell into — and a
 * missed registration makes every permission check silently pass. Subclassing
 * keeps Shield's generated policy wired up automatically.
 */
class VisitMonitoring extends BaseVisitMonitoring
{
    /**
     * Records every deletion in the activity log.
     *
     * The panel can delete visits, so without this a person with the right
     * permission could erase their own visits and leave nothing behind. The
     * activity log is the one place they cannot reach — ActivityResource
     * refuses create, edit and delete outright — so the removal survives even
     * when the visit does not. The properties keep enough of the row to say
     * what was erased.
     *
     * Hooked to the `deleted` model event rather than to the Filament actions,
     * so it also covers tinker, a console command or any future screen. The
     * blind spot is mass deletion: a query builder delete fires no events,
     * which is why DeleteBulkAction is pinned to per-record deletes.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $visit): void {
            activity('monitoring')
                ->performedOn($visit)
                ->event('visit_deleted')
                ->withProperties([
                    'visit_id' => $visit->getKey(),
                    'page' => $visit->page,
                    'ip' => $visit->ip,
                    'visited_at' => $visit->created_at?->toDateTimeString(),
                    'visited_by' => $visit->user_id,
                ])
                ->log('Data kunjungan dihapus');
        });
    }

    /**
     * The parent hardcodes $table while the middleware inserts into the table
     * named in config, so the two drift apart if that key is ever changed.
     * Reading the same key here keeps them pointed at one table.
     */
    public function getTable(): string
    {
        return config('user-monitoring.visit_monitoring.table', parent::getTable());
    }
}

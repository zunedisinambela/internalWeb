<?php

namespace App\Console\Commands;

use App\Models\AuthenticationMonitoring;
use App\Models\MonitoringSetting;
use App\Models\VisitMonitoring;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes monitoring rows older than the retention set at /admin/monitoring.
 *
 * Replaces the package's laravel-user-monitoring:remove-visit-monitoring-records,
 * which reads its cutoff from config and therefore cannot be driven by a screen.
 * That command stays registered by the package but is not scheduled; it refuses
 * to run while config delete_days is 0, which is where it is left.
 */
class PruneMonitoring extends Command
{
    protected $signature = 'monitoring:prune';

    protected $description = 'Hapus data pemantauan yang lebih tua dari batas penyimpanan';

    public function handle(): int
    {
        $settings = MonitoringSetting::current();

        $visits = $settings->prunesVisits()
            ? $this->prune(VisitMonitoring::query(), $settings->visit_retention_days)
            : null;

        $authentications = $settings->prunesAuthentications()
            ? $this->prune(AuthenticationMonitoring::query(), $settings->authentication_retention_days)
            : null;

        // Pruned before the summary entry is written, so this run's own record
        // is never inside the window it just cleared. The activitylog package
        // ships activitylog:clean for this, but that reads clean_after_days
        // from config, which no screen can write to.
        $activities = $settings->prunesActivities()
            ? $this->prune(Activity::query(), $settings->activity_retention_days)
            : null;

        // Stamped even when both are disabled, so the settings screen can tell
        // "the scheduler ran and had nothing to do" apart from "the scheduler
        // is not running at all". Without that, a dead cron looks identical to
        // retention being off.
        $settings->forceFill(['last_pruned_at' => now()])->save();

        if ($visits === null && $authentications === null && $activities === null) {
            $this->info('Penghapusan otomatis nonaktif untuk semua tabel. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        $this->report($visits, $authentications, $activities, $settings);

        return self::SUCCESS;
    }

    /**
     * Deletes through the query builder, which fires no model events — so the
     * per-row audit hook in VisitMonitoring::booted() does not run here. That
     * is deliberate: pruning a year of traffic would otherwise write one
     * activity entry per deleted row and drown the log it is meant to protect.
     * One summary entry is written instead, in report().
     */
    protected function prune(Builder $query, int $days): int
    {
        return $query->where('created_at', '<', now()->subDays($days)->startOfDay())->delete();
    }

    protected function report(?int $visits, ?int $authentications, ?int $activities, MonitoringSetting $settings): void
    {
        $this->info(sprintf(
            'Menghapus %s kunjungan, %s riwayat masuk, dan %s entri aktivitas.',
            $visits ?? 'nol',
            $authentications ?? 'nol',
            $activities ?? 'nol',
        ));

        if (($visits ?? 0) === 0 && ($authentications ?? 0) === 0 && ($activities ?? 0) === 0) {
            return;
        }

        $properties = [
            'visits_deleted' => $visits,
            'authentications_deleted' => $authentications,
            'activities_deleted' => $activities,
            'visit_retention_days' => $settings->visit_retention_days,
            'authentication_retention_days' => $settings->authentication_retention_days,
            'activity_retention_days' => $settings->activity_retention_days,
        ];

        // No causer: this runs from the scheduler, so attributing it to whoever
        // happens to be signed in would be a lie.
        activity('monitoring')
            ->event('records_pruned')
            ->withProperties($properties)
            ->log('Data pemantauan dihapus oleh aturan penyimpanan');

        // Expiring activity entries also expires the record of deletions made
        // elsewhere, so it goes to the file log too. That is the one surface
        // the panel cannot edit, and it is the only trace left once this run's
        // own activity entry falls inside a later window.
        if (($activities ?? 0) > 0) {
            Log::warning('Activity log entries pruned by retention policy', $properties);
        }
    }
}

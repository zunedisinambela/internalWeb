<?php

namespace Tests\Feature;

use App\Console\Commands\PruneMonitoring;
use App\Filament\Pages\MonitoringSettings;
use App\Models\AuthenticationMonitoring;
use App\Models\MonitoringSetting;
use App\Models\VisitMonitoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MonitoringRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_settings_page(): void
    {
        $this->get('/admin/monitoring')->assertRedirect('/admin/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/admin/monitoring')
            ->assertForbidden();
    }

    public function test_super_admins_can_open_the_settings_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/monitoring')
            ->assertOk();
    }

    public function test_retention_can_be_saved_from_the_page(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MonitoringSettings::class)
            ->fillForm([
                'visit_retention_days' => 30,
                'authentication_retention_days' => 365,
                'activity_retention_days' => 730,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = MonitoringSetting::current();

        $this->assertSame(30, $settings->visit_retention_days);
        $this->assertSame(365, $settings->authentication_retention_days);
        $this->assertSame(730, $settings->activity_retention_days);
    }

    /**
     * The activity log records deletions made on the other two screens, so
     * expiring it expires that evidence too. Keeping it forever is the default
     * anyone should have to opt out of deliberately.
     */
    public function test_activity_retention_is_off_by_default(): void
    {
        $this->assertNull(MonitoringSetting::current()->activity_retention_days);
        $this->assertFalse(MonitoringSetting::current()->prunesActivities());
    }

    public function test_activity_entries_older_than_the_retention_are_deleted(): void
    {
        MonitoringSetting::current()->update(['activity_retention_days' => 30]);

        $old = activity()->log('old entry');
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $recent = activity()->log('recent entry');

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    /**
     * An emptied field has to mean "keep forever", not "delete everything".
     * The form sends '' for a cleared numeric input, which would cast to 0 —
     * and 0 days would match every row.
     */
    public function test_clearing_a_field_means_keep_forever(): void
    {
        $this->actingAs($this->superAdmin());

        MonitoringSetting::current()->update(['visit_retention_days' => 30]);

        Livewire::test(MonitoringSettings::class)
            ->fillForm([
                'visit_retention_days' => '',
                'authentication_retention_days' => '',
                'activity_retention_days' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = MonitoringSetting::current();

        $this->assertNull($settings->visit_retention_days);
        $this->assertFalse($settings->prunesVisits());
    }

    public function test_nothing_is_pruned_while_retention_is_disabled(): void
    {
        $this->makeVisit(daysAgo: 400);

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $this->assertSame(1, VisitMonitoring::query()->count());
    }

    public function test_records_older_than_the_retention_are_deleted(): void
    {
        MonitoringSetting::current()->update(['visit_retention_days' => 30]);

        $old = $this->makeVisit(daysAgo: 31);
        $recent = $this->makeVisit(daysAgo: 29);

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_sign_ins_keep_their_own_retention(): void
    {
        MonitoringSetting::current()->update([
            'visit_retention_days' => 30,
            'authentication_retention_days' => null,
        ]);

        $this->makeVisit(daysAgo: 400);
        $signIn = $this->makeSignIn(daysAgo: 400);

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $this->assertSame(0, VisitMonitoring::query()->count());
        $this->assertModelExists($signIn);
    }

    /**
     * Pruning deletes through the query builder, which fires no model events,
     * so the per-row audit hook on VisitMonitoring does not run. One summary
     * entry has to take its place or a retention policy becomes a way to erase
     * monitoring data with nothing recorded anywhere.
     */
    public function test_a_prune_writes_one_summary_activity_entry(): void
    {
        MonitoringSetting::current()->update(['visit_retention_days' => 30]);

        $this->makeVisit(daysAgo: 40);
        $this->makeVisit(daysAgo: 50);

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $entries = Activity::query()->where('event', 'records_pruned')->get();

        $this->assertCount(1, $entries, 'A prune must summarise, not log one entry per deleted row.');
        $this->assertSame(2, $entries->first()->properties['visits_deleted']);
        $this->assertSame(0, Activity::query()->where('event', 'visit_deleted')->count());
    }

    public function test_a_prune_that_deletes_nothing_is_not_logged(): void
    {
        MonitoringSetting::current()->update(['visit_retention_days' => 30]);

        $this->makeVisit(daysAgo: 1);

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $this->assertSame(0, Activity::query()->where('event', 'records_pruned')->count());
    }

    /**
     * The settings page reports whether retention is actually running, so the
     * timestamp has to be stamped on every run — including one that had
     * nothing to do. Otherwise a working scheduler with an empty table looks
     * identical to no scheduler at all.
     */
    public function test_every_run_stamps_the_last_pruned_time(): void
    {
        $this->assertNull(MonitoringSetting::current()->last_pruned_at);

        $this->artisan(PruneMonitoring::class)->assertSuccessful();

        $this->assertNotNull(MonitoringSetting::current()->fresh()->last_pruned_at);
    }

    public function test_the_run_now_action_prunes_immediately(): void
    {
        $this->actingAs($this->superAdmin());

        MonitoringSetting::current()->update(['visit_retention_days' => 30]);
        $old = $this->makeVisit(daysAgo: 90);

        Livewire::test(MonitoringSettings::class)
            ->callAction('prune')
            ->assertHasNoActionErrors();

        $this->assertModelMissing($old);
    }

    private function makeVisit(int $daysAgo): VisitMonitoring
    {
        return VisitMonitoring::query()->create([
            'browser_name' => 'Chrome',
            'platform' => 'Linux',
            'device' => 'Linux',
            'ip' => '127.0.0.1',
            'page' => 'http://localhost/admin',
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    private function makeSignIn(int $daysAgo): AuthenticationMonitoring
    {
        return AuthenticationMonitoring::query()->create([
            'action_type' => 'login',
            'browser_name' => 'Chrome',
            'platform' => 'Linux',
            'device' => 'Linux',
            'ip' => '127.0.0.1',
            'page' => 'http://localhost/admin/login',
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }
}

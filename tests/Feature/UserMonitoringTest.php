<?php

namespace Tests\Feature;

use App\Filament\Resources\Authentications\AuthenticationResource;
use App\Filament\Resources\Authentications\Pages\ListAuthentications;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\AuthenticationMonitoring;
use App\Models\VisitMonitoring;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UserMonitoringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The whole reason routes/user-monitoring.php exists. The package registers
     * these six with the `web` group and nothing else — no auth, no gate — so
     * anonymous visitors could read every IP and login time and delete the
     * rows. If this fails, that file was emptied of its comment block, renamed,
     * or the config key pointing at it changed, and the data is public again.
     */
    public function test_the_packages_own_routes_are_not_registered(): void
    {
        $paths = [
            'user-monitoring/visits-monitoring',
            'user-monitoring/actions-monitoring',
            'user-monitoring/authentications-monitoring',
        ];

        foreach ($paths as $path) {
            $this->get('/'.$path)->assertNotFound();
            $this->delete('/'.$path.'/1')->assertNotFound();
        }
    }

    public function test_guests_are_redirected_from_the_monitoring_pages(): void
    {
        $this->get('/admin/visits')->assertRedirect('/admin/login');
        $this->get('/admin/authentications')->assertRedirect('/admin/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $user = $this->userWithRole(null);

        $this->actingAs($user)->get('/admin/visits')->assertForbidden();
        $this->actingAs($user)->get('/admin/authentications')->assertForbidden();
    }

    public function test_super_admins_can_open_the_monitoring_pages(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/admin/visits')->assertOk();
        $this->actingAs($admin)->get('/admin/authentications')->assertOk();
    }

    /**
     * Nothing writes monitoring rows by hand, so create and edit stay refused
     * on both resources even though delete is allowed.
     */
    public function test_monitoring_records_cannot_be_created_or_edited(): void
    {
        foreach ([VisitResource::class, AuthenticationResource::class] as $resource) {
            $this->assertFalse($resource::canCreate(), $resource);

            // No create or edit routes are registered at all, so there is
            // nothing to reach even with a hand-built URL.
            $this->assertSame(['index'], array_keys($resource::getPages()), $resource);
        }
    }

    /**
     * Who may clear monitoring data comes from the Shield policies, not a
     * hardcoded rule — that is the point of leaving canDelete() unoverridden.
     */
    public function test_deleting_monitoring_records_follows_the_shield_policy(): void
    {
        $this->actingAs($this->superAdmin());
        $this->assertTrue(VisitResource::canDeleteAny());
        $this->assertTrue(AuthenticationResource::canDeleteAny());

        $this->actingAs($this->userWithRole(null));
        $this->assertFalse(VisitResource::canDeleteAny());
        $this->assertFalse(AuthenticationResource::canDeleteAny());
    }

    /**
     * Sign-ins are the record of who had access and when, so removing one has
     * to leave a mark the deleter cannot also remove.
     */
    public function test_deleting_a_sign_in_is_written_to_the_activity_log(): void
    {
        $admin = $this->superAdmin();

        Auth::guard('web')->login($admin);
        $signIn = AuthenticationMonitoring::query()->latest('id')->firstOrFail();

        $this->actingAs($admin);
        $signIn->delete();

        $entry = Activity::query()->where('event', 'sign_in_deleted')->latest('id')->first();

        $this->assertNotNull($entry, 'Deleting a sign-in must leave an activity log entry.');
        $this->assertSame($admin->getKey(), $entry->causer_id);
        $this->assertSame('login', $entry->properties['action_type']);
        $this->assertSame($signIn->ip, $entry->properties['ip']);
    }

    public function test_a_sign_in_can_be_deleted_from_the_table(): void
    {
        $admin = $this->superAdmin();

        Auth::guard('web')->login($admin);
        $signIn = AuthenticationMonitoring::query()->latest('id')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(ListAuthentications::class)
            ->callAction(TestAction::make('delete')->table($signIn))
            ->assertHasNoActionErrors();

        $this->assertModelMissing($signIn);
    }

    /**
     * Same blind spot as visits: with fetchSelectedRecords off, Filament would
     * delete through a single query and fire no model events, leaving bulk
     * removals unaudited while single ones stayed covered.
     */
    public function test_bulk_deleting_sign_ins_stays_audited(): void
    {
        $admin = $this->superAdmin();

        Auth::guard('web')->login($admin);
        Auth::guard('web')->logout();

        $this->actingAs($admin);
        $signIns = AuthenticationMonitoring::query()->pluck('id');

        $this->assertCount(2, $signIns);

        Livewire::test(ListAuthentications::class)
            ->selectTableRecords($signIns->all())
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoActionErrors();

        $this->assertSame(0, AuthenticationMonitoring::query()->count());
        $this->assertSame(2, Activity::query()->where('event', 'sign_in_deleted')->count());
    }

    /**
     * Visits are deletable, so the deletion itself has to leave a mark
     * somewhere the deleter cannot reach — otherwise someone with the
     * permission could erase their own visits cleanly.
     */
    public function test_deleting_a_visit_is_written_to_the_activity_log(): void
    {
        $admin = $this->superAdmin();

        $this->get('/')->assertOk();
        $visit = VisitMonitoring::query()->latest('id')->firstOrFail();

        $this->actingAs($admin);
        $visit->delete();

        $entry = Activity::query()->where('event', 'visit_deleted')->latest('id')->first();

        $this->assertNotNull($entry, 'Deleting a visit must leave an activity log entry.');
        $this->assertSame($admin->getKey(), $entry->causer_id);
        $this->assertSame($visit->page, $entry->properties['page']);
        $this->assertSame($visit->ip, $entry->properties['ip']);
    }

    public function test_a_visit_can_be_deleted_from_the_table(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/')->assertOk();
        $visit = VisitMonitoring::query()->latest('id')->firstOrFail();

        Livewire::test(ListVisits::class)
            ->callAction(TestAction::make('delete')->table($visit))
            ->assertHasNoActionErrors();

        $this->assertModelMissing($visit);
    }

    /**
     * The bulk path is the one that can quietly stop being audited: Filament
     * deletes through a single query when fetchSelectedRecords is off, and a
     * query builder delete fires no model events. This asserts the log entries
     * are actually written, not just that the rows disappeared.
     */
    public function test_bulk_deleting_visits_stays_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();
        $visits = VisitMonitoring::query()->pluck('id');

        $this->assertCount(2, $visits);

        Livewire::test(ListVisits::class)
            ->selectTableRecords($visits->all())
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoActionErrors();

        $this->assertSame(0, VisitMonitoring::query()->count());
        $this->assertSame(2, Activity::query()->where('event', 'visit_deleted')->count());
    }

    public function test_a_page_view_is_recorded(): void
    {
        $this->get('/')->assertOk();

        $visit = VisitMonitoring::query()->latest('id')->first();

        $this->assertNotNull($visit);
        $this->assertNull($visit->user_id, 'guest_mode is on, so signed-out hits are recorded with a null user.');
        $this->assertSame('127.0.0.1', $visit->ip);
    }

    public function test_a_signed_in_page_view_is_attributed_to_the_user(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $visit = VisitMonitoring::query()->latest('id')->first();

        $this->assertNotNull($visit, 'The panel builds its own middleware stack, so RecordVisit has to be listed there too.');
        $this->assertSame($admin->getKey(), $visit->user_id);
    }

    /**
     * Filament is Livewire-driven: every table sort and search keystroke is its
     * own request. Recording them would turn this table into a keystroke log.
     */
    public function test_livewire_requests_are_not_recorded(): void
    {
        $this->withHeader('X-Livewire', 'true')->get('/')->assertOk();

        $this->assertSame(0, VisitMonitoring::query()->count());
    }

    public function test_prefetched_pages_are_not_recorded(): void
    {
        $this->withHeader('Sec-Purpose', 'prefetch')->get('/')->assertOk();

        $this->assertSame(0, VisitMonitoring::query()->count());
    }

    public function test_signing_in_and_out_is_recorded(): void
    {
        $user = $this->userWithRole(null);

        Auth::guard('web')->login($user);
        Auth::guard('web')->logout();

        $events = AuthenticationMonitoring::query()
            ->orderBy('id')
            ->pluck('action_type')
            ->all();

        $this->assertSame(['login', 'logout'], $events);
        $this->assertSame($user->getKey(), AuthenticationMonitoring::query()->first()->user_id);
    }

    /**
     * The package ships delete_user_record_when_user_delete => true, which makes
     * user_id cascade and erases an account's sign-in history the moment it is
     * deleted at /admin/users — exactly the history you would want after
     * removing a suspicious account. config sets it to false for nullOnDelete().
     */
    public function test_sign_in_history_survives_deleting_the_user(): void
    {
        $user = $this->userWithRole(null);

        Auth::guard('web')->login($user);
        Auth::guard('web')->logout();

        $user->delete();

        $this->assertSame(2, AuthenticationMonitoring::query()->count());
        $this->assertSame(0, AuthenticationMonitoring::query()->whereNotNull('user_id')->count());
    }

    /**
     * The package writes its row inline in the request pipeline, so an insert
     * failure would otherwise 500 every page in the app. RecordVisit isolates
     * it. Dropping the table is a stand-in for any write failure — a locked
     * database, a full disk, a migration not yet run on deploy.
     */
    public function test_a_failed_visit_write_does_not_break_the_request(): void
    {
        Schema::drop('visits_monitoring');

        $this->get('/')->assertOk();
    }
}

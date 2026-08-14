<?php

namespace Tests\Feature;

use App\Filament\Resources\ElectricityTariffs\Pages\CreateElectricityTariff;
use App\Models\ElectricityTariff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ElectricityTariffTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/electricity-tariffs')->assertRedirect('/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/electricity-tariffs')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        ElectricityTariff::factory()->rate(1_650)->create(['note' => 'Menyesuaikan kenaikan PLN']);

        $this->actingAs($this->superAdmin())
            ->get('/electricity-tariffs')
            ->assertOk()
            ->assertSee('Menyesuaikan kenaikan PLN');
    }

    /**
     * Gated by its Shield policy like every other resource, not by a hardcoded
     * override. If this passes for the wrong reason it is usually because
     * canCreate() was overridden to true somewhere.
     */
    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-tarif', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:ElectricityTariff'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-tarif@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/electricity-tariffs')->assertOk();
        $this->actingAs($user)->get('/electricity-tariffs/create')->assertForbidden();
    }

    /**
     * The rate in force is the latest one dated on or before today — not the
     * newest row, and not the highest id.
     */
    public function test_the_rate_in_force_is_the_latest_one_that_has_started(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        ElectricityTariff::factory()->rate(1_400, '2026-01-01')->create();
        ElectricityTariff::factory()->rate(1_500, '2026-06-01')->create();

        $this->assertSame(1_500, ElectricityTariff::currentRate());
    }

    /**
     * A row dated ahead is how a raise is scheduled. It must not leak into
     * today's readings — the tenant is billed at the rate that is actually in
     * force on the day the meter is read.
     */
    public function test_a_scheduled_tariff_does_not_apply_before_its_date(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        ElectricityTariff::factory()->rate(1_500, '2026-08-01')->create();
        ElectricityTariff::factory()->rate(2_000, '2026-09-01')->create();

        $this->assertSame(1_500, ElectricityTariff::currentRate());

        Carbon::setTestNow('2026-09-01 00:30:00');

        $this->assertSame(2_000, ElectricityTariff::currentRate());
    }

    /**
     * Null is a real answer, and callers have to handle it. Inventing a default
     * rate here would put a made-up number onto a bill.
     */
    public function test_there_is_no_rate_in_force_on_an_empty_table(): void
    {
        $this->assertNull(ElectricityTariff::current());
        $this->assertNull(ElectricityTariff::currentRate());
    }

    /**
     * Two tariffs on one day would make "which rate is in force" unanswerable,
     * and the tiebreak would silently become insertion order. The database is
     * what refuses it; the form's ->unique() only turns that into a message.
     */
    public function test_two_tariffs_cannot_share_a_start_date(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-08-01')->create();

        $this->expectException(QueryException::class);

        ElectricityTariff::factory()->rate(1_700, '2026-08-01')->create();
    }

    /**
     * Who raised the rate is the question this table exists to answer, so it is
     * stamped server-side rather than taken from the form.
     */
    public function test_the_author_is_stamped_on_create(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreateElectricityTariff::class)
            ->fillForm([
                'rate' => '1.750',
                'effective_from' => '2026-08-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tariff = ElectricityTariff::query()->sole();

        $this->assertSame($admin->getKey(), $tariff->user_id);
    }

    /**
     * The grouped figure the field shows and the bare integer the column stores
     * are inverses, and both go through WholeRupiah so they cannot drift.
     */
    public function test_a_grouped_rate_is_stored_as_an_integer(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateElectricityTariff::class)
            ->fillForm([
                'rate' => '1.750',
                'effective_from' => '2026-08-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1_750, ElectricityTariff::query()->sole()->rate);
    }

    /**
     * A rate is what every bill is computed from, so a change to it belongs in
     * the audit trail — and unlike the two-factor secret, its value is safe to
     * record.
     */
    public function test_a_rate_change_is_audited(): void
    {
        $tariff = ElectricityTariff::factory()->rate(1_500, '2026-08-01')->create();

        $tariff->update(['rate' => 1_800]);

        $entry = Activity::query()
            ->where('log_name', 'tariff')
            ->where('event', 'updated')
            ->sole();

        // v5 keeps the diff in its own `attribute_changes` column rather than
        // burying it inside `properties` the way v4 did.
        $this->assertSame(1_500, $entry->attribute_changes['old']['rate']);
        $this->assertSame(1_800, $entry->attribute_changes['attributes']['rate']);
    }
}

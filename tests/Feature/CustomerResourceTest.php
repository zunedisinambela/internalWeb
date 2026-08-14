<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/customers')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        Customer::factory()->named('Ayu Lestari')->create(['phone' => '081234567890']);

        $this->actingAs($this->superAdmin())
            ->get('/customers')
            ->assertOk()
            ->assertSee('Ayu Lestari')
            ->assertSee('081234567890');
    }

    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-pelanggan', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Customer'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-pelanggan@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/customers')->assertOk();
        $this->actingAs($user)->get('/customers/create')->assertForbidden();
    }

    /**
     * The payoff question this feature exists for, asked per person: what has
     * this customer bought, and what was made on it.
     *
     * Walked over the relation rather than read from a stored figure, so it
     * cannot disagree with the sales it was summed from.
     */
    public function test_the_totals_are_summed_across_every_sale(): void
    {
        $customer = Customer::factory()->named('Ayu')->create();

        $first = Sale::factory()->forCustomer($customer)->create();
        SaleItem::factory()->forSale($first)->line(quantity: 1, catalog: 200_000, marketing: 150_000)->create();

        $second = Sale::factory()->forCustomer($customer)->create();
        SaleItem::factory()->forSale($second)->line(quantity: 2, catalog: 50_000, marketing: 40_000)->create();

        $customer->refresh();

        $this->assertSame(300_000, $customer->total_spent);
        $this->assertSame(70_000, $customer->total_profit);
    }

    public function test_a_customer_with_no_sales_totals_zero(): void
    {
        $customer = Customer::factory()->create();

        $this->assertSame(0, $customer->total_spent);
        $this->assertSame(0, $customer->total_profit);
    }

    /**
     * The resource-level half of the delete rule, which turns the foreign key's
     * refusal into a missing button rather than a stack trace. On the resource
     * and not on the action, because Filament consults the resource for the row
     * action *and* for every record inside a bulk delete.
     */
    public function test_a_customer_with_sales_cannot_be_deleted(): void
    {
        // canDelete() defers to the Shield policy first, so this has to run as
        // somebody the policy allows — otherwise both answers are false and the
        // test passes for the wrong reason.
        $this->actingAs($this->superAdmin());

        $buyer = Customer::factory()->named('Pernah beli')->create();
        $newcomer = Customer::factory()->named('Belum beli')->create();

        Sale::factory()->forCustomer($buyer)->create();

        $this->assertFalse(CustomerResource::canDelete($buyer));
        $this->assertTrue(CustomerResource::canDelete($newcomer));
    }

    /**
     * The other half, covering tinker, a console command and anything else that
     * never asks the resource. sales.customer_id is restrictOnDelete.
     */
    public function test_the_database_refuses_to_delete_a_customer_with_sales(): void
    {
        $customer = Customer::factory()->create();
        Sale::factory()->forCustomer($customer)->create();

        $this->expectException(QueryException::class);

        $customer->delete();
    }

    /**
     * Deactivation is the exit a customer who stops buying actually takes, and
     * it leaves their history intact.
     */
    public function test_deactivating_a_customer_keeps_their_sales(): void
    {
        $customer = Customer::factory()->create();
        Sale::factory()->forCustomer($customer)->create();

        $customer->update(['is_active' => false]);

        $this->assertSame(1, $customer->sales()->count());
    }

    /**
     * A phone number changed on the wrong row is how a message about an order
     * reaches the wrong person, which is why it is on the allowlist rather than
     * left off as a mere contact detail.
     */
    public function test_a_phone_number_change_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create(['phone' => '081200000000']);
        $customer->update(['phone' => '081299999999']);

        $entry = Activity::query()->where('log_name', 'customer')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('081200000000', $entry->attribute_changes['old']['phone']);
        $this->assertSame('081299999999', $entry->attribute_changes['attributes']['phone']);
    }

    /**
     * Pinned to per-record deletes so each removal is consulted against
     * canDelete() and writes its own entry, rather than the whole selection
     * going down in one query the foreign key would refuse halfway through.
     */
    public function test_a_bulk_delete_audits_each_customer(): void
    {
        $this->actingAs($this->superAdmin());

        $customers = Customer::factory()->count(2)->create();

        Activity::query()->delete();

        Livewire::test(ListCustomers::class)
            ->selectTableRecords($customers->pluck('id')->all())
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(2, Activity::query()->where('log_name', 'customer')->where('event', 'deleted')->count());
    }
}

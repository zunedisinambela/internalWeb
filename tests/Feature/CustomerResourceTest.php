<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Sale;
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

        Sale::factory()->forCustomer($customer)
            ->priced(marketing: 150_000, catalog: 200_000)->create();

        // The second order was posted, so its Rp 10.000 comes out of the margin
        // rather than being added to what the customer paid.
        Sale::factory()->forCustomer($customer)
            ->priced(marketing: 80_000, catalog: 100_000, shipping: 10_000)->create();

        $customer->refresh();

        $this->assertSame(300_000, $customer->total_spent);
        $this->assertSame(60_000, $customer->total_profit);
    }

    public function test_a_customer_with_no_sales_totals_zero(): void
    {
        $customer = Customer::factory()->create();

        $this->assertSame(0, $customer->total_spent);
        $this->assertSame(0, $customer->total_profit);
    }

    /**
     * The rule this screen answers, and the one place it differs from the sale.
     *
     * Sale::$free_quantity divides one order's own count, so two orders of ten
     * earn nothing at all. The customer's bonus is counted across every order
     * instead, because a customer buying ten a month is the ordinary case and
     * the other reading throws away every remainder at the row boundary — the
     * same twenty items would be worth a free one or nothing depending only on
     * how many trips they were bought in.
     *
     * Both readings are asserted here together: without the per-sale half, this
     * would still pass if Customer::$free_quantity were ever quietly rewritten
     * as a sum of the sales' own bonuses.
     */
    public function test_the_free_item_is_counted_across_orders_not_per_order(): void
    {
        $customer = Customer::factory()->named('Zunedi')->create();

        $first = Sale::factory()->forCustomer($customer)->quantity(10)->create();
        $second = Sale::factory()->forCustomer($customer)->quantity(10)->create();

        $customer->refresh();

        $this->assertSame(0, $first->free_quantity);
        $this->assertSame(0, $second->free_quantity);

        $this->assertSame(20, $customer->total_quantity);
        $this->assertSame(1, $customer->free_quantity);
        $this->assertSame(Sale::FREE_ITEM_THRESHOLD, $customer->quantity_to_next_free_item);
    }

    /**
     * A remainder is carried rather than dropped, so the count keeps running
     * across the threshold instead of restarting at each order.
     */
    public function test_the_bonus_counts_every_whole_threshold_and_names_the_distance_to_the_next(): void
    {
        $customer = Customer::factory()->create();

        Sale::factory()->forCustomer($customer)->quantity(25)->create();
        Sale::factory()->forCustomer($customer)->quantity(18)->create();

        $customer->refresh();

        $this->assertSame(43, $customer->total_quantity);
        $this->assertSame(2, $customer->free_quantity);
        $this->assertSame(17, $customer->quantity_to_next_free_item);
    }

    /**
     * A customer who has bought nothing owes the whole threshold, not nothing —
     * otherwise an empty record reads as one item away from a free item.
     */
    public function test_a_customer_with_no_sales_has_earned_nothing(): void
    {
        $customer = Customer::factory()->create();

        $this->assertSame(0, $customer->total_quantity);
        $this->assertSame(0, $customer->free_quantity);
        $this->assertSame(Sale::FREE_ITEM_THRESHOLD, $customer->quantity_to_next_free_item);
    }

    /**
     * The bonus carries no money here either, for the reason it carries none on
     * the sale: whether the free item is still paid for to Oriflame has not been
     * decided, so folding it into either total would put a figure on screen that
     * nobody entered.
     */
    public function test_the_customer_bonus_leaves_the_money_alone(): void
    {
        $customer = Customer::factory()->create();

        Sale::factory()->forCustomer($customer)->quantity(20)
            ->priced(marketing: 150_000, catalog: 200_000)->create();

        $customer->refresh();

        $this->assertSame(1, $customer->free_quantity);
        $this->assertSame(200_000, $customer->total_spent);
        $this->assertSame(50_000, $customer->total_profit);
    }

    /**
     * The list reads the same figure from a ->sum() subquery rather than from
     * the accessor, so the two arithmetics are asserted against one screen. The
     * bonus rides on the count column as a description: it is only ever read
     * beside the count it comes from.
     */
    public function test_the_customer_list_shows_the_item_count_and_the_bonus_it_earned(): void
    {
        $this->actingAs($this->superAdmin());

        $qualifies = Customer::factory()->named('Zunedi')->create();
        Sale::factory()->forCustomer($qualifies)->quantity(10)->count(2)->create();

        $shortOfIt = Customer::factory()->named('Ayu')->create();
        Sale::factory()->forCustomer($shortOfIt)->quantity(4)->create();

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$qualifies, $shortOfIt])
            ->assertTableColumnStateSet('sales_sum_quantity', 20, $qualifies)
            ->assertTableColumnStateSet('sales_sum_quantity', 4, $shortOfIt)
            // The description names what is still owed rather than what was
            // earned; FreeItemRedemptionTest covers the collected half.
            ->assertSee('+1 gratis belum diambil');
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
     * The address is where a parcel goes, so a stale one loses it rather than
     * merely misdirecting a message. It is on the LogsActivity allowlist beside
     * `phone` for that reason, and the cost is named in Customer's docblock:
     * activity_log then holds home addresses.
     */
    public function test_an_address_change_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->at('Jl. Mawar No. 1, RT 02/RW 03, Sukajadi, Bandung 40161')->create();
        $customer->update(['address' => 'Jl. Melati No. 9, RT 01/RW 04, Cicendo, Bandung 40172']);

        $entry = Activity::query()->where('log_name', 'customer')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Mawar', $entry->attribute_changes['old']['address']);
        $this->assertStringContainsString('Melati', $entry->attribute_changes['attributes']['address']);
    }

    /**
     * A full address needs a row to itself, so its column is toggled off by
     * default — and it stays searchable anyway.
     *
     * That rests on a vendor internal rather than a documented promise:
     * CanSearchRecords::applyGlobalSearchToTableQuery() skips a column for
     * isHidden(), which is the ->hidden()/->visible() API, and never consults
     * isToggledHidden(). If that ever changes, searching for a street stops
     * finding anybody and nothing else in the suite notices.
     */
    public function test_an_address_is_searchable_while_its_column_is_hidden(): void
    {
        $this->actingAs($this->superAdmin());

        $atHome = Customer::factory()->named('Zunedi')->at('Jl. Kenanga No. 7, Sukajadi, Bandung')->create();
        $elsewhere = Customer::factory()->named('Ayu')->at('Jl. Anggrek No. 2, Cicendo, Bandung')->create();

        // Not assertTableColumnHidden(): that asserts isHidden(), which is the
        // very distinction this test is about. The column being toggled off is
        // asserted by its content being absent from the rendered list instead.
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$atHome, $elsewhere])
            ->assertDontSee('Kenanga');

        // Searched separately, because the search term is bound to the input and
        // so appears in the markup of the searched page either way.
        Livewire::test(ListCustomers::class)
            ->searchTable('Kenanga')
            ->assertCanSeeTableRecords([$atHome])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    /**
     * The column is `text`, not `string`, because a full Indonesian address runs
     * past 255 characters and a VARCHAR would truncate it without raising
     * anything on a database that is not in strict mode. SQLite enforces no
     * length at all, so this asserts the round trip rather than the column type
     * — what it really guards is a later migration quietly narrowing it.
     */
    public function test_a_long_address_survives_the_round_trip(): void
    {
        $address = 'Jl. Raya Pajajaran Blok C2 No. 148, RT 007/RW 012, Kelurahan Sukajadi, '
            .'Kecamatan Cicendo, Kota Bandung, Jawa Barat 40161, patokan seberang minimarket '
            .'dua ratus meter sesudah pertigaan, sebelah bengkel motor yang catnya biru, '
            .'rumah pagar hijau paling ujung, titipkan ke tetangga sebelah kanan bila tidak '
            .'ada orang di rumah pada jam kerja';

        $this->assertGreaterThan(255, strlen($address));

        $customer = Customer::factory()->at($address)->create();

        $this->assertSame($address, $customer->fresh()->address);
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

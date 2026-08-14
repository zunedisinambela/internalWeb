<?php

namespace Tests\Feature;

use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/sales')->assertRedirect('/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/sales')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        $customer = Customer::factory()->named('Ayu')->create();
        Sale::factory()->forCustomer($customer)->create();

        $this->actingAs($this->superAdmin())
            ->get('/sales')
            ->assertOk()
            ->assertSee('Ayu');
    }

    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-penjualan', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Sale'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-penjualan@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/sales')->assertOk();
        $this->actingAs($user)->get('/sales/create')->assertForbidden();
    }

    /**
     * The view screen renders the lines through a RepeatableEntry in table
     * layout, which is the one component here with no equivalent elsewhere in
     * the panel. Asserting the margin on the page rather than only the product
     * name is what makes this cover the entry states rather than the heading.
     */
    public function test_the_view_screen_renders_the_lines_and_the_margin(): void
    {
        $customer = Customer::factory()->named('Ayu')->create();
        $product = Product::factory()->priced(200_000, 150_000)->create(['name' => 'Milk Honey Gold']);
        $sale = Sale::factory()->forCustomer($customer)->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product)->create();

        $this->actingAs($this->superAdmin())
            ->get('/sales/'.$sale->getKey())
            ->assertOk()
            ->assertSee('Milk Honey Gold')
            ->assertSee('Rp 200.000')
            ->assertSee('Rp 50.000');
    }

    /**
     * The worked example the feature was built from: Ayu takes three products
     * priced at Rp 200.000 in the catalogue, which cost this consultant
     * Rp 150.000, leaving Rp 50.000.
     *
     * All three figures are accessors over the lines. None of them is stored, so
     * none of them can disagree with the lines they were summed from.
     */
    public function test_the_three_totals_are_derived_from_the_lines(): void
    {
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->line(quantity: 1, catalog: 80_000, marketing: 60_000)->create();
        SaleItem::factory()->forSale($sale)->line(quantity: 1, catalog: 70_000, marketing: 55_000)->create();
        SaleItem::factory()->forSale($sale)->line(quantity: 1, catalog: 50_000, marketing: 35_000)->create();

        $sale->refresh();

        $this->assertSame(200_000, $sale->catalog_total);
        $this->assertSame(150_000, $sale->marketing_total);
        $this->assertSame(50_000, $sale->profit);
    }

    public function test_a_quantity_multiplies_both_prices(): void
    {
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->line(quantity: 3, catalog: 100_000, marketing: 75_000)->create();

        $sale->refresh();

        $this->assertSame(300_000, $sale->catalog_total);
        $this->assertSame(225_000, $sale->marketing_total);
        $this->assertSame(75_000, $sale->profit);
    }

    /**
     * **The assertion the whole feature rests on.**
     *
     * Oriflame reprices its catalogue every month. If a sale line read its
     * figures through the product relation, entering the new catalogue would
     * rewrite every sale already recorded — August's margin quietly becoming
     * September's, with no row changed and nothing in activity_log to notice.
     *
     * Without this test the feature passes every other one here while doing
     * exactly the wrong thing.
     */
    public function test_a_later_price_change_does_not_reprice_a_recorded_sale(): void
    {
        $product = Product::factory()->priced(200_000, 150_000)->create();
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product)->create();

        // A new catalogue arrives and everything goes up.
        $product->update(['catalog_price' => 260_000, 'marketing_price' => 195_000]);

        $sale->refresh();

        $this->assertSame(200_000, $sale->catalog_total, 'The sale must keep the prices it was recorded at.');
        $this->assertSame(150_000, $sale->marketing_total);
        $this->assertSame(50_000, $sale->profit);
    }

    /**
     * The same failure arriving through the form instead of through a join: a
     * line that re-copied the product's current prices whenever the sale was
     * saved would reprice an issued order while looking like an ordinary edit.
     */
    public function test_editing_a_sale_does_not_recopy_the_current_prices(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(200_000, 150_000)->create();
        $customer = Customer::factory()->create();
        $sale = Sale::factory()->forCustomer($customer)->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product)->create();

        $product->update(['catalog_price' => 260_000, 'marketing_price' => 195_000]);

        // An unrelated field changes, and nothing else may move with it.
        Livewire::test(EditSale::class, ['record' => $sale->getKey()])
            ->fillForm(['note' => 'Diantar ke kantor'])
            ->call('save')
            ->assertHasNoFormErrors();

        $sale->refresh();

        $this->assertSame('Diantar ke kantor', $sale->note);
        $this->assertSame(200_000, $sale->catalog_total);
        $this->assertSame(150_000, $sale->marketing_total);
    }

    /**
     * The escape hatch from the snapshot, and the assertion that it stays an
     * escape hatch: the action fills the open form and writes nothing. Closing
     * the page without saving has to leave the sale exactly as it was.
     */
    public function test_refreshing_prices_fills_the_form_without_saving(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(20_000, 17_000)->create(['name' => 'Produk A']);
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product, quantity: 2)->create();

        $product->update(['marketing_price' => 15_000]);

        $component = Livewire::test(EditSale::class, ['record' => $sale->getKey()])
            ->callAction('refreshPrices');

        $uuid = array_key_first($component->get('data.items'));

        // The form now shows the current figure...
        $component->assertSet("data.items.{$uuid}.marketing_price", '15.000');

        // ...and the row still holds the one it was recorded at.
        $this->assertSame(17_000, $sale->items()->sole()->marketing_price);
        $this->assertSame(6_000, $sale->refresh()->profit);
    }

    /**
     * The other half: pressing Simpan afterwards is what commits it, through the
     * ordinary save path — so the correction lands in `sale_item` the same way a
     * figure typed by hand would.
     */
    public function test_saving_after_a_refresh_commits_the_new_prices_and_audits_them(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(20_000, 17_000)->create(['name' => 'Produk A']);
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product, quantity: 2)->create();

        $product->update(['marketing_price' => 15_000]);

        Activity::query()->delete();

        Livewire::test(EditSale::class, ['record' => $sale->getKey()])
            ->callAction('refreshPrices')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(15_000, $sale->items()->sole()->marketing_price);
        $this->assertSame(10_000, $sale->refresh()->profit);

        $entry = Activity::query()->where('log_name', 'sale_item')->latest('id')->first();

        $this->assertNotNull($entry, 'A correction made this way has to be audited like any other.');
        $this->assertSame(17_000, $entry->attribute_changes['old']['marketing_price']);
        $this->assertSame(15_000, $entry->attribute_changes['attributes']['marketing_price']);
    }

    /**
     * The button answers "are my prices current?" by being absent. A modal that
     * opens only to say nothing would change is a worse answer than no button.
     */
    public function test_the_refresh_button_is_hidden_when_every_price_already_matches(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(20_000, 15_000)->create();
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product)->create();

        Livewire::test(EditSale::class, ['record' => $sale->getKey()])
            ->assertActionHidden('refreshPrices');

        $product->update(['marketing_price' => 12_000]);

        Livewire::test(EditSale::class, ['record' => $sale->getKey()])
            ->assertActionVisible('refreshPrices');
    }

    /**
     * The confirmation is the part that makes this a correction rather than a
     * silent rewrite, so it has to name the line and both figures. A product
     * name is typed by a user and the modal body is rendered as HTML, so it is
     * escaped — this is the one place in the feature where that matters.
     */
    public function test_the_confirmation_lists_what_would_change_and_escapes_the_product_name(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(20_000, 17_000)->create(['name' => 'Milk & Honey <b>Gold</b>']);
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->ofProduct($product)->create();

        $product->update(['marketing_price' => 15_000]);

        $description = (string) Livewire::test(EditSale::class, ['record' => $sale->getKey()])
            ->instance()
            ->getAction('refreshPrices')
            ->getModalDescription();

        $this->assertStringContainsString('Rp 17.000', $description);
        $this->assertStringContainsString('Rp 15.000', $description);
        $this->assertStringContainsString('Milk &amp; Honey &lt;b&gt;Gold&lt;/b&gt;', $description);
        $this->assertStringNotContainsString('<b>Gold</b>', $description);
    }

    /**
     * Picking a product copies both of its prices into the line. That copy is
     * the moment the snapshot is taken, and it is what makes the assertion above
     * possible without asking the user to type prices they already recorded.
     *
     * Driven with ->set() rather than fillForm(), because fillForm() fills state
     * without firing afterStateUpdated() — and afterStateUpdated is the thing
     * under test.
     */
    public function test_picking_a_product_copies_its_prices_onto_the_line(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(200_000, 150_000)->create();
        $customer = Customer::factory()->create();

        $component = Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'items' => [['quantity' => 1]],
            ]);

        // The repeater keys its items by uuid, so the path cannot be written out
        // in advance.
        $uuid = array_key_first($component->get('data.items'));

        $component->set("data.items.{$uuid}.product_id", $product->getKey())
            ->assertSet("data.items.{$uuid}.catalog_price", '200.000')
            ->assertSet("data.items.{$uuid}.marketing_price", '150.000');
    }

    /**
     * The full round trip through the form: grouped strings in, integers in the
     * columns, and the margin falling out of the two.
     */
    public function test_a_sale_recorded_through_the_form_stores_whole_rupiah(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->named('Ayu')->create();
        $product = Product::factory()->priced(200_000, 150_000)->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'items' => [[
                    'product_id' => $product->getKey(),
                    'quantity' => 1,
                    'catalog_price' => '200.000',
                    'marketing_price' => '150.000',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = Sale::query()->sole();
        $item = $sale->items()->sole();

        $this->assertSame(200_000, $item->catalog_price);
        $this->assertSame(150_000, $item->marketing_price);
        $this->assertSame(50_000, $sale->profit);
    }

    /**
     * Stamped server-side rather than exposed as a select, so a crafted request
     * cannot attribute a sale to someone else.
     */
    public function test_the_author_is_stamped_from_the_session(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $customer = Customer::factory()->create();
        $product = Product::factory()->priced(100_000, 80_000)->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'items' => [[
                    'product_id' => $product->getKey(),
                    'quantity' => 1,
                    'catalog_price' => '100.000',
                    'marketing_price' => '80.000',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($admin->getKey(), Sale::query()->sole()->user_id);
    }

    /**
     * A row written outside the form has to read as a loss rather than as a sale
     * that happened to earn nothing — max(0, …) would render the broken line as
     * a plausible one.
     */
    public function test_a_negative_margin_is_reported_rather_than_clamped(): void
    {
        $sale = Sale::factory()->create();

        SaleItem::factory()->forSale($sale)->line(quantity: 1, catalog: 100_000, marketing: 120_000)->create();

        $this->assertSame(-20_000, $sale->refresh()->profit);
    }

    /**
     * Two lines naming the same product are almost always a double entry;
     * quantity is what expresses "three of these".
     */
    public function test_the_same_product_cannot_be_listed_twice(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();
        $product = Product::factory()->priced(100_000, 80_000)->create();

        $line = [
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'catalog_price' => '100.000',
            'marketing_price' => '80.000',
        ];

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'items' => [$line, $line],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * The line-level half of the same rule the product form enforces, and it has
     * to be repeated here because a price can be overridden per sale.
     */
    public function test_a_line_marketing_price_above_its_catalogue_price_is_refused(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();
        $product = Product::factory()->priced(200_000, 150_000)->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'items' => [[
                    'product_id' => $product->getKey(),
                    'quantity' => 1,
                    'catalog_price' => '150.000',
                    'marketing_price' => '1.500.000',
                ]],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * sale_items.sale_id is the one cascade in this project: a line belongs to
     * its sale and means nothing without it.
     */
    public function test_deleting_a_sale_takes_its_lines_with_it(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->create();
        SaleItem::factory()->forSale($sale)->count(3)->create();

        $this->assertSame(3, SaleItem::query()->count());

        $sale->delete();

        $this->assertSame(0, SaleItem::query()->count());
    }

    /**
     * The cascade fires no model events, so the sale's own entry is what records
     * the deletion — one act, one entry, rather than one per line.
     */
    public function test_deleting_a_sale_writes_one_audit_entry(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->create();
        SaleItem::factory()->forSale($sale)->count(3)->create();

        Activity::query()->delete();

        $sale->delete();

        $this->assertSame(1, Activity::query()->where('log_name', 'sale')->where('event', 'deleted')->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'sale_item')->where('event', 'deleted')->count());
    }

    /**
     * Both snapshot columns are on the SaleItem allowlist, for the same reason
     * meter_readings.rate is: they hold values copied from somewhere else, so a
     * line whose figures match no product is only explicable from the log.
     */
    public function test_a_line_price_correction_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $item = SaleItem::factory()->line(quantity: 1, catalog: 200_000, marketing: 150_000)
            ->create(['sale_id' => Sale::factory()]);

        $item->update(['marketing_price' => 140_000]);

        $entry = Activity::query()->where('log_name', 'sale_item')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(150_000, $entry->attribute_changes['old']['marketing_price']);
        $this->assertSame(140_000, $entry->attribute_changes['attributes']['marketing_price']);
    }

    /**
     * The allowlist is what keeps the log safe as columns are added, so the
     * assertion has to be that nothing *outside* it arrives — not merely that
     * the listed columns do. `user_id` is the one to watch here: it is written
     * on every create and would otherwise ride along.
     *
     * The shape `UserActivityLoggingTest` and `TransactionResourceTest`
     * established, applied to both models this feature adds.
     */
    public function test_nothing_outside_the_allowlist_is_logged(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->create(['note' => 'Awal']);
        $sale->update(['note' => 'Diubah']);

        $saleEntry = Activity::query()->where('log_name', 'sale')->latest('id')->first();

        $this->assertNotNull($saleEntry);
        $this->assertSame(
            ['note'],
            array_keys($saleEntry->attribute_changes['attributes']),
            'Only the allowlisted columns may reach the sale log.',
        );

        $item = SaleItem::factory()->forSale($sale)
            ->line(quantity: 1, catalog: 200_000, marketing: 150_000)
            ->create();
        $item->update(['quantity' => 2]);

        $itemEntry = Activity::query()->where('log_name', 'sale_item')->latest('id')->first();

        $this->assertNotNull($itemEntry);
        $this->assertSame(
            ['quantity'],
            array_keys($itemEntry->attribute_changes['attributes']),
            'Only the allowlisted columns may reach the sale line log.',
        );
    }

    /**
     * Both selects are required and neither has a free-text fallback, so the
     * form would otherwise open onto empty lists and refuse to save with a
     * message naming a field rather than the missing catalogue.
     */
    public function test_the_create_button_waits_for_a_customer_and_a_product(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(ListSales::class)
            ->assertActionHidden(TestAction::make('create'));

        Customer::factory()->create();

        Livewire::test(ListSales::class)
            ->assertActionHidden(TestAction::make('create'));

        Product::factory()->create();

        Livewire::test(ListSales::class)
            ->assertActionVisible(TestAction::make('create'));
    }

    /**
     * The date is what the row is filed under, and it defaults to now() rather
     * than being asked for — the sale is usually recorded as it happens.
     */
    public function test_the_purchase_date_defaults_to_now(): void
    {
        $this->actingAs($this->superAdmin());

        Carbon::setTestNow('2026-08-14 15:30:00');

        try {
            Livewire::test(CreateSale::class)
                ->assertFormSet(['occurred_at' => '2026-08-14 15:30']);
        } finally {
            Carbon::setTestNow();
        }
    }
}

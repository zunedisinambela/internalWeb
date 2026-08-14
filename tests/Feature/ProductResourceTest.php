<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/products')->assertRedirect('/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/products')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        Product::factory()->priced(200_000, 150_000)->create(['name' => 'Milk & Honey Gold']);

        $this->actingAs($this->superAdmin())
            ->get('/products')
            ->assertOk()
            ->assertSee('Milk &amp; Honey Gold', escape: false);
    }

    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-produk', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Product'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-produk@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/products')->assertOk();
        $this->actingAs($user)->get('/products/create')->assertForbidden();
    }

    /**
     * The margin is an accessor over the two stored prices, never a third
     * column, so it cannot disagree with the figures it came from.
     */
    public function test_the_unit_margin_is_derived_from_the_two_prices(): void
    {
        $product = Product::factory()->priced(200_000, 150_000)->create();

        $this->assertSame(50_000, $product->unit_profit);
    }

    /**
     * Not clamped at zero. A row written outside the form — from a seeder or
     * from tinker, with the two prices the wrong way round — has to read as
     * negative, because the alternative renders it as a product that merely
     * earns nothing.
     */
    public function test_a_margin_below_zero_is_reported_rather_than_clamped(): void
    {
        $product = Product::factory()->priced(100_000, 120_000)->create();

        $this->assertSame(-20_000, $product->unit_profit);
    }

    /**
     * The grouped field round trip: `200.000` is typed, 200000 is stored, and
     * reopening the row shows `200.000` again.
     */
    public function test_a_grouped_price_is_stored_as_an_integer(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Optimals Hydra Radiance',
                'catalog_price' => '200.000',
                'marketing_price' => '150.000',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->sole();

        $this->assertSame(200_000, $product->catalog_price);
        $this->assertSame(150_000, $product->marketing_price);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertFormSet([
                'catalog_price' => '200.000',
                'marketing_price' => '150.000',
            ]);
    }

    /**
     * The assertion that pins RupiahInput::notGreaterThan(), and the reason
     * Laravel's own ->lte() could not be used for it.
     *
     * Both fields hold grouped strings while the form is open. `lte` decides how
     * to compare from is_numeric(), which answers true for "150.000" — a float
     * string meaning 150.0 — and false for "1.500.000". One side of the same
     * comparison would be read as a number and the other as a string length,
     * with no error either way. These figures are chosen so that the broken
     * reading and the correct one disagree.
     */
    public function test_a_marketing_price_above_the_catalogue_price_is_refused(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Terbalik',
                'catalog_price' => '150.000',
                'marketing_price' => '1.500.000',
            ])
            ->call('create')
            ->assertHasFormErrors(['marketing_price']);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_equal_prices_are_accepted(): void
    {
        $this->actingAs($this->superAdmin());

        // A product sold on at cost earns nothing and is still a real sale — the
        // rule refuses "above", not "not below".
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Tanpa untung',
                'catalog_price' => '100.000',
                'marketing_price' => '100.000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Product::query()->sole()->unit_profit);
    }

    /**
     * A fractional amount is what WholeRupiah exists to refuse: stripping the
     * dot in `150.75` would silently store 15075, and the result is a perfectly
     * valid integer that nothing downstream can question.
     */
    public function test_a_fractional_price_is_refused(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Pecahan',
                'catalog_price' => '1500.75',
                'marketing_price' => '1.000',
            ])
            ->call('create')
            ->assertHasFormErrors(['catalog_price']);
    }

    /**
     * Both prices are on the LogsActivity allowlist deliberately: a sale line
     * carries copies of them, so a line whose figures match no current product
     * is only explicable from the log.
     */
    public function test_a_price_change_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $product = Product::factory()->priced(200_000, 150_000)->create();
        $product->update(['catalog_price' => 220_000]);

        $entry = Activity::query()->where('log_name', 'product')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('updated', $entry->event);
        // v5 keeps the diff in its own `attribute_changes` column rather than
        // burying it inside `properties` the way v4 did.
        $this->assertSame(200_000, $entry->attribute_changes['old']['catalog_price']);
        $this->assertSame(220_000, $entry->attribute_changes['attributes']['catalog_price']);
    }

    /**
     * The resource-level half of the delete rule, which turns the foreign key's
     * refusal into a missing button rather than a stack trace.
     */
    public function test_a_product_that_has_been_sold_cannot_be_deleted(): void
    {
        // canDelete() defers to the Shield policy first, so this has to run as
        // somebody the policy allows — otherwise both answers are false and the
        // test passes for the wrong reason.
        $this->actingAs($this->superAdmin());

        $sold = Product::factory()->create(['name' => 'Sudah terjual']);
        $unsold = Product::factory()->create(['name' => 'Belum terjual']);

        SaleItem::factory()->ofProduct($sold)->create(['sale_id' => Sale::factory()]);

        $this->assertFalse(ProductResource::canDelete($sold));
        $this->assertTrue(ProductResource::canDelete($unsold));
    }

    /**
     * The other half, covering tinker, a console command and anything else that
     * never asks the resource. sale_items.product_id is restrictOnDelete.
     */
    public function test_the_database_refuses_to_delete_a_product_that_has_been_sold(): void
    {
        $product = Product::factory()->create();

        SaleItem::factory()->ofProduct($product)->create(['sale_id' => Sale::factory()]);

        $this->expectException(QueryException::class);

        $product->delete();
    }

    /**
     * Deactivation is the exit a discontinued product actually takes, and it
     * leaves every sale that names it readable.
     */
    public function test_deactivating_a_product_keeps_its_sale_lines(): void
    {
        $product = Product::factory()->create();

        SaleItem::factory()->ofProduct($product)->create(['sale_id' => Sale::factory()]);

        $product->update(['is_active' => false]);

        $this->assertSame(1, $product->saleItems()->count());
    }
}

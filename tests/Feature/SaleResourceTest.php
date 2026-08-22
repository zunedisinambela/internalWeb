<?php

namespace Tests\Feature;

use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Customer;
use App\Models\Sale;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
        $customer = Customer::factory()->named('Zunedi')->create();
        Sale::factory()->forCustomer($customer)->create();

        $this->actingAs($this->superAdmin())
            ->get('/sales')
            ->assertOk()
            ->assertSee('Zunedi');
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
     * The worked example the flat shape was built from: Zunedi's order costs
     * Rp 190.000 from Oriflame, Rp 10.000 to post, and is sold at the catalogue
     * price of Rp 220.000.
     *
     * The margin is an accessor over the three stored columns, so it cannot
     * disagree with them — a stored copy would be a fourth number able to
     * contradict the three it came from.
     */
    public function test_the_margin_is_derived_from_the_three_figures(): void
    {
        $sale = Sale::factory()->priced(marketing: 190_000, catalog: 220_000, shipping: 10_000)->create();

        $this->assertSame(200_000, $sale->total_cost);
        $this->assertSame(20_000, $sale->profit);
    }

    /**
     * **The decision this shape rests on.** Ongkir is the consultant's cost, not
     * a line on the customer's bill: the customer pays the catalogue price and
     * nothing more, so shipping comes out of the margin rather than being added
     * on top.
     *
     * Two orders identical but for the postage therefore charge the customer the
     * same and earn the consultant different amounts. Reading it the other way
     * would make total_spent 230.000 here and the margin 30.000, and nothing
     * after the fact could tell the two readings apart — which is why one of
     * them is asserted.
     */
    public function test_shipping_is_a_cost_to_the_consultant_not_a_charge_to_the_customer(): void
    {
        $customer = Customer::factory()->create();

        $posted = Sale::factory()->forCustomer($customer)
            ->priced(marketing: 190_000, catalog: 220_000, shipping: 10_000)->create();
        $handed = Sale::factory()->forCustomer($customer)
            ->priced(marketing: 190_000, catalog: 220_000, shipping: 0)->create();

        $this->assertSame(20_000, $posted->profit);
        $this->assertSame(30_000, $handed->profit);

        // What the customer paid does not move with the postage.
        $this->assertSame(440_000, $customer->refresh()->total_spent);
        $this->assertSame(50_000, $customer->total_profit);
    }

    /**
     * The view screen shows the three figures and the margin they produce.
     */
    public function test_the_view_screen_renders_the_figures_and_the_margin(): void
    {
        $customer = Customer::factory()->named('Zunedi')->create();
        $sale = Sale::factory()->forCustomer($customer)
            ->priced(marketing: 190_000, catalog: 220_000, shipping: 10_000)->create();

        $this->actingAs($this->superAdmin())
            ->get("/sales/{$sale->getKey()}")
            ->assertOk()
            ->assertSee('Zunedi')
            ->assertSee('Rp 190.000')
            ->assertSee('Rp 10.000')
            ->assertSee('Rp 220.000')
            ->assertSee('Rp 20.000');
    }

    /**
     * The grouped-rupiah round trip, on all three fields at once.
     *
     * The trap this guards is RupiahInput's whole reason for existing: without
     * ->dehydrateStateUsing() the column receives the string "1.500.000", and
     * SQLite's loose typing stores **1** with no exception and no validation
     * message. Asserting the stored integers is the only way that stays caught.
     */
    public function test_grouped_amounts_are_stored_as_integers(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '1.500.000',
                'shipping_cost' => '25.000',
                'catalog_price' => '2.000.000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = Sale::query()->sole();

        $this->assertSame(1_500_000, $sale->marketing_price);
        $this->assertSame(25_000, $sale->shipping_cost);
        $this->assertSame(2_000_000, $sale->catalog_price);
        $this->assertSame(475_000, $sale->profit);
    }

    /**
     * Zero ongkir is a real answer, not an empty field — most orders are handed
     * over rather than posted.
     *
     * WholeRupiah's floor is 1 by default, which is right for a price and wrong
     * here, so the ongkir field lifts it with ->allowingZero(). Without that the
     * commonest case is refused with "Jumlah minimal Rp 1", a message about the
     * wrong problem.
     */
    public function test_a_zero_shipping_cost_is_accepted(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '190.000',
                'shipping_cost' => '0',
                'catalog_price' => '220.000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Sale::query()->sole()->shipping_cost);
    }

    /**
     * The other half of ->allowingZero(): lifting the floor on ongkir must not
     * lift it on the prices, where an amount of nothing is a half-filled form.
     */
    public function test_a_zero_price_is_still_refused(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '0',
                'shipping_cost' => '0',
                'catalog_price' => '220.000',
            ])
            ->call('create')
            ->assertHasFormErrors(['marketing_price']);

        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * In practice the two prices entered the wrong way round.
     *
     * The figures are picked so the broken reading and the correct one
     * disagree: Laravel's ->lte() decides how to compare from is_numeric(),
     * which answers true for "150.000" (a float string meaning 150.0) and false
     * for "1.500.000" (two dots), so it would compare one side as a number and
     * the other as a string length. RupiahInput::notGreaterThan() compares
     * through WholeRupiah::toInteger() instead.
     */
    public function test_a_marketing_price_above_the_catalogue_price_is_refused(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '1.500.000',
                'shipping_cost' => '0',
                'catalog_price' => '150.000',
            ])
            ->call('create')
            ->assertHasFormErrors(['marketing_price']);

        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * Equal prices are accepted: selling on at cost earns nothing and is still a
     * real sale.
     */
    public function test_equal_prices_are_accepted(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '200.000',
                'shipping_cost' => '0',
                'catalog_price' => '200.000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Sale::query()->sole()->profit);
    }

    /**
     * A margin can go negative for an honest reason now — a small order posted a
     * long way — so it is reported rather than clamped. max(0, …) would render
     * that order as one that happened to earn nothing, which is the reading that
     * hides it.
     */
    public function test_a_negative_margin_is_reported_rather_than_clamped(): void
    {
        $sale = Sale::factory()->priced(marketing: 100_000, catalog: 110_000, shipping: 30_000)->create();

        $this->assertSame(-20_000, $sale->profit);
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

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '190.000',
                'shipping_cost' => '10.000',
                'catalog_price' => '220.000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($admin->getKey(), Sale::query()->sole()->user_id);
    }

    /**
     * The customer select is required and has no free-text fallback, so the form
     * would otherwise open onto an empty list and refuse to save with a message
     * naming a field rather than the missing customer.
     *
     * One prerequisite now rather than two — there is no product catalogue to
     * wait for.
     */
    public function test_the_create_button_waits_for_a_customer(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(ListSales::class)
            ->assertActionHidden(TestAction::make('create'));

        Customer::factory()->create();

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

    /**
     * Ongkir starts at zero so the commonest order needs no typing, and the
     * field renders it grouped rather than blank — a blank required field reads
     * as the form being broken.
     */
    public function test_the_shipping_cost_defaults_to_zero(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateSale::class)
            ->assertFormSet(['shipping_cost' => '0']);
    }

    /**
     * All three figures are on the allowlist, and that is the point of it here:
     * they are the whole record of the order, so a margin that reads differently
     * today than it did last month is only explicable from the log.
     */
    public function test_a_price_correction_is_audited(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->priced(marketing: 190_000, catalog: 220_000, shipping: 10_000)->create();

        $sale->update(['marketing_price' => 180_000]);

        $entry = Activity::query()->where('log_name', 'sale')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(190_000, $entry->attribute_changes['old']['marketing_price']);
        $this->assertSame(180_000, $entry->attribute_changes['attributes']['marketing_price']);
    }

    /**
     * The allowlist is what keeps the log safe as columns are added, so the
     * assertion has to be that nothing *outside* it arrives — not merely that
     * the listed columns do. `user_id` is the one to watch: it is written on
     * every create and would otherwise ride along.
     *
     * The shape UserActivityLoggingTest and TransactionResourceTest established.
     */
    public function test_nothing_outside_the_allowlist_is_logged(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->create(['note' => 'Awal']);
        $sale->update(['note' => 'Diubah']);

        $entry = Activity::query()->where('log_name', 'sale')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(
            ['note'],
            array_keys($entry->attribute_changes['attributes']),
            'Only the allowlisted columns may reach the sale log.',
        );
    }

    /**
     * One sale is one act, and it now leaves exactly one entry — there are no
     * lines under it to account for separately.
     */
    public function test_deleting_a_sale_writes_one_audit_entry(): void
    {
        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->create();

        Activity::query()->delete();

        $sale->delete();

        $this->assertSame(1, Activity::query()->where('log_name', 'sale')->where('event', 'deleted')->count());
    }

    /**
     * The create path, which is the one that can break without anything else
     * noticing: on create the record does not exist when the file is uploaded,
     * so Filament holds it and attaches it after the insert. Every other test
     * here calls addMedia() on a saved row and would stay green if that
     * handover broke.
     */
    public function test_attachments_uploaded_on_the_create_form_reach_their_collections(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'occurred_at' => '2026-08-14 10:00',
                'marketing_price' => '190.000',
                'shipping_cost' => '10.000',
                'catalog_price' => '220.000',
                'payment_proofs' => [UploadedFile::fake()->image('transfer.jpg')],
                'shipping_proofs' => [UploadedFile::fake()->image('resi.jpg')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = Sale::query()->sole();

        $this->assertCount(1, $sale->getMedia(Sale::PAYMENT_PROOFS));
        $this->assertCount(1, $sale->getMedia(Sale::SHIPPING_PROOFS));
        $this->assertSame('local', $sale->getFirstMedia(Sale::PAYMENT_PROOFS)->disk);
    }

    /**
     * The disk decision, asserted rather than left to a comment. A transfer
     * receipt carries a bank account number and a name, a resi carries the
     * customer's home address; the `public` disk would make either readable by
     * URL with no role check and no policy.
     */
    public function test_attachments_land_on_the_private_disk(): void
    {
        Storage::fake('local');

        $sale = Sale::factory()->create();
        $sale->addMedia(UploadedFile::fake()->image('transfer.jpg'))
            ->toMediaCollection(Sale::PAYMENT_PROOFS);

        $media = $sale->refresh()->getFirstMedia(Sale::PAYMENT_PROOFS);

        $this->assertSame('local', $media->disk);
        $this->assertSame(Sale::PAYMENT_PROOFS, $media->collection_name);
    }

    /**
     * Which file is evidence of what is the whole point of attaching them, and
     * it is held by collection_name rather than by upload order — order is
     * destroyed by reordering or by deleting one file, and neither leaves a
     * trace that the pairing has shifted.
     */
    public function test_an_attachment_belongs_to_the_field_it_was_uploaded_against(): void
    {
        Storage::fake('local');

        $sale = Sale::factory()->create();
        $sale->addMedia(UploadedFile::fake()->image('transfer.jpg'))
            ->toMediaCollection(Sale::PAYMENT_PROOFS);
        $sale->addMedia(UploadedFile::fake()->image('resi.jpg'))
            ->toMediaCollection(Sale::SHIPPING_PROOFS);

        $sale->refresh();

        $this->assertSame('transfer.jpg', $sale->getFirstMedia(Sale::PAYMENT_PROOFS)->file_name);
        $this->assertSame('resi.jpg', $sale->getFirstMedia(Sale::SHIPPING_PROOFS)->file_name);
        $this->assertCount(1, $sale->getMedia(Sale::PAYMENT_PROOFS));
        $this->assertCount(1, $sale->getMedia(Sale::SHIPPING_PROOFS));

        // Both registered, so neither falls through to media-library's own
        // default disk — which is `public`, and would publish the file by URL
        // with no role check at all.
        $this->assertSame('local', $sale->getFirstMedia(Sale::SHIPPING_PROOFS)->disk);
    }

    /**
     * Several files per collection is the point of ->multiple() here: a split
     * payment is two transfers, and an order sent in two parcels is two resi.
     */
    public function test_a_collection_holds_more_than_one_file(): void
    {
        Storage::fake('local');

        $sale = Sale::factory()->create();
        $sale->addMedia(UploadedFile::fake()->image('transfer-1.jpg'))
            ->toMediaCollection(Sale::PAYMENT_PROOFS);
        $sale->addMedia(UploadedFile::fake()->image('transfer-2.jpg'))
            ->toMediaCollection(Sale::PAYMENT_PROOFS);

        $this->assertCount(2, $sale->refresh()->getMedia(Sale::PAYMENT_PROOFS));
    }

    /**
     * The `local` disk sets serve => true and no visibility key, so Laravel
     * treats it as private and its /storage route refuses any request without a
     * valid signature — before it looks for the file, which is why this works
     * against a faked disk.
     */
    public function test_an_attachment_cannot_be_fetched_without_a_signature(): void
    {
        Storage::fake('local');

        $sale = Sale::factory()->create();
        $sale->addMedia(UploadedFile::fake()->image('transfer.jpg'))
            ->toMediaCollection(Sale::PAYMENT_PROOFS);

        $path = $sale->refresh()
            ->getFirstMedia(Sale::PAYMENT_PROOFS)
            ->getPathRelativeToRoot();

        $this->get('/storage/'.$path)->assertForbidden();
    }

    /**
     * Renders the screens that actually resolve an attachment URL.
     *
     * The image column and the image entry take a separate code path when the
     * component is marked private — they ask medialibrary for a temporary URL
     * instead of a plain one. Nothing else here would notice a broken one: it
     * still renders, it just renders as a broken image. Both collections are
     * filled so a missing flag on either component is caught here rather than by
     * eye.
     */
    public function test_every_screen_renders_with_an_attachment(): void
    {
        Storage::fake('local');

        $customer = Customer::factory()->named('Zunedi')->create();
        $sale = Sale::factory()->forCustomer($customer)->create();
        $sale->addMedia(UploadedFile::fake()->image('transfer.jpg'))
            ->toMediaCollection(Sale::PAYMENT_PROOFS);
        $sale->addMedia(UploadedFile::fake()->image('resi.jpg'))
            ->toMediaCollection(Sale::SHIPPING_PROOFS);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/sales')->assertOk()->assertSee('Zunedi');
        $this->actingAs($admin)->get('/sales/create')->assertOk();
        $this->actingAs($admin)->get('/sales/'.$sale->getKey())->assertOk();
        $this->actingAs($admin)->get('/sales/'.$sale->getKey().'/edit')->assertOk();
    }

    /**
     * Attachments are a relation, so LogsActivity cannot see them. Removing the
     * proof that an order was paid for is exactly what an audit trail is for —
     * the same split LogRoleChange makes for roles.
     *
     * Both collections write the same event key; which one lost the file is in
     * the `collection` property, so filtering for "a sale attachment was
     * removed" does not mean remembering two keys.
     */
    public function test_deleting_an_attachment_is_audited(): void
    {
        Storage::fake('local');

        $sale = Sale::factory()->create();
        $sale->addMedia(UploadedFile::fake()->image('resi.jpg'))
            ->toMediaCollection(Sale::SHIPPING_PROOFS);

        $sale->refresh()->getFirstMedia(Sale::SHIPPING_PROOFS)->delete();

        $entry = Activity::query()->where('event', 'sale_attachment_deleted')->sole();

        $this->assertSame('sale', $entry->log_name);
        $this->assertSame('Lampiran penjualan dihapus', $entry->description);
        $this->assertSame('resi.jpg', $entry->properties->get('file_name'));
        $this->assertSame(Sale::SHIPPING_PROOFS, $entry->properties->get('collection'));
        $this->assertSame($sale->getKey(), $entry->properties->get('sale_id'));
    }

    /**
     * Deleting a sale writes its own entry *and* one per attachment. The
     * duplication is wanted: a file removed on its own and a file that went down
     * with its row are different events, and the log should not have to infer
     * which happened.
     *
     * It depends on two unrelated mechanisms lining up — medialibrary removing
     * its files from the `deleting` event, and the Media::deleted listener
     * firing once per row — so the counts are asserted rather than left to be
     * noticed later.
     */
    public function test_deleting_a_sale_audits_the_row_and_each_attachment(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin());

        $sale = Sale::factory()->create();
        $sale->addMedia(UploadedFile::fake()->image('transfer.jpg'))->toMediaCollection(Sale::PAYMENT_PROOFS);
        $sale->addMedia(UploadedFile::fake()->image('resi.jpg'))->toMediaCollection(Sale::SHIPPING_PROOFS);

        Activity::query()->delete();

        $sale->delete();

        $this->assertSame(1, Activity::query()
            ->where('log_name', 'sale')
            ->where('event', 'deleted')
            ->count());

        $this->assertSame(2, Activity::query()
            ->where('log_name', 'sale')
            ->where('event', 'sale_attachment_deleted')
            ->count());
    }
}

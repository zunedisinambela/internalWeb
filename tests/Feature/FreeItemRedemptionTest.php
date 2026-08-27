<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\FreeItemRedemptionsRelationManager;
use App\Models\Customer;
use App\Models\FreeItemRedemption;
use App\Models\Sale;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The other half of the bonus question.
 *
 * What a customer has *earned* is arithmetic over their orders and cannot
 * disagree with them. Whether they have *collected* it is a fact about the
 * world, so it is recorded — a row per handover carrying the date and the resi.
 * These tests are about the seam between the two: the earned figure moving
 * while the collected one stays put, and the form refusing to hand over a bonus
 * twice.
 */
class FreeItemRedemptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A customer who has bought 40 items has earned two free ones; collecting
     * one leaves one owed. The earned figure is untouched by the handover —
     * they are two different questions and the screen shows both.
     */
    public function test_a_handover_draws_down_what_is_owed_without_touching_what_was_earned(): void
    {
        $customer = $this->customerWithItems(40);

        FreeItemRedemption::factory()->forCustomer($customer)->create();

        $customer->refresh();

        $this->assertSame(2, $customer->free_quantity);
        $this->assertSame(1, $customer->free_quantity_claimed);
        $this->assertSame(1, $customer->free_quantity_available);
    }

    public function test_two_free_items_can_be_collected_in_one_handover(): void
    {
        $customer = $this->customerWithItems(40);

        FreeItemRedemption::factory()->forCustomer($customer)->quantity(2)->create();

        $customer->refresh();

        $this->assertSame(2, $customer->free_quantity_claimed);
        $this->assertSame(0, $customer->free_quantity_available);
    }

    /**
     * A customer who has collected nothing is owed everything they earned, and
     * one who has bought nothing is owed nothing at all.
     */
    public function test_nothing_collected_leaves_the_whole_bonus_owed(): void
    {
        $this->assertSame(2, $this->customerWithItems(40)->free_quantity_available);
        $this->assertSame(0, Customer::factory()->create()->free_quantity_available);
    }

    /**
     * The figure is deliberately not clamped: a handover recorded against an
     * order later corrected downwards is a real bookkeeping problem, and a
     * negative is how it becomes visible. max(0, …) would render the same
     * customer as one who happens to be owed nothing.
     */
    public function test_a_bonus_collected_and_then_unearned_reads_as_a_negative(): void
    {
        $customer = $this->customerWithItems(20);

        FreeItemRedemption::factory()->forCustomer($customer)->create();

        // The order turns out to have been eighteen items, not twenty.
        $customer->sales()->first()->update(['quantity' => 18]);

        $customer->refresh();

        $this->assertSame(0, $customer->free_quantity);
        $this->assertSame(1, $customer->free_quantity_claimed);
        $this->assertSame(-1, $customer->free_quantity_available);
    }

    /**
     * The wiring, asserted on the real page: the three bonus figures render in
     * the infolist, and the handover table is registered on the resource.
     *
     * The table's own contents are deliberately not asserted here — a relation
     * manager is lazy by default, so the first response carries a placeholder
     * and the rows arrive on a second Livewire request. Every other test in this
     * file drives that component directly.
     */
    public function test_the_customer_screen_carries_the_three_figures_and_registers_the_handover_table(): void
    {
        $customer = $this->customerWithItems(40, 'Zunedi');
        FreeItemRedemption::factory()->forCustomer($customer)->create();

        $this->actingAs($this->superAdmin())
            ->get(CustomerResource::getUrl('view', ['record' => $customer]))
            ->assertOk()
            ->assertSee('Barang gratis')
            ->assertSee('Sudah diambil')
            ->assertSee('Sisa belum diambil');

        $this->assertContains(
            FreeItemRedemptionsRelationManager::class,
            CustomerResource::getRelations(),
        );
    }

    /**
     * The rule the form is there to enforce. Asked of the relation manager
     * rather than of the model, because that is where it lives — the model
     * records what happened and the form decides what may be recorded.
     */
    public function test_the_form_refuses_to_hand_over_more_than_is_owed(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(20);

        $this->relationManager($customer)
            ->callAction(TestAction::make('create')->table(), [
                'redeemed_at' => now()->subDay()->format('Y-m-d H:i'),
                'quantity' => 2,
            ])
            ->assertHasActionErrors(['quantity']);

        $this->assertSame(0, FreeItemRedemption::query()->count());
    }

    /**
     * The pin on `fresh()` in availableFor(): a handover saved a moment ago is
     * not in the owner record the page was rendered with, so a check against the
     * copy in memory would still see two bonuses owed and let the second, larger
     * one through.
     *
     * Two collected out of two earned, in the same component — the first is
     * accepted, and the second is refused *by the remainder it left behind*
     * rather than by the button, which is still on screen because one bonus is
     * genuinely still owed.
     */
    public function test_a_second_handover_is_measured_against_what_the_first_one_left(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(40);

        $manager = $this->relationManager($customer);

        $manager->callAction(TestAction::make('create')->table(), [
            'redeemed_at' => now()->subDay()->format('Y-m-d H:i'),
            'quantity' => 1,
        ])->assertHasNoActionErrors();

        $manager->callAction(TestAction::make('create')->table(), [
            'redeemed_at' => now()->subHours(2)->format('Y-m-d H:i'),
            'quantity' => 2,
        ])->assertHasActionErrors(['quantity']);

        // The remaining one is still collectable — the refusal was about the
        // size of the second handover, not about the bonus being spent.
        $this->relationManager($customer->refresh())
            ->callAction(TestAction::make('create')->table(), [
                'redeemed_at' => now()->subHours(2)->format('Y-m-d H:i'),
                'quantity' => 1,
            ])->assertHasNoActionErrors();

        $this->assertSame(2, $customer->refresh()->free_quantity_claimed);
    }

    /**
     * Once nothing is owed the button goes rather than being disabled: a form
     * every submission of which is refused explains the rule worse than its
     * absence plus the sentence in the empty state.
     */
    public function test_the_button_is_gone_once_every_bonus_has_been_collected(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(20);

        $this->relationManager($customer)
            ->assertActionVisible(TestAction::make('create')->table());

        FreeItemRedemption::factory()->forCustomer($customer)->create();

        $this->relationManager($customer->refresh())
            ->assertActionHidden(TestAction::make('create')->table());
    }

    /**
     * Editing an existing handover must not be refused by the handover itself.
     * Its own quantity is already inside the claimed total, so availableFor()
     * adds it back — without that, correcting a resi on a row that used up the
     * last bonus would be impossible.
     */
    public function test_an_existing_handover_can_be_edited_without_being_refused_by_itself(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(20);
        $redemption = FreeItemRedemption::factory()->forCustomer($customer)->create();

        $this->relationManager($customer)
            ->callAction(TestAction::make('edit')->table($redemption), [
                'redeemed_at' => $redemption->redeemed_at->format('Y-m-d H:i'),
                'quantity' => 1,
                'tracking_number' => 'JP1234567890',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('JP1234567890', $redemption->refresh()->tracking_number);
    }

    /**
     * The date is the whole point of the row, so the form records the one that
     * was typed rather than the moment it was saved.
     */
    public function test_the_handover_records_the_date_and_the_resi_that_were_entered(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(20);

        $this->relationManager($customer)
            ->callAction(TestAction::make('create')->table(), [
                'redeemed_at' => '2026-08-20 09:30',
                'quantity' => 1,
                'tracking_number' => 'JNE0099887766',
                'note' => 'Diambil bersama pesanan berikutnya',
            ])
            ->assertHasNoActionErrors();

        $redemption = FreeItemRedemption::query()->sole();

        $this->assertSame('2026-08-20 09:30:00', $redemption->redeemed_at->format('Y-m-d H:i:s'));
        $this->assertSame('JNE0099887766', $redemption->tracking_number);
        $this->assertSame($customer->getKey(), $redemption->customer_id);
    }

    /**
     * A free item handed over in person has no resi at all, which is the common
     * case for a nearby customer — the column is nullable and the form must not
     * quietly require it.
     */
    public function test_a_handover_without_a_resi_is_accepted(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(20);

        $this->relationManager($customer)
            ->callAction(TestAction::make('create')->table(), [
                'redeemed_at' => now()->subDay()->format('Y-m-d H:i'),
                'quantity' => 1,
            ])
            ->assertHasNoActionErrors();

        $this->assertNull(FreeItemRedemption::query()->sole()->tracking_number);
    }

    /**
     * The disk decision, asserted rather than left to a comment: a resi carries
     * the customer's home address, and the `public` disk would make it readable
     * by URL with no role check and no policy.
     */
    public function test_the_resi_photo_lands_on_the_private_disk(): void
    {
        Storage::fake('local');

        $redemption = FreeItemRedemption::factory()->create();
        $redemption->addMedia(UploadedFile::fake()->image('resi.jpg'))
            ->toMediaCollection(FreeItemRedemption::SHIPPING_PROOFS);

        $media = $redemption->refresh()->getFirstMedia(FreeItemRedemption::SHIPPING_PROOFS);

        $this->assertSame('local', $media->disk);
        $this->assertSame(FreeItemRedemption::SHIPPING_PROOFS, $media->collection_name);
    }

    /**
     * The create path specifically: on create the record does not exist when
     * the file is uploaded, so Filament holds it and attaches it after the
     * insert. The test above calls addMedia() on a saved row and would stay
     * green if that handover broke.
     */
    public function test_a_resi_photo_uploaded_on_the_form_reaches_the_collection(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin());

        $customer = $this->customerWithItems(20);

        $this->relationManager($customer)
            ->callAction(TestAction::make('create')->table(), [
                'redeemed_at' => now()->subDay()->format('Y-m-d H:i'),
                'quantity' => 1,
                'shipping_proofs' => [UploadedFile::fake()->image('resi.jpg')],
            ])
            ->assertHasNoActionErrors();

        $this->assertCount(1, FreeItemRedemption::query()->sole()->getMedia(FreeItemRedemption::SHIPPING_PROOFS));
    }

    /**
     * Its own log name: "when did somebody collect a free item" is a different
     * question from "what did this customer buy", and folding it into the
     * `customer` or `sale` log would mean reading past one to answer the other.
     */
    public function test_a_handover_is_audited_under_its_own_log_name(): void
    {
        $customer = $this->customerWithItems(20);

        $redemption = FreeItemRedemption::factory()->forCustomer($customer)->create();
        $redemption->update(['tracking_number' => 'JP1234567890']);

        $entry = Activity::query()
            ->where('log_name', 'free_item_redemption')
            ->where('event', 'updated')
            ->sole();

        $this->assertSame(
            'JP1234567890',
            $entry->attribute_changes['attributes']['tracking_number'],
        );
    }

    /**
     * Media is a relation, so LogsActivity cannot see it — the same split
     * LogRoleChange makes for roles. Removing the resi photograph is a
     * meaningful edit to the record even though no column changes.
     */
    public function test_removing_the_resi_photo_is_audited(): void
    {
        Storage::fake('local');

        $redemption = FreeItemRedemption::factory()->create();
        $redemption->addMedia(UploadedFile::fake()->image('resi.jpg'))
            ->toMediaCollection(FreeItemRedemption::SHIPPING_PROOFS);

        Activity::query()->delete();

        $redemption->refresh()->getFirstMedia(FreeItemRedemption::SHIPPING_PROOFS)->delete();

        $entry = Activity::query()
            ->where('log_name', 'free_item_redemption')
            ->where('event', 'redemption_resi_deleted')
            ->sole();

        $this->assertSame('resi.jpg', $entry->properties['file_name']);
        $this->assertSame(FreeItemRedemption::SHIPPING_PROOFS, $entry->properties['collection']);
    }

    /**
     * The author stamp, taken from the session the same way Sale takes it.
     */
    public function test_the_signed_in_user_is_stamped_on_a_handover(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $customer = $this->customerWithItems(20);

        $this->relationManager($customer)
            ->callAction(TestAction::make('create')->table(), [
                'redeemed_at' => now()->subDay()->format('Y-m-d H:i'),
                'quantity' => 1,
            ]);

        $this->assertSame($admin->getKey(), FreeItemRedemption::query()->sole()->user_id);
    }

    /**
     * What the list is actually scanned for is who is still owed something, so
     * the description carries the remainder rather than the earned figure. Both
     * halves arrive as aggregate subqueries rather than from the accessors, so
     * this asserts the second of the two routes to the same number.
     */
    public function test_the_customer_list_shows_what_is_still_owed(): void
    {
        $this->actingAs($this->superAdmin());

        $owed = $this->customerWithItems(40, 'Zunedi');
        FreeItemRedemption::factory()->forCustomer($owed)->create();

        $settled = $this->customerWithItems(20, 'Ayu');
        FreeItemRedemption::factory()->forCustomer($settled)->create();

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$owed, $settled])
            ->assertSee('+1 gratis belum diambil')
            ->assertSee('gratis sudah diambil');
    }

    /**
     * The same rule the foreign key enforces, turned into a missing button.
     * A handover is a record of something that happened to a person, so
     * removing the person would take the meaning out of it — is_active is the
     * exit, exactly as it is for a customer with sales.
     */
    public function test_a_customer_with_a_handover_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin());

        $customer = Customer::factory()->named('Pernah ambil gratis')->create();
        FreeItemRedemption::factory()->forCustomer($customer)->create();

        $this->assertFalse(CustomerResource::canDelete($customer));
    }

    /**
     * The relation manager is gated on the customer's own permissions, because
     * Shield generates permissions from resources and this model has none. A
     * user who cannot see customers must not reach their handovers.
     */
    public function test_the_handovers_are_gated_on_the_customers_permissions(): void
    {
        $this->seedRoles();

        $stranger = $this->userWithRole(null, ['email' => 'bukan-siapa-siapa@admin.com']);

        $this->assertFalse(
            FreeItemRedemptionsRelationManager::canViewForRecord(
                Customer::factory()->create(),
                ViewCustomer::class,
            ),
        );

        $this->actingAs($stranger);

        $this->assertFalse(
            FreeItemRedemptionsRelationManager::canViewForRecord(
                Customer::factory()->create(),
                ViewCustomer::class,
            ),
        );

        $this->actingAs($this->superAdmin());

        $this->assertTrue(
            FreeItemRedemptionsRelationManager::canViewForRecord(
                Customer::factory()->create(),
                ViewCustomer::class,
            ),
        );
    }

    /**
     * A customer who has bought $items items, in one order.
     */
    private function customerWithItems(int $items, ?string $name = null): Customer
    {
        $customer = Customer::factory()->named($name ?? fake()->name())->create();

        Sale::factory()->forCustomer($customer)->quantity($items)->create();

        return $customer->refresh();
    }

    private function relationManager(Customer $customer): Testable
    {
        return Livewire::test(FreeItemRedemptionsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ]);
    }
}

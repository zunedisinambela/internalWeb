<?php

namespace Tests\Feature;

use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Models\ElectricityTariff;
use App\Models\MeterReading;
use App\Models\Room;
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

class MeterReadingResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/meter-readings')->assertRedirect('/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/meter-readings')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        $room = Room::factory()->create(['name' => 'Kamar B2']);
        MeterReading::factory()->forRoom($room)->usage(120)->create();

        $this->actingAs($this->superAdmin())
            ->get('/meter-readings')
            ->assertOk()
            ->assertSee('Kamar B2');
    }

    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-meteran', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:MeterReading'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-meteran@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/meter-readings')->assertOk();
        $this->actingAs($user)->get('/meter-readings/create')->assertForbidden();
    }

    /**
     * The arithmetic the whole feature exists for. Both factors are integers, so
     * the product is exact — that is the payoff for keeping kWh and the rate out
     * of floating point.
     */
    public function test_usage_and_the_total_are_derived_from_the_stored_figures(): void
    {
        $reading = MeterReading::factory()->usage(137, rate: 1_650, start: 4_200)->create();

        $this->assertSame(4_200, $reading->start_kwh);
        $this->assertSame(4_337, $reading->end_kwh);
        $this->assertSame(137, $reading->usage_kwh);
        $this->assertSame(226_050, $reading->total_amount);
    }

    /**
     * The single most important assertion in this file.
     *
     * The rate is copied onto the reading when it is recorded, never joined to
     * from electricity_tariffs. Without that copy, entering a new tariff would
     * silently recompute every bill already issued — no row changed, nothing in
     * the activity log, and a tenant's July bill quietly becoming August's rate.
     */
    public function test_a_later_tariff_does_not_change_a_recorded_reading(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-07-01')->create();

        $reading = MeterReading::factory()->usage(100, rate: 1_500)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        $this->assertSame(150_000, $reading->total_amount);

        ElectricityTariff::factory()->rate(2_000, '2026-08-01')->create();

        $this->assertSame(1_500, $reading->fresh()->rate);
        $this->assertSame(150_000, $reading->fresh()->total_amount);
        // The tariff screen has moved on; the recorded bill has not.
        $this->assertSame(2_000, ElectricityTariff::currentRate());
    }

    /**
     * The form's half of that: a new reading arrives with the rate in force
     * already filled in, so the copy happens without anyone having to remember.
     */
    public function test_the_form_prefills_the_rate_in_force(): void
    {
        Carbon::setTestNow('2026-08-14 15:00:00');
        ElectricityTariff::factory()->rate(1_650, '2026-08-01')->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            // Shown grouped, stored bare — the two are inverses through
            // WholeRupiah, the same as the transaction amount field.
            ->assertSchemaStateSet(['rate' => '1.650'], schema: 'form');
    }

    /**
     * The rate is not a decision taken at the meter, so it is not asked for
     * there — it is set once on the tariff screen and copied.
     *
     * This is the assertion that makes hiding the field safe. Filament does not
     * dehydrate a hidden component unless told to, so without
     * ->dehydratedWhenHidden() the column would receive nothing, and the snapshot
     * the whole feature rests on would be gone with no error anywhere.
     */
    public function test_the_rate_is_copied_even_though_the_field_is_hidden(): void
    {
        Carbon::setTestNow('2026-08-14 15:00:00');
        ElectricityTariff::factory()->rate(1_650, '2026-08-01')->create();

        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaComponentHidden('rate', 'form')
            // Deliberately not filled — that is the point.
            ->fillForm([
                'room_id' => $room->getKey(),
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reading = MeterReading::query()->sole();

        $this->assertSame(1_650, $reading->rate);
        $this->assertSame(165_000, $reading->total_amount);
    }

    /**
     * The escape hatch stays reachable. `rate` is NOT NULL and there is nothing
     * to copy, so hiding the field here would refuse the save with a message
     * naming a field nobody can see.
     */
    public function test_the_rate_field_appears_when_there_is_no_tariff_to_copy(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaComponentVisible('rate', 'form');
    }

    /**
     * Hidden on the edit screen too. The cost is that a rate typed wrong is no
     * longer correctable from the panel; that trade was made knowingly, and this
     * test is what stops the field drifting back onto the screen unnoticed.
     */
    public function test_the_rate_field_stays_hidden_when_editing_a_recorded_reading(): void
    {
        ElectricityTariff::factory()->rate(1_650, '2026-08-01')->create();

        $reading = MeterReading::factory()->usage(100)->create();

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->assertSchemaComponentHidden('rate', 'form');
    }

    /**
     * The snapshot has to survive an edit, and hiding the field is where that
     * could quietly break.
     *
     * The reading below stores 1.500 while the tariff screen has since moved to
     * 2.000. Saving an unrelated field must write the stored rate back unchanged
     * — not re-copy the rate in force, which would reprice a bill already issued
     * while looking like an ordinary edit. ->default() is what fills the field on
     * create; on edit the stored value is what loads, and this asserts the round
     * trip through formatStateUsing/dehydrateStateUsing does not lose it.
     */
    public function test_editing_a_reading_does_not_recopy_the_current_tariff(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-07-01')->create();

        $reading = MeterReading::factory()->usage(100, rate: 1_500)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        ElectricityTariff::factory()->rate(2_000, '2026-08-01')->create();

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->fillForm(['note' => 'Angka sulit dibaca'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reading->refresh();

        $this->assertSame(1_500, $reading->rate);
        $this->assertSame(150_000, $reading->total_amount);
        $this->assertSame('Angka sulit dibaca', $reading->note);
    }

    /**
     * The escape hatch from the snapshot, and the assertion that it stays one:
     * the action fills the open form and writes nothing. Closing the page without
     * saving has to leave the bill exactly as it was.
     *
     * The rate field is hidden here, so the form state is the only place the new
     * figure is visible at all — which is why it is what gets asserted.
     */
    public function test_refreshing_the_rate_fills_the_form_without_saving(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-07-01')->create();

        // Recorded at a rate that was simply wrong — the case the snapshot
        // deliberately leaves unserved, and this button exists for.
        $reading = MeterReading::factory()->usage(100, rate: 1_200)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->callAction('refreshRate')
            // Grouped, because that is the shape the field holds.
            ->assertSchemaStateSet(['rate' => '1.500'], schema: 'form');

        // The row still holds what it was recorded at.
        $this->assertSame(1_200, $reading->fresh()->rate);
        $this->assertSame(120_000, $reading->fresh()->total_amount);
    }

    /**
     * The other half: Simpan is what commits it, through the ordinary save path
     * — so the correction lands in `meter_reading` the same way a rate fixed from
     * tinker would, rather than moving a bill with nothing to show for it.
     */
    public function test_saving_after_a_rate_refresh_commits_it_and_audits_it(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-07-01')->create();

        $reading = MeterReading::factory()->usage(100, rate: 1_200)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        Activity::query()->delete();

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->callAction('refreshRate')
            ->call('save')
            ->assertHasNoFormErrors();

        $reading->refresh();

        $this->assertSame(1_500, $reading->rate);
        $this->assertSame(150_000, $reading->total_amount);

        $entry = Activity::query()->where('log_name', 'meter_reading')->latest('id')->first();

        $this->assertNotNull($entry, 'A correction made this way has to be audited like any other.');
        $this->assertSame(1_200, $entry->attribute_changes['old']['rate']);
        $this->assertSame(1_500, $entry->attribute_changes['attributes']['rate']);
    }

    /**
     * The one place this deliberately differs from the sales action it copies.
     *
     * Product prices are not versioned, so "the current price" is the only answer
     * there. Tariffs are, so a July reading corrected in August has two candidate
     * rates — and the newest one is the wrong one. Taking August's rate onto a
     * July bill is exactly the repricing the snapshot exists to prevent, arriving
     * through a button instead of through a join.
     */
    public function test_the_rate_refresh_takes_the_tariff_in_force_when_the_period_closed(): void
    {
        Carbon::setTestNow('2026-08-14 15:00:00');

        ElectricityTariff::factory()->rate(1_500, '2026-07-01')->create();
        ElectricityTariff::factory()->rate(2_000, '2026-08-01')->create();

        $reading = MeterReading::factory()->usage(100, rate: 1_200)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->callAction('refreshRate')
            ->call('save')
            ->assertHasNoFormErrors();

        // July's rate, not today's.
        $this->assertSame(1_500, $reading->fresh()->rate);
        $this->assertSame(2_000, ElectricityTariff::currentRate());
    }

    /**
     * The button answers "is this bill on the right tariff?" by being absent. A
     * modal that opens only to say nothing would change is a worse answer than no
     * button — and here it matters more than on a sale, because the rate field is
     * hidden and the modal is the only place the figure is ever shown.
     */
    public function test_the_rate_refresh_button_is_hidden_when_the_rate_already_matches(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-07-01')->create();

        $reading = MeterReading::factory()->usage(100, rate: 1_500)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->assertActionHidden('refreshRate');

        $reading->update(['rate' => 1_200]);

        Livewire::actingAs($admin)
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->assertActionVisible('refreshRate');
    }

    /**
     * A reading closed before any tariff was ever set has nothing to copy from.
     * Falling back to the earliest or the newest rate would put a figure onto a
     * bill that no tariff row can account for.
     */
    public function test_the_rate_refresh_button_is_hidden_when_no_tariff_had_taken_effect_yet(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-08-01')->create();

        $reading = MeterReading::factory()->usage(100, rate: 1_200)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->assertActionHidden('refreshRate');
    }

    /**
     * The confirmation is what makes this a correction rather than a silent
     * rewrite. It has to name both rates and the bill they produce, because the
     * rate field is hidden — the total is the only thing the user can check.
     *
     * A tariff note is typed by a user and the modal body is rendered as HTML, so
     * it is escaped.
     */
    public function test_the_rate_refresh_confirmation_names_both_rates_and_escapes_the_tariff_note(): void
    {
        ElectricityTariff::factory()->rate(1_500, '2026-07-01')
            ->create(['note' => 'Naik & <b>disesuaikan</b> PLN']);

        $reading = MeterReading::factory()->usage(100, rate: 1_200)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        $description = (string) Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->instance()
            ->getAction('refreshRate')
            ->getModalDescription();

        $this->assertStringContainsString('Rp 1.200', $description);
        $this->assertStringContainsString('Rp 1.500', $description);
        // The bill before and after, which is what the correction actually moves.
        $this->assertStringContainsString('Rp 120.000', $description);
        $this->assertStringContainsString('Rp 150.000', $description);
        $this->assertStringContainsString('Naik &amp; &lt;b&gt;disesuaikan&lt;/b&gt; PLN', $description);
        $this->assertStringNotContainsString('<b>disesuaikan</b>', $description);
    }

    /**
     * The opening figure is the previous reading's closing figure. That is what
     * makes the two numbers one continuous meter rather than two unrelated
     * fields nobody can check.
     */
    public function test_the_opening_figure_is_prefilled_from_the_previous_reading(): void
    {
        $room = Room::factory()->create();
        MeterReading::factory()->forRoom($room)->usage(200, start: 3_000)
            ->create(['end_read_at' => '2026-07-01 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm(['room_id' => $room->getKey()])
            ->assertSchemaStateSet(['start_kwh' => 3_200], schema: 'form');
    }

    /**
     * And its moment, for the same reason: a period opens where the last one
     * closed. Prefilled from the previous end_read_at rather than from now(),
     * which would leave a gap nobody entered and nobody can account for.
     */
    public function test_the_opening_moment_is_prefilled_from_the_previous_reading(): void
    {
        $room = Room::factory()->create();
        MeterReading::factory()->forRoom($room)->usage(200, start: 3_000)
            ->create(['end_read_at' => '2026-07-01 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm(['room_id' => $room->getKey()])
            // Without seconds — the shape the ->seconds(false) picker carries.
            ->assertSchemaStateSet(['start_read_at' => '2026-07-01 09:00'], schema: 'form');
    }

    /**
     * A room being read for the first time keeps the default rather than having
     * it blanked. Overwriting a required field with null on the one path that has
     * nothing to copy from would read as the form breaking, not as an empty
     * history.
     */
    public function test_a_room_with_no_history_keeps_the_default_opening_moment(): void
    {
        Carbon::setTestNow('2026-08-14 15:12:00');

        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm(['room_id' => $room->getKey()])
            ->assertSchemaStateSet(['start_read_at' => '2026-08-14 15:12'], schema: 'form');
    }

    /**
     * A meter nobody has recorded yet starts at zero rather than blank — a blank
     * required field on a form that just filled two others in reads as a bug.
     */
    public function test_a_room_with_no_history_opens_at_zero(): void
    {
        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm(['room_id' => $room->getKey()])
            ->assertSchemaStateSet(['start_kwh' => 0], schema: 'form');
    }

    /**
     * A closing figure below the opening one is either a typo or a replaced
     * meter, and the two need different handling. Refusing it means the second
     * has to be entered deliberately rather than silently producing a negative
     * bill that still looks like a number.
     */
    public function test_a_closing_figure_below_the_opening_one_is_refused(): void
    {
        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'room_id' => $room->getKey(),
                'start_kwh' => 5_000,
                'end_kwh' => 4_900,
                'rate' => '1.500',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['end_kwh']);

        $this->assertSame(0, MeterReading::query()->count());
    }

    public function test_an_unchanged_meter_records_a_zero_bill(): void
    {
        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'room_id' => $room->getKey(),
                'start_kwh' => 5_000,
                'end_kwh' => 5_000,
                'rate' => '1.500',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reading = MeterReading::query()->sole();

        $this->assertSame(0, $reading->usage_kwh);
        $this->assertSame(0, $reading->total_amount);
    }

    /**
     * Stamped server-side rather than taken from the form, so a crafted request
     * cannot attribute a reading to someone else.
     */
    public function test_the_author_is_stamped_on_create(): void
    {
        $admin = $this->superAdmin();
        $room = Room::factory()->create();

        Livewire::actingAs($admin)
            ->test(CreateMeterReading::class)
            ->fillForm([
                'room_id' => $room->getKey(),
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'rate' => '1.500',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($admin->getKey(), MeterReading::query()->sole()->user_id);
    }

    /**
     * now() is already WIB — APP_TIMEZONE is Asia/Jakarta and timestamps are
     * stored in local time, so nothing is converted anywhere in this feature.
     */
    public function test_both_reading_times_default_to_now(): void
    {
        Carbon::setTestNow('2026-08-14 15:12:00');

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            // Without seconds: both pickers are configured ->seconds(false), so
            // that is the shape the form state carries.
            ->assertSchemaStateSet([
                'start_read_at' => '2026-08-14 15:12',
                'end_read_at' => '2026-08-14 15:12',
            ], schema: 'form');
    }

    /**
     * A period that closes before it opens is a typo, and an expensive one: it is
     * end_read_at that dates the row, so such a reading would sort into the wrong
     * place forever and previousFor() would offer it as the predecessor of
     * readings taken before it.
     */
    public function test_a_closing_moment_before_the_opening_one_is_refused(): void
    {
        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'room_id' => $room->getKey(),
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'rate' => '1.500',
                'start_read_at' => '2026-08-14 09:00',
                'end_read_at' => '2026-07-14 09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['end_read_at']);

        $this->assertSame(0, MeterReading::query()->count());
    }

    /**
     * Both figures read in one visit is a real case — a room let mid-month, a
     * meter replaced — so the two moments being equal is accepted where the
     * reverse is not.
     */
    public function test_both_figures_may_be_read_at_the_same_moment(): void
    {
        $room = Room::factory()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'room_id' => $room->getKey(),
                'start_kwh' => 0,
                'end_kwh' => 0,
                'rate' => '1.500',
                'start_read_at' => '2026-08-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, MeterReading::query()->count());
    }

    /**
     * The create button is hidden until a room exists. room_id is required and
     * has no free-text fallback, so the form would otherwise open onto an empty
     * select and refuse to save with a message naming the field rather than the
     * missing room.
     */
    public function test_the_create_button_appears_only_once_a_room_exists(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(ListMeterReadings::class)
            ->assertActionHidden(TestAction::make('create'));

        Room::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListMeterReadings::class)
            ->assertActionVisible(TestAction::make('create'));
    }

    /**
     * The disk decision, asserted rather than left to a comment. A photograph of
     * a meter carries the room and usually part of the building; the `public`
     * disk would make it readable by URL with no role check and no policy.
     */
    public function test_photos_land_on_the_private_disk(): void
    {
        Storage::fake('local');

        $reading = MeterReading::factory()->create();
        $reading->addMedia(UploadedFile::fake()->image('meteran.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_START);

        $media = $reading->refresh()->getFirstMedia(MeterReading::PHOTOS_START);

        $this->assertSame('local', $media->disk);
        $this->assertSame(MeterReading::PHOTOS_START, $media->collection_name);
    }

    /**
     * Which photograph backs which figure is the evidentiary point of the whole
     * screen, and it is held by collection_name rather than by upload order —
     * order is destroyed by reordering or by deleting one file, and neither
     * leaves a trace that the pairing has shifted.
     */
    public function test_a_photo_belongs_to_the_end_it_was_uploaded_against(): void
    {
        Storage::fake('local');

        $reading = MeterReading::factory()->create();
        $reading->addMedia(UploadedFile::fake()->image('awal.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_START);
        $reading->addMedia(UploadedFile::fake()->image('akhir.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_END);

        $reading->refresh();

        $this->assertSame('awal.jpg', $reading->getFirstMedia(MeterReading::PHOTOS_START)->file_name);
        $this->assertSame('akhir.jpg', $reading->getFirstMedia(MeterReading::PHOTOS_END)->file_name);
        $this->assertCount(1, $reading->getMedia(MeterReading::PHOTOS_START));
        $this->assertCount(1, $reading->getMedia(MeterReading::PHOTOS_END));

        // Both registered, so neither falls through to media-library's own
        // default disk — which is `public`, and would publish a meter photograph
        // by URL with no role check at all.
        $this->assertSame('local', $reading->getFirstMedia(MeterReading::PHOTOS_END)->disk);
    }

    /**
     * The `local` disk sets serve => true and no visibility key, so Laravel
     * treats it as private and its /storage route refuses any request without a
     * valid signature — before it looks for the file, which is why this works
     * against a faked disk.
     */
    public function test_a_photo_cannot_be_fetched_without_a_signature(): void
    {
        Storage::fake('local');

        $reading = MeterReading::factory()->create();
        $reading->addMedia(UploadedFile::fake()->image('meteran.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_START);

        $path = $reading->refresh()
            ->getFirstMedia(MeterReading::PHOTOS_START)
            ->getPathRelativeToRoot();

        $this->get('/storage/'.$path)->assertForbidden();
    }

    /**
     * Renders the screens that actually resolve a photo URL.
     *
     * The image column and the image entry take a separate code path when the
     * component is marked private — they ask medialibrary for a temporary URL
     * instead of a plain one. Nothing else here would notice a broken one: it
     * still renders, it just renders as a broken image.
     */
    public function test_every_screen_renders_with_a_photo_attached(): void
    {
        Storage::fake('local');

        $room = Room::factory()->create(['name' => 'Kamar C1']);
        $reading = MeterReading::factory()->forRoom($room)->usage(90)->create();
        // Both, so a private-URL flag missing from either end's component is
        // caught here rather than by eye on a broken image.
        $reading->addMedia(UploadedFile::fake()->image('awal.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_START);
        $reading->addMedia(UploadedFile::fake()->image('akhir.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_END);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/meter-readings')->assertOk()->assertSee('Kamar C1');
        $this->actingAs($admin)->get('/meter-readings/create')->assertOk();
        $this->actingAs($admin)->get('/meter-readings/'.$reading->getKey())->assertOk();
        $this->actingAs($admin)->get('/meter-readings/'.$reading->getKey().'/edit')->assertOk();
    }

    /**
     * Photos are a relation, so LogsActivity cannot see them. Removing one is an
     * edit to the evidence behind a kWh figure, which is what the audit trail is
     * for — the same split LogRoleChange makes for roles.
     */
    public function test_deleting_a_photo_is_audited(): void
    {
        Storage::fake('local');

        $reading = MeterReading::factory()->create();
        $reading->addMedia(UploadedFile::fake()->image('meteran.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_START);

        $reading->refresh()->getFirstMedia(MeterReading::PHOTOS_START)->delete();

        $entry = Activity::query()->where('event', 'meter_photo_deleted')->sole();

        $this->assertSame('meter_reading', $entry->log_name);
        $this->assertSame('Foto meteran dihapus', $entry->description);
        $this->assertSame('meteran.jpg', $entry->properties->get('file_name'));
        $this->assertSame($reading->getKey(), $entry->properties->get('meter_reading_id'));
    }

    /**
     * Deleting a reading writes its own entry *and* one per photo. The
     * duplication is wanted: a photo removed on its own and a photo that went
     * down with its row are different events, and the log should not have to
     * infer which happened.
     */
    public function test_deleting_a_reading_audits_the_row_and_each_photo(): void
    {
        Storage::fake('local');

        $reading = MeterReading::factory()->create();
        // One photo per end, so this also covers both collections going down with
        // the row rather than only the one that happens to be checked elsewhere.
        $reading->addMedia(UploadedFile::fake()->image('awal.jpg'))->toMediaCollection(MeterReading::PHOTOS_START);
        $reading->addMedia(UploadedFile::fake()->image('akhir.jpg'))->toMediaCollection(MeterReading::PHOTOS_END);

        $reading->delete();

        $this->assertSame(1, Activity::query()
            ->where('log_name', 'meter_reading')
            ->where('event', 'deleted')
            ->count());

        $this->assertSame(2, Activity::query()
            ->where('log_name', 'meter_reading')
            ->where('event', 'meter_photo_deleted')
            ->count());
    }

    /**
     * Bulk deletion goes through per-record deletes. Filament's single-query
     * path fires no model events, which would take both the activity entries and
     * the photo cleanup down with it — rows gone, images orphaned on disk with
     * nothing left pointing at them.
     */
    public function test_bulk_deleting_readings_is_audited_per_row(): void
    {
        $room = Room::factory()->create();
        $readings = MeterReading::factory()->forRoom($room)->count(2)->create();

        Livewire::actingAs($this->superAdmin())
            ->test(ListMeterReadings::class)
            ->selectTableRecords($readings->pluck('id')->all())
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertSame(0, MeterReading::query()->count());
        $this->assertSame(2, Activity::query()
            ->where('log_name', 'meter_reading')
            ->where('event', 'deleted')
            ->count());
    }

    /**
     * The rate is on the audit allowlist deliberately: it is the one column here
     * whose value is copied from somewhere else, so a row whose rate no longer
     * matches any tariff is only explicable from the log.
     */
    public function test_changing_the_rate_on_a_reading_is_audited(): void
    {
        $reading = MeterReading::factory()->usage(100, rate: 1_500)->create();

        $reading->update(['rate' => 1_650]);

        $entry = Activity::query()
            ->where('log_name', 'meter_reading')
            ->where('event', 'updated')
            ->sole();

        $this->assertSame(1_500, $entry->attribute_changes['old']['rate']);
        $this->assertSame(1_650, $entry->attribute_changes['attributes']['rate']);
    }
}

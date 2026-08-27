<?php

namespace Tests\Feature;

use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Models\MeterReading;
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
        MeterReading::factory()->usage(120)->create();

        $this->actingAs($this->superAdmin())
            ->get('/meter-readings')
            ->assertOk()
            ->assertSee('120 kWh');
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
     * The two halves of a reading, and they are now different kinds of number.
     *
     * Consumption is derived, so it cannot disagree with the figures it comes
     * from. The bill is stored, because it is not computed from anything on this
     * row any more — it is what the tenant was told to pay. Nothing multiplies
     * the one by the other.
     */
    public function test_usage_is_derived_and_the_bill_is_stored_as_typed(): void
    {
        $reading = MeterReading::factory()->usage(137, total: 226_050, start: 4_200)->create();

        $this->assertSame(4_200, $reading->start_kwh);
        $this->assertSame(4_337, $reading->end_kwh);
        $this->assertSame(137, $reading->usage_kwh);
        $this->assertSame(226_050, $reading->total_amount);
    }

    /**
     * What the rate column was there to guarantee, now held by there being no
     * shared figure at all.
     *
     * A bill belongs to the period it was issued for. It used to take a rate
     * copied onto every row to keep that true, because the alternative was a
     * tariff joined at display time that would recompute July at August's price.
     * With the amount itself on the row there is nothing left to recompute from
     * — but the assertion stays, because it is the property that matters rather
     * than the mechanism that happens to provide it.
     */
    public function test_a_later_reading_does_not_change_an_earlier_bill(): void
    {
        $july = MeterReading::factory()->usage(100, total: 150_000)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        $this->assertSame(150_000, $july->total_amount);

        MeterReading::factory()->usage(100, total: 200_000)
            ->create(['end_read_at' => '2026-08-20 09:00:00']);

        $this->assertSame(150_000, $july->fresh()->total_amount);
    }

    /**
     * The bill is the one field that is deliberately *not* carried forward.
     *
     * The rate it replaced was prefilled, and rightly: a price repeats month to
     * month, so a default was one fewer thing to type and a change was a field
     * already on screen waiting to be corrected. An amount does not repeat. A
     * prefill here would put last month's figure into the only field on the form
     * that nothing else can contradict — a wrong bill that looks exactly like a
     * right one.
     */
    public function test_the_bill_is_not_prefilled_from_the_previous_reading(): void
    {
        MeterReading::factory()->usage(100, total: 165_000)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaStateSet(['total_amount' => null], schema: 'form');
    }

    /**
     * What *is* carried forward comes from the latest period, not the last row
     * written. Ordering on end_read_at is what places a period on the timeline,
     * so a correction entered out of order still leaves the newest closing
     * figure as the one the next reading opens at.
     */
    public function test_the_prefill_comes_from_the_latest_period_not_the_last_row_written(): void
    {
        MeterReading::factory()->usage(100, start: 8_000)
            ->create(['end_read_at' => '2026-08-20 09:00:00']);

        // Written second, but covers an earlier period.
        MeterReading::factory()->usage(100, start: 3_000)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaStateSet(['start_kwh' => 8_100], schema: 'form');
    }

    /**
     * The amount is asked for on every reading, and the column is NOT NULL, so
     * the form has to be what refuses an empty one.
     */
    public function test_the_bill_field_is_on_screen_and_required(): void
    {
        Carbon::setTestNow('2026-08-14 15:00:00');

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaComponentVisible('total_amount', 'form')
            ->fillForm([
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'total_amount' => null,
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['total_amount']);

        $this->assertSame(0, MeterReading::query()->count());
    }

    /**
     * The grouped field's round trip: typed with separators, stored bare.
     *
     * Losing RupiahInput's ->dehydrateStateUsing() would write "165.000" into an
     * INTEGER column, which SQLite casts to 165 with no error at all — and with
     * nothing deriving this figure any more, a bill three orders of magnitude
     * out is a number nothing on the row disagrees with.
     */
    public function test_a_grouped_bill_is_stored_as_a_whole_integer(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'total_amount' => '165.000',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reading = MeterReading::query()->sole();

        $this->assertSame(165_000, $reading->total_amount);
        $this->assertSame(100, $reading->usage_kwh);
    }

    /**
     * The ceiling, which is the guard that replaced the arithmetic.
     *
     * A rate had a plausible range and a bill computed from it inherited one. A
     * typed amount does not: one extra zero produces a figure the form accepts,
     * the column stores and nothing else on the row contradicts. WholeRupiah's
     * max is what refuses it.
     */
    public function test_a_bill_far_beyond_a_plausible_one_is_refused(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'total_amount' => '150.000.000',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['total_amount']);

        $this->assertSame(0, MeterReading::query()->count());
    }

    /**
     * A recorded amount has to survive an edit to anything else.
     *
     * This mattered more when the figure was derived: a form that re-read "the
     * current price" on save would have repriced an issued bill while looking
     * like an ordinary note change. Now it is stored, and this is what catches a
     * default or a mutator quietly writing over it.
     */
    public function test_editing_a_reading_leaves_the_recorded_bill_alone(): void
    {
        $july = MeterReading::factory()->usage(100, total: 150_000)
            ->create(['end_read_at' => '2026-07-20 09:00:00']);

        MeterReading::factory()->usage(100, total: 200_000)
            ->create(['end_read_at' => '2026-08-20 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $july->getKey()])
            ->fillForm(['note' => 'Angka sulit dibaca'])
            ->call('save')
            ->assertHasNoFormErrors();

        $july->refresh();

        $this->assertSame(150_000, $july->total_amount);
        $this->assertSame('Angka sulit dibaca', $july->note);
    }

    /**
     * An amount typed wrong is corrected on the field itself — the ordinary
     * save, with its model events, validation and audit entry.
     */
    public function test_a_bill_typed_wrong_is_corrected_on_the_form(): void
    {
        $reading = MeterReading::factory()->usage(100, total: 120_000)->create();

        Livewire::actingAs($this->superAdmin())
            ->test(EditMeterReading::class, ['record' => $reading->getKey()])
            ->assertSchemaStateSet(['total_amount' => '120.000'], schema: 'form')
            ->fillForm(['total_amount' => '150.000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(150_000, $reading->fresh()->total_amount);
    }

    /**
     * The opening figure is the previous reading's closing figure. That is what
     * makes the two numbers one continuous meter rather than two unrelated
     * fields nobody can check.
     */
    public function test_the_opening_figure_is_prefilled_from_the_previous_reading(): void
    {
        MeterReading::factory()->usage(200, start: 3_000)
            ->create(['end_read_at' => '2026-07-01 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaStateSet(['start_kwh' => 3_200], schema: 'form');
    }

    /**
     * And its moment, for the same reason: a period opens where the last one
     * closed. Prefilled from the previous end_read_at rather than from now(),
     * which would leave a gap nobody entered and nobody can account for.
     */
    public function test_the_opening_moment_is_prefilled_from_the_previous_reading(): void
    {
        MeterReading::factory()->usage(200, start: 3_000)
            ->create(['end_read_at' => '2026-07-01 09:00:00']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            // Without seconds — the shape the ->seconds(false) picker carries.
            ->assertSchemaStateSet(['start_read_at' => '2026-07-01 09:00'], schema: 'form');
    }

    /**
     * A meter nobody has recorded yet keeps the fallbacks rather than opening
     * blank. A required field left empty on a form that just filled two others
     * in reads as the form breaking, not as an empty history.
     */
    public function test_a_meter_with_no_history_opens_at_zero_and_at_now(): void
    {
        Carbon::setTestNow('2026-08-14 15:12:00');

        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->assertSchemaStateSet([
                'start_kwh' => 0,
                'start_read_at' => '2026-08-14 15:12',
                'end_read_at' => '2026-08-14 15:12',
            ], schema: 'form');
    }

    /**
     * A closing figure below the opening one is either a typo or a replaced
     * meter, and the two need different handling. Refusing it means the second
     * has to be entered deliberately rather than silently producing a negative
     * bill that still looks like a number.
     */
    public function test_a_closing_figure_below_the_opening_one_is_refused(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 5_000,
                'end_kwh' => 4_900,
                'total_amount' => '150.000',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['end_kwh']);

        $this->assertSame(0, MeterReading::query()->count());
    }

    /**
     * A meter that did not move really can cost nothing, so Rp 0 is a real
     * answer rather than an empty field — which is why the amount field is
     * ->allowingZero() and ->required() is what catches a blank one.
     */
    public function test_an_unchanged_meter_may_record_a_zero_bill(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 5_000,
                'end_kwh' => 5_000,
                'total_amount' => '0',
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

        Livewire::actingAs($admin)
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'total_amount' => '150.000',
                'start_read_at' => '2026-07-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($admin->getKey(), MeterReading::query()->sole()->user_id);
    }

    /**
     * A period that closes before it opens is a typo, and an expensive one: it is
     * end_read_at that dates the row, so such a reading would sort into the wrong
     * place forever and previous() would offer it as the predecessor of readings
     * taken before it.
     */
    public function test_a_closing_moment_before_the_opening_one_is_refused(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 1_000,
                'end_kwh' => 1_100,
                'total_amount' => '150.000',
                'start_read_at' => '2026-08-14 09:00',
                'end_read_at' => '2026-07-14 09:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['end_read_at']);

        $this->assertSame(0, MeterReading::query()->count());
    }

    /**
     * Both figures read in one visit is a real case — a meter replaced, a room
     * taken over mid-month — so the two moments being equal is accepted where
     * the reverse is not.
     */
    public function test_both_figures_may_be_read_at_the_same_moment(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateMeterReading::class)
            ->fillForm([
                'start_kwh' => 0,
                'end_kwh' => 0,
                'total_amount' => '150.000',
                'start_read_at' => '2026-08-14 09:00',
                'end_read_at' => '2026-08-14 09:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, MeterReading::query()->count());
    }

    /**
     * Nothing has to be set up before the first reading any more.
     *
     * The button used to be hidden until a room existed, because room_id was
     * required and had no free-text fallback. With the room gone there is no
     * such precondition left, and a create button that hides itself for a reason
     * nobody can see is indistinguishable from a missing permission.
     */
    public function test_the_create_button_is_available_on_an_empty_log(): void
    {
        Livewire::actingAs($this->superAdmin())
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

        $reading = MeterReading::factory()->usage(90, total: 135_000)->create();
        // Both, so a private-URL flag missing from either end's component is
        // caught here rather than by eye on a broken image.
        $reading->addMedia(UploadedFile::fake()->image('awal.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_START);
        $reading->addMedia(UploadedFile::fake()->image('akhir.jpg'))
            ->toMediaCollection(MeterReading::PHOTOS_END);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/meter-readings')->assertOk()->assertSee('90 kWh');
        $this->actingAs($admin)->get('/meter-readings/create')->assertOk();
        // One figure on the view screen, not two: the rate that used to be
        // printed beside the total was the other half of a multiplication that
        // no longer happens.
        $this->actingAs($admin)->get('/meter-readings/'.$reading->getKey())
            ->assertOk()
            ->assertDontSee('/kWh</')
            ->assertSee('Rp 135.000');
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
        $readings = MeterReading::factory()->count(2)->create();

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
     * The amount is on the audit allowlist deliberately: it is the money, and
     * with nothing deriving it there is no second figure on the row that a quiet
     * correction would contradict.
     */
    public function test_changing_the_bill_on_a_reading_is_audited(): void
    {
        $reading = MeterReading::factory()->usage(100, total: 150_000)->create();

        $reading->update(['total_amount' => 165_000]);

        $entry = Activity::query()
            ->where('log_name', 'meter_reading')
            ->where('event', 'updated')
            ->sole();

        $this->assertSame(150_000, $entry->attribute_changes['old']['total_amount']);
        $this->assertSame(165_000, $entry->attribute_changes['attributes']['total_amount']);
    }

    /**
     * The other half of an allowlist, and the half that is usually missing.
     *
     * logOnly() is asserted everywhere by what it *does* record; nothing asserts
     * what it refuses. So widening the list — or a refactor that sweeps a new
     * column into it — fails nothing. `user_id` is the column to test against:
     * it is fillable, it is written on every row, and it is deliberately absent
     * from the allowlist because who recorded a reading is already the causer.
     */
    public function test_nothing_outside_the_allowlist_is_logged(): void
    {
        $reading = MeterReading::factory()->usage(100, total: 150_000)->create();
        $other = $this->superAdmin();

        $reading->update(['user_id' => $other->getKey(), 'total_amount' => 165_000]);

        $entry = Activity::query()
            ->where('log_name', 'meter_reading')
            ->where('event', 'updated')
            ->sole();

        $this->assertSame(['total_amount'], array_keys($entry->attribute_changes['attributes']));
        $this->assertArrayNotHasKey('user_id', $entry->attribute_changes['attributes']);
    }
}

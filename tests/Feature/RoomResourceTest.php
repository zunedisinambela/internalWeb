<?php

namespace Tests\Feature;

use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RoomResource;
use App\Models\MeterReading;
use App\Models\Room;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoomResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/rooms')->assertRedirect('/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/rooms')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        Room::factory()->create(['name' => 'Kamar A3', 'occupant' => 'Budi Santoso']);

        $this->actingAs($this->superAdmin())
            ->get('/rooms')
            ->assertOk()
            ->assertSee('Kamar A3')
            ->assertSee('Budi Santoso');
    }

    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-kamar', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Room'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-kamar@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/rooms')->assertOk();
        $this->actingAs($user)->get('/rooms/create')->assertForbidden();
    }

    /**
     * A reading without a room means nothing, so a room with readings against it
     * cannot be removed. This is the resource-level half of that rule — the one
     * that turns the refusal into a missing button rather than a stack trace.
     *
     * It lives on the resource and not on the action because Filament consults
     * the resource for the row action *and* for every record inside a bulk
     * delete; a check on ->visible() alone would leave the bulk path throwing.
     */
    public function test_a_room_with_readings_cannot_be_deleted(): void
    {
        // canDelete() defers to the Shield policy first, so this has to run as
        // somebody the policy allows — otherwise both answers are false and the
        // test passes for the wrong reason.
        $this->actingAs($this->superAdmin());

        $occupied = Room::factory()->create(['name' => 'Kamar terisi']);
        $empty = Room::factory()->create(['name' => 'Kamar kosong']);

        MeterReading::factory()->forRoom($occupied)->create();

        $this->assertFalse(RoomResource::canDelete($occupied));
        $this->assertTrue(RoomResource::canDelete($empty));
    }

    /**
     * The other half, and the one that covers tinker, a console command and a
     * bulk query — none of which ask the resource anything. meter_readings.room_id
     * is restrictOnDelete, so the database is what refuses.
     */
    public function test_the_database_refuses_to_delete_a_room_that_has_readings(): void
    {
        $room = Room::factory()->create();
        MeterReading::factory()->forRoom($room)->create();

        $this->expectException(QueryException::class);

        $room->delete();
    }

    /**
     * A room that stops being rented is deactivated, which is the only exit the
     * schema leaves. Its readings stay readable.
     */
    public function test_an_inactive_room_keeps_its_readings(): void
    {
        $room = Room::factory()->create();
        MeterReading::factory()->forRoom($room)->count(2)->create();

        $room->update(['is_active' => false]);

        $this->assertFalse($room->fresh()->is_active);
        $this->assertSame(2, $room->meterReadings()->count());
    }

    /**
     * The figure the next reading opens with. `id` is the tiebreak, so two
     * readings sharing a timestamp still resolve to one answer rather than to
     * whatever the engine returns first.
     */
    public function test_the_latest_reading_is_the_newest_by_time_then_by_id(): void
    {
        $room = Room::factory()->create();

        MeterReading::factory()->forRoom($room)->usage(100, start: 1_000)
            ->create(['end_read_at' => '2026-07-01 09:00:00']);

        $newer = MeterReading::factory()->forRoom($room)->usage(50, start: 1_100)
            ->create(['end_read_at' => '2026-08-01 09:00:00']);

        $sameMoment = MeterReading::factory()->forRoom($room)->usage(20, start: 1_150)
            ->create(['end_read_at' => '2026-08-01 09:00:00']);

        $this->assertSame($sameMoment->getKey(), $room->latestReading()->getKey());
        $this->assertNotSame($newer->getKey(), $room->latestReading()->getKey());
    }

    /**
     * `before` is what keeps an edit from offering a later reading as the one it
     * continues from.
     */
    public function test_the_latest_reading_can_be_limited_to_a_moment(): void
    {
        $room = Room::factory()->create();

        $july = MeterReading::factory()->forRoom($room)->usage(100)
            ->create(['end_read_at' => '2026-07-01 09:00:00']);

        MeterReading::factory()->forRoom($room)->usage(100, start: 1_100)
            ->create(['end_read_at' => '2026-08-01 09:00:00']);

        $this->assertSame(
            $july->getKey(),
            $room->latestReading(new \DateTimeImmutable('2026-07-15 00:00:00'))->getKey(),
        );
    }

    public function test_a_room_without_readings_has_no_latest_reading(): void
    {
        $this->assertNull(Room::factory()->create()->latestReading());
    }

    /**
     * Who lives in a room when a reading is taken is exactly what a disputed
     * bill turns on, so `occupant` is on the allowlist deliberately.
     */
    public function test_changing_the_occupant_is_audited(): void
    {
        $room = Room::factory()->create(['occupant' => 'Budi']);

        $room->update(['occupant' => 'Siti']);

        $entry = Activity::query()
            ->where('log_name', 'room')
            ->where('event', 'updated')
            ->sole();

        $this->assertSame('Budi', $entry->attribute_changes['old']['occupant']);
        $this->assertSame('Siti', $entry->attribute_changes['attributes']['occupant']);
    }

    /**
     * Bulk deletion goes through per-record deletes, so the resource check and
     * the activity entries both still apply. Filament's single-query path fires
     * no model events and asks the resource nothing.
     */
    public function test_bulk_deleting_rooms_is_audited_per_row(): void
    {
        $rooms = Room::factory()->count(2)->create();

        Livewire::actingAs($this->superAdmin())
            ->test(ListRooms::class)
            ->selectTableRecords($rooms->pluck('id')->all())
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertSame(0, Room::query()->count());
        $this->assertSame(2, Activity::query()
            ->where('log_name', 'room')
            ->where('event', 'deleted')
            ->count());
    }
}

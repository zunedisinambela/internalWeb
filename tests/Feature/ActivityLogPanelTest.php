<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return $this->superAdmin();
    }

    public function test_list_page_renders_for_an_authenticated_user(): void
    {
        $user = $this->admin();

        activity()->causedBy($user)->log('plain entry without a subject');

        $this->actingAs($user)
            ->get(ActivityResource::getUrl('index'))
            ->assertOk()
            ->assertSee('plain entry without a subject');
    }

    public function test_view_page_renders_changes_and_properties(): void
    {
        $user = $this->admin();

        $activity = Activity::create([
            'log_name' => 'default',
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
            'causer_type' => User::class,
            'causer_id' => $user->getKey(),
            'attribute_changes' => [
                'old' => ['name' => 'Old Name', 'meta' => ['nested' => true]],
                'attributes' => ['name' => 'New Name', 'meta' => ['nested' => false]],
            ],
            'properties' => ['ip' => '127.0.0.1'],
        ]);

        $this->actingAs($user)
            ->get(ActivityResource::getUrl('view', ['record' => $activity]))
            ->assertOk()
            ->assertSee('Old Name')
            ->assertSee('New Name')
            ->assertSee('127.0.0.1');
    }

    /**
     * Entries are written by the application, never typed in. An editable audit
     * entry is worse than a deleted one, because it still reads as true.
     */
    public function test_entries_cannot_be_created_or_edited(): void
    {
        $this->assertFalse(ActivityResource::canCreate());
        $this->assertSame(
            ['index', 'view'],
            array_keys(ActivityResource::getPages()),
        );
    }

    public function test_deleting_entries_follows_the_shield_policy(): void
    {
        $this->actingAs($this->admin());
        $this->assertTrue(ActivityResource::canDeleteAny());

        $this->actingAs($this->userWithRole(null));
        $this->assertFalse(ActivityResource::canDeleteAny());
    }

    /**
     * This table is where deletions on the other monitoring screens are
     * recorded, so deleting from it has to land somewhere the same button
     * cannot reach. Logging back into the activity log would be circular; the
     * file log is not writable from the panel, so that is the end of the chain.
     */
    public function test_deleting_an_entry_is_written_to_the_application_log(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $activity = activity('user')->event('role_granted')->log('Roles granted: super_admin');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($activity, $admin): bool {
                return $message === 'Activity log entry deleted'
                    && $context['activity_id'] === $activity->getKey()
                    && $context['event'] === 'role_granted'
                    && $context['deleted_by'] === $admin->email;
            });

        $activity->delete();
    }

    public function test_an_entry_can_be_deleted_from_the_table(): void
    {
        $this->actingAs($this->admin());

        $activity = activity()->log('deletable entry');

        Livewire::test(ListActivities::class)
            ->callAction(TestAction::make('delete')->table($activity))
            ->assertHasNoActionErrors();

        $this->assertModelMissing($activity);
    }

    /**
     * The bulk path is the one that can quietly stop being logged: with
     * fetchSelectedRecords off Filament deletes through a single query, which
     * fires no model events.
     */
    public function test_bulk_deleting_entries_stays_logged(): void
    {
        $this->actingAs($this->admin());

        activity()->log('first');
        activity()->log('second');

        $ids = Activity::query()->pluck('id');

        Log::shouldReceive('warning')
            ->times($ids->count())
            ->withSomeOfArgs('Activity log entry deleted');

        Livewire::test(ListActivities::class)
            ->selectTableRecords($ids->all())
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoActionErrors();

        $this->assertSame(0, Activity::query()->count());
    }
}

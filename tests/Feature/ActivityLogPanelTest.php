<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\ActivityResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_log_is_read_only(): void
    {
        $this->assertFalse(ActivityResource::canCreate());
        $this->assertFalse(ActivityResource::canDeleteAny());
        $this->assertSame(
            ['index', 'view'],
            array_keys(ActivityResource::getPages()),
        );
    }
}

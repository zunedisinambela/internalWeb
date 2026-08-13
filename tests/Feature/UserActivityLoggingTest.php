<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UserActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function user(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff@admin.com',
            'password' => 'secret',
        ]);
    }

    public function test_creating_a_user_is_logged_under_the_user_log(): void
    {
        $user = $this->user();

        $activity = Activity::latest('id')->first();

        $this->assertSame('user', $activity->log_name);
        $this->assertSame('created', $activity->event);
        $this->assertSame(User::class, $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    public function test_privilege_escalation_is_recorded(): void
    {
        $user = $this->user();

        $user->grantAdmin();

        $activity = Activity::latest('id')->first();

        $this->assertSame('updated', $activity->event);
        $this->assertSame(false, $activity->attribute_changes->get('old')['is_admin']);
        $this->assertSame(true, $activity->attribute_changes->get('attributes')['is_admin']);
    }

    public function test_secrets_are_never_written_to_the_log(): void
    {
        $user = $this->user();

        $user->update(['password' => 'a-different-secret']);

        $logged = Activity::query()->pluck('attribute_changes')->toJson();

        $this->assertStringNotContainsString('password', $logged);
        $this->assertStringNotContainsString('remember_token', $logged);
    }

    public function test_a_save_that_changes_nothing_is_not_logged(): void
    {
        $user = $this->user();

        $countAfterCreate = Activity::count();

        $user->update(['name' => 'Staff']);

        $this->assertSame($countAfterCreate, Activity::count());
    }

    public function test_the_causer_is_the_authenticated_user(): void
    {
        $actor = User::create([
            'name' => 'Actor',
            'email' => 'actor@admin.com',
            'password' => 'secret',
        ]);

        $this->actingAs($actor);

        $subject = $this->user();

        $activity = Activity::latest('id')->first();

        $this->assertSame($actor->getKey(), $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);
        $this->assertSame($subject->getKey(), $activity->subject_id);
    }
}

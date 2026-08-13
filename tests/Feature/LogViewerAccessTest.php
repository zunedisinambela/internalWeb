<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_the_log_viewer(): void
    {
        $this->get('/log-viewer')->assertForbidden();
    }

    public function test_guests_cannot_reach_the_log_viewer_api(): void
    {
        $this->getJson('/log-viewer/api/files')->assertForbidden();
    }

    public function test_authenticated_users_can_open_the_log_viewer(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@admin.com',
            'password' => 'admin',
        ]);

        $this->actingAs($user)
            ->get('/log-viewer')
            ->assertOk();
    }
}

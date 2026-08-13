<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function user(bool $isAdmin): User
    {
        $user = User::create([
            'name' => $isAdmin ? 'Admin' : 'Staff',
            'email' => $isAdmin ? 'admin@admin.com' : 'staff@admin.com',
            'password' => 'secret',
        ]);

        if ($isAdmin) {
            $user->grantAdmin();
        }

        return $user;
    }

    public function test_guests_cannot_open_the_log_viewer(): void
    {
        $this->get('/log-viewer')->assertForbidden();
    }

    public function test_guests_cannot_reach_the_log_viewer_api(): void
    {
        $this->getJson('/log-viewer/api/files')->assertForbidden();
    }

    public function test_non_admins_cannot_open_the_log_viewer(): void
    {
        $this->actingAs($this->user(false))
            ->get('/log-viewer')
            ->assertForbidden();
    }

    public function test_non_admins_cannot_reach_the_log_viewer_api(): void
    {
        $this->actingAs($this->user(false))
            ->getJson('/log-viewer/api/files')
            ->assertForbidden();
    }

    public function test_admins_can_open_the_log_viewer(): void
    {
        $this->actingAs($this->user(true))
            ->get('/log-viewer')
            ->assertOk();
    }

    /**
     * The two gates must stay in step: anyone refused by the admin panel has to
     * be refused by the log viewer as well.
     */
    public function test_log_viewer_access_matches_panel_access(): void
    {
        foreach ([true, false] as $isAdmin) {
            $user = $this->user($isAdmin);

            $this->assertSame(
                $isAdmin,
                $this->actingAs($user)->get('/log-viewer')->isOk(),
            );

            $this->assertSame(
                $isAdmin,
                $this->actingAs($user)->get('/admin')->isOk(),
            );

            $user->delete();
        }
    }
}

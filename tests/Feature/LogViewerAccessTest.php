<?php

namespace Tests\Feature;

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

    public function test_users_without_a_role_cannot_open_the_log_viewer(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/log-viewer')
            ->assertForbidden();
    }

    public function test_users_without_a_role_cannot_reach_the_log_viewer_api(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->getJson('/log-viewer/api/files')
            ->assertForbidden();
    }

    public function test_super_admins_can_open_the_log_viewer(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/log-viewer')
            ->assertOk();
    }

    /**
     * The two gates must stay in step: anyone refused by the admin panel has to
     * be refused by the log viewer as well.
     */
    public function test_log_viewer_access_matches_panel_access(): void
    {
        foreach ([true, false] as $hasRole) {
            $user = $hasRole ? $this->superAdmin() : $this->userWithRole(null);

            $this->assertSame(
                $hasRole,
                $this->actingAs($user)->get('/log-viewer')->isOk(),
            );

            $this->assertSame(
                $hasRole,
                $this->actingAs($user)->get('/')->isOk(),
            );

            $user->delete();
        }
    }
}

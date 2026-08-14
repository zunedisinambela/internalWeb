<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_a_role_cannot_access_the_panel(): void
    {
        $user = $this->userWithRole(null);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        $this->actingAs($user)->get('/')->assertForbidden();
    }

    public function test_super_admins_can_open_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertOk();
    }

    public function test_super_admins_can_open_the_activity_log(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/activities')
            ->assertOk();
    }

    public function test_users_without_a_role_are_forbidden_from_the_activity_log(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/activities')
            ->assertForbidden();
    }

    public function test_removing_the_last_role_locks_the_user_out(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user)->get('/')->assertOk();

        $user->syncRoles([]);

        $this->actingAs($user->fresh())->get('/')->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(Filament::getLoginUrl());
    }
}

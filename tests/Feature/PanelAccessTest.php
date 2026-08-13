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

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_super_admins_can_open_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin')
            ->assertOk();
    }

    public function test_super_admins_can_open_the_activity_log(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/activities')
            ->assertOk();
    }

    public function test_users_without_a_role_are_forbidden_from_the_activity_log(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/admin/activities')
            ->assertForbidden();
    }

    public function test_removing_the_last_role_locks_the_user_out(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->syncRoles([]);

        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(Filament::getLoginUrl());
    }
}

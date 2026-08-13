<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
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

    public function test_is_admin_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@admin.com',
            'password' => 'secret',
            'is_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->is_admin);

        $user->update(['is_admin' => true]);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_revoking_admin_locks_the_user_out(): void
    {
        $user = $this->user(true);

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->revokeAdmin();

        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    }

    public function test_is_admin_defaults_to_false(): void
    {
        $user = User::create([
            'name' => 'Nobody',
            'email' => 'nobody@admin.com',
            'password' => 'secret',
        ]);

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_admins_can_open_the_dashboard(): void
    {
        $this->actingAs($this->user(true))
            ->get('/admin')
            ->assertOk();
    }

    public function test_non_admins_are_forbidden_from_the_dashboard(): void
    {
        $this->actingAs($this->user(false))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_non_admins_are_forbidden_from_the_activity_log(): void
    {
        $this->actingAs($this->user(false))
            ->get('/admin/activities')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(Filament::getLoginUrl());
    }
}

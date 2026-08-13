<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admins_can_open_the_user_list(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/users')
            ->assertOk();
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_creating_a_user_hashes_the_password_and_assigns_roles(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'email' => 'new@admin.com',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
                'roles' => [1],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::firstWhere('email', 'new@admin.com');

        $this->assertNotNull($user);
        $this->assertNotSame('a-strong-password', $user->password);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
        $this->assertTrue($user->roles()->exists());
        $this->assertTrue(Auth::attempt(['email' => 'new@admin.com', 'password' => 'a-strong-password']));
    }

    public function test_a_short_password_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'email' => 'short@admin.com',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertNull(User::firstWhere('email', 'short@admin.com'));
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'email' => 'mismatch@admin.com',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-different-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertNull(User::firstWhere('email', 'mismatch@admin.com'));
    }

    public function test_editing_without_a_password_keeps_the_existing_one(): void
    {
        $this->actingAs($this->superAdmin());

        $user = $this->userWithRole(null);
        $originalHash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Renamed',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Renamed', $user->name);
        $this->assertSame($originalHash, $user->password);
    }

    public function test_editing_with_a_password_replaces_it(): void
    {
        $this->actingAs($this->superAdmin());

        $user = $this->userWithRole(null);
        $originalHash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertNotSame($originalHash, $user->password);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
    }

    public function test_a_user_cannot_delete_themselves(): void
    {
        $admin = $this->superAdmin();
        $other = $this->userWithRole(null);

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canDelete($admin));
        $this->assertTrue(UserResource::canDelete($other));
    }

    public function test_the_confirmation_field_is_never_stored(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'email' => 'stored@admin.com',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::firstWhere('email', 'stored@admin.com');

        $this->assertArrayNotHasKey('password_confirmation', $user->getAttributes());
    }
}

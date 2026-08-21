<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The sign-in screen accepts either identifier.
 *
 * What is worth pinning is not that a correct password works — it is that the
 * two identifiers reach the same account, that neither becomes a way around
 * the panel gate, and that a refusal still says so on a field that exists.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The label is the only thing telling a user the second identifier exists,
     * and ->email() being dropped from the field is what lets them type it.
     */
    public function test_the_sign_in_field_names_both_identifiers(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Email atau nama pengguna');
    }

    public function test_a_user_signs_in_with_their_email_address(): void
    {
        $user = $this->superAdmin(['password' => 'admin-password']);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => $user->email,
                'password' => 'admin-password',
            ])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_signs_in_with_their_username(): void
    {
        $user = $this->superAdmin(['password' => 'admin-password', 'username' => 'bendahara']);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => 'bendahara',
                'password' => 'admin-password',
            ])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Usernames are stored lowercase, and SQLite compares TEXT case
     * sensitively — so without the fold in getCredentialsFromFormData() a
     * capital first letter would be an unknown account.
     */
    public function test_the_username_is_matched_regardless_of_case(): void
    {
        $user = $this->superAdmin(['password' => 'admin-password', 'username' => 'bendahara']);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => '  Bendahara ',
                'password' => 'admin-password',
            ])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The base page attaches its failure to `data.email`, a field this form no
     * longer has. Livewire raises nothing for a message on an unknown key, so
     * without the override the screen would simply reload in silence.
     */
    public function test_a_wrong_password_is_refused_on_the_field_the_form_actually_has(): void
    {
        $user = $this->superAdmin(['password' => 'admin-password']);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => $user->username,
                'password' => 'not-the-password',
            ])
            ->call('authenticate')
            ->assertHasErrors('data.login');

        $this->assertGuest();
    }

    public function test_an_unknown_identifier_is_refused(): void
    {
        $this->superAdmin(['password' => 'admin-password']);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => 'tidak-ada',
                'password' => 'admin-password',
            ])
            ->call('authenticate')
            ->assertHasErrors('data.login');

        $this->assertGuest();
    }

    /**
     * Holding a role is what grants panel access. A second identifier must not
     * become a second door: the username path runs through the same
     * canAccessPanel() check the email path does.
     */
    public function test_a_roleless_user_is_refused_by_either_identifier(): void
    {
        $user = $this->userWithRole(null, ['password' => 'admin-password']);

        foreach ([$user->email, $user->username] as $identifier) {
            Livewire::test(Login::class)
                ->fillForm([
                    'login' => $identifier,
                    'password' => 'admin-password',
                ])
                ->call('authenticate')
                ->assertHasErrors('data.login');

            $this->assertGuest();
        }
    }

    /**
     * The whole rule rests on this: an '@' means the input is an email
     * address. That is only unambiguous while no username can contain one.
     */
    public function test_a_username_containing_an_at_sign_is_refused_by_the_form(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'username' => 'staff@admin.com',
                'email' => 'staff@admin.com',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['username']);

        $this->assertNull(User::firstWhere('email', 'staff@admin.com'));
    }

    public function test_a_username_is_stored_lowercase(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'username' => 'Bendahara_Kost',
                'email' => 'bendahara@admin.com',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('bendahara_kost', User::firstWhere('email', 'bendahara@admin.com')->username);
    }

    /**
     * The username is on the LogsActivity allowlist: it is an identifier
     * somebody signs in with, so a change to it belongs in the trail beside a
     * changed address.
     */
    public function test_a_username_change_is_audited(): void
    {
        $user = $this->superAdmin(['username' => 'lama']);

        $user->update(['username' => 'baru']);

        $activity = Activity::latest('id')->first();

        $this->assertSame('user', $activity->log_name);
        $this->assertSame(
            ['attributes' => ['username' => 'baru'], 'old' => ['username' => 'lama']],
            $activity->attribute_changes->toArray(),
        );
    }
}

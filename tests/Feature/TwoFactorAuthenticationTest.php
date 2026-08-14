<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function enable(User $user): User
    {
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['hashed-code-a', 'hashed-code-b']);

        return $user->refresh();
    }

    public function test_two_factor_is_opt_in_and_off_by_default(): void
    {
        $user = $this->superAdmin();

        $this->assertFalse($user->hasTwoFactorEnabled());

        // isRequired() false is what makes it the user's own choice. With it on,
        // every user without a secret is redirected to set one up before they
        // can reach any page in the panel.
        $this->assertFalse(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());
        $this->assertNotEmpty(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders());
    }

    public function test_recovery_codes_are_offered(): void
    {
        // Without these, a lost phone locks the account out for good — and for
        // the last super admin there is nobody left who can clear it.
        $provider = collect(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders())
            ->firstWhere(fn ($provider): bool => $provider instanceof AppAuthentication);

        $this->assertNotNull($provider);
        $this->assertTrue($provider->isRecoverable());
    }

    public function test_the_secret_is_encrypted_at_rest_and_never_serialised(): void
    {
        $user = $this->enable($this->superAdmin());

        $stored = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $stored->app_authentication_secret);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $stored->app_authentication_secret);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $stored->app_authentication_recovery_codes ?? '');

        // A secret that survives into a JSON response is a secret that can be
        // read out of a Livewire payload or an API listing.
        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->toArray());
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $user->toJson());

        // Still readable by the app itself — TOTP needs the plaintext to derive
        // the current code, which is why this is encrypted and not hashed.
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->getAppAuthenticationSecret());
    }

    public function test_the_secret_never_reaches_the_activity_log(): void
    {
        $user = $this->enable($this->superAdmin());

        $entries = Activity::query()->get();

        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $serialised = json_encode([$entry->properties, $entry->attribute_changes, $entry->description]);

            $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $serialised);
            $this->assertStringNotContainsString('app_authentication_secret', $serialised);
        }
    }

    public function test_enabling_and_disabling_are_audited_separately(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $this->enable($user);

        $enabled = Activity::query()->latest('id')->first();
        $this->assertSame('two_factor_enabled', $enabled->event);
        $this->assertSame($user->id, $enabled->causer_id);

        $user->resetTwoFactor();

        $disabled = Activity::query()->latest('id')->first();
        $this->assertSame('two_factor_disabled', $disabled->event);
    }

    public function test_clearing_someone_elses_two_factor_is_a_distinct_event(): void
    {
        $admin = $this->superAdmin();
        $target = $this->superAdmin(['email' => 'target@admin.com', 'name' => 'Target']);

        $this->enable($target);
        $this->actingAs($admin);

        $target->resetTwoFactor();

        $entry = Activity::query()->latest('id')->first();

        // Not `two_factor_disabled`: clearing your own requires a valid code,
        // clearing someone else's does not. Reading the log back, the two must
        // not be mistakable for each other.
        $this->assertSame('two_factor_reset', $entry->event);
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame($target->id, $entry->subject_id);
    }

    public function test_an_admin_can_reset_two_factor_for_a_user_who_lost_their_device(): void
    {
        $admin = $this->superAdmin(['password' => 'admin-password']);
        $target = $this->enable($this->superAdmin(['email' => 'target@admin.com', 'name' => 'Target']));

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callAction(
                TestAction::make('resetTwoFactor')->table($target),
                ['password' => 'admin-password'],
            );

        $target->refresh();

        $this->assertFalse($target->hasTwoFactorEnabled());
        // The old codes go with the secret, or the next person to enable two
        // factor here inherits codes the previous holder may still hold on paper.
        $this->assertNull($target->getAppAuthenticationRecoveryCodes());
    }

    public function test_the_reset_requires_the_admins_own_password(): void
    {
        $admin = $this->superAdmin(['password' => 'admin-password']);
        $target = $this->enable($this->superAdmin(['email' => 'target@admin.com', 'name' => 'Target']));

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callAction(
                TestAction::make('resetTwoFactor')->table($target),
                ['password' => 'not-the-password'],
            )
            ->assertHasActionErrors(['password']);

        $this->assertTrue($target->refresh()->hasTwoFactorEnabled());
    }

    public function test_the_reset_is_hidden_for_your_own_account(): void
    {
        $admin = $this->enable($this->superAdmin());

        $this->actingAs($admin);

        // The button skips the code check the profile page enforces, so on your
        // own account it would be a way to strip two factor off a session left
        // unattended. Owners turn theirs off at /profile instead.
        $this->assertFalse(UserResource::canResetTwoFactor($admin));

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('resetTwoFactor')->table($admin));
    }

    public function test_only_super_admins_may_clear_someone_elses_two_factor(): void
    {
        $target = $this->enable($this->superAdmin(['email' => 'target@admin.com', 'name' => 'Target']));

        // A role that can edit users in every other respect — including setting
        // their password. Stripping the second factor is the step that turns
        // that into a full account takeover, so it does not travel with
        // Update:User.
        $staff = Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $staff->givePermissionTo(Permission::whereIn('name', ['ViewAny:User', 'View:User', 'Update:User'])->get());

        $editor = $this->userWithRole('staff', ['email' => 'staff@admin.com', 'name' => 'Staff']);

        $this->actingAs($editor);

        $this->assertTrue(UserResource::canEdit($target));
        $this->assertFalse(UserResource::canResetTwoFactor($target));

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('resetTwoFactor')->table($target));

        $this->assertTrue($target->refresh()->hasTwoFactorEnabled());
    }

    public function test_the_reset_is_hidden_when_the_user_has_no_two_factor(): void
    {
        $admin = $this->superAdmin();
        $target = $this->superAdmin(['email' => 'target@admin.com', 'name' => 'Target']);

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canResetTwoFactor($target));

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('resetTwoFactor')->table($target));
    }

    public function test_users_without_a_role_cannot_reach_the_profile_page(): void
    {
        // The profile page is where two factor is managed. It sits behind the
        // same gate as the rest of the panel, so losing your last role takes it
        // with everything else.
        $this->actingAs($this->userWithRole(null))
            ->get('/profile')
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->get('/profile')
            ->assertOk();
    }

    public function test_a_correct_password_alone_does_not_sign_in_a_user_with_two_factor(): void
    {
        $user = $this->enable($this->superAdmin(['password' => 'admin-password']));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'admin-password',
            ])
            ->call('authenticate');

        // This is the whole feature. If it ever regresses, every other
        // assertion in this file still passes while two factor does nothing.
        $this->assertGuest();
    }

    public function test_a_valid_code_completes_the_sign_in(): void
    {
        $user = $this->enable($this->superAdmin(['password' => 'admin-password']));

        $component = Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'admin-password',
            ])
            ->call('authenticate');

        $this->assertGuest();

        // The challenge form shares the login form's `data` state path and
        // nests each provider under its own id, so the code lands at
        // data.multiFactor.app.code — fillForm() cannot reach it.
        $component
            ->set('data.multiFactor.app.code', app(AppAuthentication::class)->getCurrentCode($user))
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_without_two_factor_signs_in_on_the_password_alone(): void
    {
        // Opt-in means the challenge must not appear for everyone else.
        $user = $this->superAdmin(['password' => 'admin-password']);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'admin-password',
            ])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_codes_are_stored_hashed(): void
    {
        $user = $this->superAdmin();

        // AppAuthentication hashes each code on the way in — they only ever
        // need comparing, never reading back.
        app(AppAuthentication::class)->saveRecoveryCodes($user, ['plain-code-one']);

        $stored = $user->refresh()->getAppAuthenticationRecoveryCodes();

        $this->assertNotSame('plain-code-one', $stored[0]);
        $this->assertTrue(Hash::check('plain-code-one', $stored[0]));
    }
}

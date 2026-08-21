<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Two-factor authentication, opt-in per user.
     *
     * These traits are the whole storage layer: they merge an `encrypted` cast
     * and a `$hidden` entry for each column, and implement the two contracts
     * above against them. Nothing is added to #[Fillable] on purpose — the
     * secret is written by Filament through a direct property assignment, and
     * a fillable secret would be settable from any request that reaches a
     * user form.
     */
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Audits every change to the two-factor secret.
     *
     * LogsActivity cannot cover this: its allowlist is ['name', 'email'], and
     * widening it to include the secret column would write the secret itself
     * into the log. Watching the event instead records that the column changed
     * without ever recording what it changed to.
     *
     * Only the secret is watched, not the recovery codes. Codes are rewritten
     * every time one is spent during a sign-in, and a log entry per consumed
     * code would say nothing the sign-in history does not already say.
     */
    protected static function booted(): void
    {
        static::updated(function (self $user): void {
            if (! $user->wasChanged('app_authentication_secret')) {
                return;
            }

            // Someone clearing their own two-factor had to pass a code or a
            // recovery code to do it. Someone clearing another account's did
            // not, and that is a step towards taking the account over — so the
            // two are separate events rather than one, and neither can be
            // mistaken for the other when reading the log back.
            $isEnabled = filled($user->app_authentication_secret);
            $isSelf = Auth::id() === $user->getKey();

            // The event key stays English and machine-readable; only the
            // description is translated. See LogRoleChange for the same split.
            [$event, $description] = match (true) {
                $isEnabled => ['two_factor_enabled', 'Autentikasi dua faktor diaktifkan'],
                $isSelf => ['two_factor_disabled', 'Autentikasi dua faktor dimatikan'],
                default => ['two_factor_reset', 'Autentikasi dua faktor direset oleh pengguna lain'],
            };

            activity('user')
                ->performedOn($user)
                ->event($event)
                ->log($description);
        });
    }

    /**
     * Whether app authentication is set up. Mirrors how Filament decides:
     * AppAuthentication::isEnabled() tests the same column for a value.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->app_authentication_secret);
    }

    /**
     * Clears two-factor for a user who lost their device.
     *
     * The recovery codes go with the secret. Leaving them behind would mean the
     * next person to enable two-factor on this account inherits a set of valid
     * codes they never saw — codes the previous holder may still have on paper.
     */
    public function resetTwoFactor(): void
    {
        $this->app_authentication_secret = null;
        $this->app_authentication_recovery_codes = null;
        $this->save();
    }

    /**
     * Gate for every page of the Filament panel.
     *
     * Holding any role is what grants entry; Filament Shield policies then
     * decide what is reachable inside. Filament calls this on login and again
     * through its Authenticate middleware on every request, so removing a
     * user's last role locks an existing session out immediately.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }

    /**
     * Audit trail written to the activity_log table.
     *
     * Attributes are listed explicitly rather than using logAll(): the table
     * holds password hashes and remember tokens, and an allowlist cannot leak
     * a sensitive column added later.
     *
     * Role changes are not visible here — roles are a relation, not a column.
     * They are audited separately by App\Listeners\LogRoleChange.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'username', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }
}

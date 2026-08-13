<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

// is_admin is deliberately NOT fillable: it grants access to the whole admin
// panel, so it must never be settable from request data. Change it explicitly
// with grantAdmin()/revokeAdmin() from an authorization-checked code path.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /**
     * The database default only applies to the INSERT. Declaring it here as
     * well means a freshly instantiated User already reads as a non-admin,
     * instead of null, and the audit log records a real false -> true
     * transition when the flag is granted.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

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
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Gate for every page of the Filament panel.
     *
     * Filament calls this on login and again through its Authenticate
     * middleware on each request, so revoking is_admin locks an existing
     * session out immediately.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Grant panel access. Bypasses mass assignment protection on purpose, so
     * every caller is an explicit, greppable decision rather than whatever
     * happened to arrive in a request payload. The change is picked up by the
     * activity log like any other update.
     */
    public function grantAdmin(): bool
    {
        return $this->forceFill(['is_admin' => true])->save();
    }

    /**
     * Revoke panel access. Takes effect on the revoked user's next request,
     * since Filament re-checks canAccessPanel() through its middleware.
     */
    public function revokeAdmin(): bool
    {
        return $this->forceFill(['is_admin' => false])->save();
    }

    /**
     * Audit trail written to the activity_log table.
     *
     * Attributes are listed explicitly rather than using logAll(): the table
     * holds password hashes and remember tokens, and an allowlist cannot leak
     * a sensitive column added later. is_admin is included because a change
     * there is a privilege escalation and is the single most important event
     * on this model to be able to review.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_admin'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }
}

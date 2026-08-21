<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The panel's sign-in screen, accepting either identifier.
 *
 * It lives here rather than under app/Filament/Pages so that nothing about it
 * depends on which base class Filament's auth pages extend. discoverPages()
 * registers a route for every Filament\Pages\Page subclass it scans; this one
 * extends SimplePage, so it would be passed over — while EditProfile does
 * extend Page and carries $isDiscovered = false for exactly that reason. Out
 * here the distinction never has to be checked again.
 *
 * Filament builds the whole sign-in around getCredentialsFromFormData(): the
 * array it returns goes to EloquentUserProvider::retrieveByCredentials(), which
 * turns every key but `password` into a where clause. So swapping `email` for
 * `username` needs no custom guard and no custom user provider — but the keys
 * are AND-ed, which is why the column has to be chosen before the query rather
 * than searched across both.
 */
class Login extends BaseLogin
{
    /**
     * Replaces the base email field. The name changes to `login`, so the
     * failure message has to be re-pointed as well — see below.
     *
     * ->email() is deliberately gone: it would refuse a username outright,
     * client-side, before anything here ran.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Email atau nama pengguna')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * An '@' means an email address, anything else means a username.
     *
     * The rule is total because the column is NOT NULL, and unambiguous
     * because UserForm validates the username as alpha_dash — no username can
     * contain an '@', so no input matches both readings.
     *
     * Only the username is lowercased. Usernames are stored lowercase by the
     * form, while email addresses are stored as typed and have always been
     * matched exactly; folding their case here would be a change to who can
     * sign in, made in passing.
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim($data['login']);

        return str_contains($login, '@')
            ? ['email' => $login, 'password' => $data['password']]
            : ['username' => Str::lower($login), 'password' => $data['password']];
    }

    /**
     * The base class attaches the failure to `data.email`, a field that no
     * longer exists on this form. Livewire raises nothing for a message on an
     * unknown key — the screen would simply reload with no explanation, for a
     * wrong password as much as for an unknown account.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}

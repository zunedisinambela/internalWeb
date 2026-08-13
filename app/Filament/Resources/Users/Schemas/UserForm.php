<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Scoped to the table so editing a user does not
                            // collide with their own address.
                            ->unique(ignoreRecord: true),
                    ]),

                Section::make('Password')
                    ->columns(2)
                    ->components([
                        // Required when creating, optional when editing: an
                        // empty field on edit must leave the stored hash alone
                        // rather than overwrite it with an empty string.
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->confirmed()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the current password.'
                                : 'At least 8 characters.'),

                        TextInput::make('password_confirmation')
                            ->label('Confirm password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // Never stored: it exists only to satisfy confirmed().
                            ->dehydrated(false),
                    ]),

                Section::make('Roles')
                    ->description('Holding any role grants access to this panel and to the log viewer.')
                    ->components([
                        Select::make('roles')
                            ->hiddenLabel()
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            // Editing yourself down to zero roles would lock you
                            // out of the page you are standing on.
                            ->required(fn (?User $record): bool => $record?->is(Auth::user()) ?? false)
                            ->helperText(fn (?User $record): ?string => $record?->is(Auth::user())
                                ? 'You cannot remove your own last role.'
                                : null),
                    ]),
            ]);
    }
}

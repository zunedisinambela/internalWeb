<?php

namespace App\Filament\Resources\Users\Actions;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Clears another user's two-factor secret, for when they lose their device.
 *
 * Written as its own class because it is mounted in two places — the users
 * table and the user view page — and a rule this sensitive should not be
 * maintained in two copies.
 */
class ResetTwoFactorAction
{
    public static function make(): Action
    {
        return Action::make('resetTwoFactor')
            ->label('Reset 2FA')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('danger')
            ->modalWidth(Width::Medium)
            ->modalIcon(Heroicon::OutlinedShieldExclamation)
            ->modalHeading('Reset autentikasi dua faktor')
            ->modalDescription(fn (User $record): string => sprintf(
                'Setelah direset, %s bisa masuk hanya dengan kata sandi, dan kode pemulihan lamanya tidak berlaku lagi. Lakukan ini hanya kalau yang bersangkutan benar-benar kehilangan perangkatnya.',
                $record->name,
            ))
            ->modalSubmitActionLabel('Reset')
            // The admin's own password is asked for, not the target's. This
            // action removes a security control from someone else's account, so
            // it should not be one click away on a session left unattended.
            ->schema([
                TextInput::make('password')
                    ->label('Kata sandi Anda')
                    ->helperText('Konfirmasi bahwa ini benar-benar Anda.')
                    ->password()
                    ->revealable()
                    ->required()
                    ->currentPassword(),
            ])
            // Filament checks visibility server-side before mounting, and the
            // rule lives on the resource so the table and the view page cannot
            // drift apart. See UserResource::canResetTwoFactor().
            ->visible(fn (User $record): bool => UserResource::canResetTwoFactor($record))
            ->action(function (User $record): void {
                // The activity log entry is written by User::booted(), which
                // watches the column rather than this button — so a reset done
                // from tinker or a future screen is recorded just the same.
                $record->resetTwoFactor();

                Notification::make()
                    ->success()
                    ->title('Autentikasi dua faktor direset')
                    ->body("{$record->name} sekarang masuk hanya dengan kata sandi. Minta yang bersangkutan mengaktifkannya lagi dari halaman profil.")
                    ->send();
            });
    }
}

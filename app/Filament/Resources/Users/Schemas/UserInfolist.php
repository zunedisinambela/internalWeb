<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Akun')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name'),

                        TextEntry::make('username')
                            ->label('Nama pengguna')
                            ->copyable(),

                        TextEntry::make('email')
                            ->copyable(),

                        TextEntry::make('email_verified_at')
                            ->label('Terverifikasi')
                            ->dateTime('d M Y H:i')
                            ->placeholder('Belum terverifikasi'),

                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime('d M Y H:i'),
                    ]),

                Section::make('Akses')
                    ->components([
                        TextEntry::make('roles.name')
                            ->label('Peran')
                            ->badge()
                            ->placeholder('Tanpa akses')
                            ->helperText(fn (User $record): string => $record->roles()->exists()
                                ? 'Bisa masuk ke panel ini dan ke log viewer.'
                                : 'Tidak bisa masuk ke panel ini maupun ke log viewer.'),

                        // State is derived, never the stored value: the column
                        // holds the encrypted TOTP secret, and anyone who can
                        // read it can generate that account's codes.
                        TextEntry::make('two_factor')
                            ->label('Autentikasi dua faktor')
                            ->state(fn (User $record): string => $record->hasTwoFactorEnabled()
                                ? 'Aktif'
                                : 'Belum aktif')
                            ->badge()
                            ->color(fn (User $record): string => $record->hasTwoFactorEnabled() ? 'success' : 'gray')
                            ->helperText(fn (User $record): string => $record->hasTwoFactorEnabled()
                                ? 'Masuk butuh kata sandi dan kode dari aplikasi authenticator.'
                                : 'Masuk cukup dengan kata sandi. Hanya pemilik akun yang bisa mengaktifkannya, dari halaman profilnya sendiri.'),
                    ]),
            ]);
    }
}

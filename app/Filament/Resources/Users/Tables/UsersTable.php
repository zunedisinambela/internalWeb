<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\ResetTwoFactorAction;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('username')
                    ->label('Nama pengguna')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('roles.name')
                    ->label('Peran')
                    ->badge()
                    ->placeholder('Tanpa akses'),

                IconColumn::make('app_authentication_secret')
                    ->label('2FA')
                    // The column is encrypted and hidden, so it is never
                    // rendered — only whether it holds anything.
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled())
                    ->boolean()
                    ->trueIcon(Heroicon::LockClosed)
                    ->falseIcon(Heroicon::OutlinedLockOpen)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (User $record): string => $record->hasTwoFactorEnabled()
                        ? 'Aktif — butuh kode aplikasi saat masuk'
                        : 'Belum aktif — cukup kata sandi saat masuk'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),

                Filter::make('without_roles')
                    ->label('Tanpa akses')
                    ->query(fn (Builder $query): Builder => $query->doesntHave('roles')),

                TernaryFilter::make('two_factor')
                    ->label('2FA')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Belum aktif')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('app_authentication_secret'),
                        false: fn (Builder $query): Builder => $query->whereNull('app_authentication_secret'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ResetTwoFactorAction::make(),
                // Self-deletion is refused by UserResource::canDelete(), which
                // also covers the bulk action below.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada pengguna');
    }
}

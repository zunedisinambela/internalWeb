<?php

namespace App\Filament\Resources\Authentications\Tables;

use App\Models\AuthenticationMonitoring;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuthenticationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (AuthenticationMonitoring $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                TextColumn::make('action_type')
                    ->label('Peristiwa')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'login' => 'success',
                        'logout' => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Pengguna')
                    // Rows outlive the account: user_id is nullOnDelete() so
                    // the history of a removed user stays readable.
                    ->placeholder('Pengguna terhapus')
                    ->description(fn (AuthenticationMonitoring $record): ?string => $record->user?->email)
                    ->searchable(),

                TextColumn::make('ip')
                    ->label('IP')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('browser_name')
                    ->label('Peramban')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('device')
                    ->label('Perangkat')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('page')
                    ->label('Dari halaman')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? (parse_url($state, PHP_URL_PATH) ?: '/')
                        : '—')
                    ->tooltip(fn (AuthenticationMonitoring $record): ?string => $record->page)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user_guard')
                    ->label('Guard')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action_type')
                    ->label('Peristiwa')
                    ->options([
                        'login' => 'Login',
                        'logout' => 'Logout',
                    ]),

                SelectFilter::make('user_id')
                    ->label('Pengguna')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Dari '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Sampai '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned rather than left to the default: with
                    // fetchSelectedRecords off, Filament deletes through one
                    // query, which fires no model events — and the activity log
                    // entry in AuthenticationMonitoring::booted() hangs off the
                    // `deleted` event. Bulk removals would vanish untraced
                    // while single ones stayed audited.
                    DeleteBulkAction::make()
                        ->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada riwayat masuk')
            ->emptyStateDescription('Baris ditulis oleh peristiwa Login dan Logout, jadi entri pertama muncul saat ada yang masuk berikutnya.');
    }
}

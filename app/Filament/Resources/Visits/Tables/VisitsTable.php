<?php

namespace App\Filament\Resources\Visits\Tables;

use App\Models\VisitMonitoring;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (VisitMonitoring $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Pengguna')
                    // guest_mode is on, so a null user_id is expected and means
                    // the request arrived signed out — not missing data.
                    ->placeholder('Tamu')
                    ->description(fn (VisitMonitoring $record): ?string => $record->user?->email)
                    ->searchable(),

                TextColumn::make('page')
                    ->label('Halaman')
                    // The full URL is stored; the host is the same for every
                    // row, so trim it and keep the path visible.
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? (parse_url($state, PHP_URL_PATH) ?: '/').(parse_url($state, PHP_URL_QUERY) ? '?'.parse_url($state, PHP_URL_QUERY) : '')
                        : '—')
                    ->tooltip(fn (VisitMonitoring $record): ?string => $record->page)
                    ->limit(50)
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

                // The package writes getDevice() into both `platform` and
                // `device`, so the two columns always hold the same string.
                // Only one is worth showing.
                TextColumn::make('device')
                    ->label('Perangkat')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user_guard')
                    ->label('Guard')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Pengguna')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('guests')
                    ->label('Hanya tamu')
                    ->query(fn (Builder $query): Builder => $query->whereNull('user_id')),

                // Read from the table so newly seen browsers show up without
                // touching this file.
                SelectFilter::make('browser_name')
                    ->label('Peramban')
                    ->options(fn (): array => VisitMonitoring::query()
                        ->whereNotNull('browser_name')
                        ->distinct()
                        ->orderBy('browser_name')
                        ->pluck('browser_name', 'browser_name')
                        ->all()),

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
                    // fetchSelectedRecords() is pinned rather than left to the
                    // default. With it off, Filament deletes through a single
                    // query, which fires no model events — and the activity log
                    // entry in VisitMonitoring::booted() hangs off the `deleted`
                    // event. Losing it would mean bulk deletions vanish without
                    // a trace while single ones stay audited.
                    DeleteBulkAction::make()
                        ->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada kunjungan tercatat')
            ->emptyStateDescription('Kunjungan halaman masuk ke sini saat orang menjelajah. Permintaan latar dari Livewire disaring oleh App\Monitoring\PageViewsOnly.');
    }
}

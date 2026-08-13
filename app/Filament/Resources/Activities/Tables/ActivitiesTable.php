<?php

namespace App\Filament\Resources\Activities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (Activity $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'restored' => 'info',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('subject')
                    ->label('Objek')
                    ->state(fn (Activity $record): string => $record->subject_type
                        ? class_basename($record->subject_type)." #{$record->subject_id}"
                        : '—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('causer')
                    ->label('Pelaku')
                    ->state(fn (Activity $record): string => $record->causer?->name
                        ?? ($record->causer_type ? class_basename($record->causer_type)." #{$record->causer_id}" : 'Sistem'))
                    ->description(fn (Activity $record): ?string => $record->causer?->email),

                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Options are read from the table itself rather than hardcoded,
                // so new log names and events appear in the filter automatically.
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),

                SelectFilter::make('event')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('event')
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),

                SelectFilter::make('subject_type')
                    ->label('Tipe objek')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->orderBy('subject_type')
                        ->pluck('subject_type', 'subject_type')
                        ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
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
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned, like the other two monitoring tables: with
                    // fetchSelectedRecords off Filament deletes through one
                    // query and fires no model events, and the log entry for
                    // each deletion hangs off the `deleted` event.
                    DeleteBulkAction::make()
                        ->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada aktivitas tercatat')
            ->emptyStateDescription('Entri muncul di sini begitu model yang memakai trait LogsActivity berubah, atau begitu activity() dipanggil.');
    }
}

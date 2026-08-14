<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\Room;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Active rooms first, then by name. A room that stopped being rented
            // is still worth reaching, but it is not what this screen is opened
            // for.
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Kamar')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('occupant')
                    ->label('Penghuni')
                    ->searchable()
                    ->placeholder('Kosong'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                // counts() adds the aggregate to the same query rather than one
                // per row. It is also the figure canDelete() turns on, so seeing
                // it here explains why the delete button is missing.
                TextColumn::make('meter_readings_count')
                    ->label('Pencatatan')
                    ->counts('meterReadings')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Tidak aktif'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned to per-record deletes, like every other bulk action
                    // in this panel: the single-query path fires no model events,
                    // so the activity log entry would go missing — and here it
                    // would also skip RoomResource::canDelete(), taking the
                    // readings check with it.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada kamar')
            ->emptyStateDescription('Tambahkan kamar terlebih dahulu sebelum mencatat meteran.')
            ->recordUrl(fn (Room $record): string => route('filament.admin.resources.rooms.view', $record));
    }
}

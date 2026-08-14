<?php

namespace App\Filament\Resources\ElectricityTariffs\Tables;

use App\Models\ElectricityTariff;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ElectricityTariffsTable
{
    public static function configure(Table $table): Table
    {
        // Resolved once per table render, not once per row. configure() is called
        // when the table is built for a request, so this is a single query for
        // the whole page — the alternative, asking inside the column closure,
        // would be one per visible row.
        $currentId = ElectricityTariff::current()?->getKey();

        return $table
            // Newest rate first: the one in force is what this screen is opened
            // to check, and it is almost always at or near the top.
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Berlaku mulai')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('rate')
                    ->label('Tarif')
                    ->alignEnd()
                    ->sortable()
                    // number_format rather than ->money('IDR'): money() renders
                    // two decimal places unless told otherwise and leans on
                    // ext-intl for grouping this does directly. The column is
                    // whole rupiah, so there is nothing after a separator.
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.').'/kWh')
                    ->weight('medium'),

                // Derived, never stored. A stored "is_active" flag on a tariff
                // would need something to flip it the day a scheduled rate takes
                // over — a scheduler this app does not run for anything but
                // retention, and whose absence is silent (see Monitoring).
                // Reading it off the dates cannot fall out of step.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function (ElectricityTariff $record) use ($currentId): string {
                        if ($record->effective_from->isFuture()) {
                            return 'Terjadwal';
                        }

                        return $record->getKey() === $currentId ? 'Berlaku' : 'Riwayat';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Berlaku' => 'success',
                        'Terjadwal' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('user.name')
                    ->label('Diatur oleh')
                    ->placeholder('Tidak diketahui')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada tarif')
            ->emptyStateDescription('Tetapkan tarif per kWh sebelum mencatat meteran kamar.');
    }
}

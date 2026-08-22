<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('phone')
                    ->label('Telepon')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable()
                    ->copyMessage('Nomor disalin'),

                // A count, not a margin. The totals on the view screen walk the
                // lines in PHP, and doing that per row here would be a query per
                // customer — while a withSum would be a second copy of the
                // arithmetic that Customer::$total_profit already owns.
                TextColumn::make('sales_count')
                    ->label('Transaksi')
                    ->counts('sales')
                    ->alignEnd()
                    ->sortable()
                    ->badge(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                // Hidden by default and still searchable, which is not a
                // contradiction: applyGlobalSearchToTableQuery() skips a column
                // only for isHidden() — the ->hidden()/->visible() API — and
                // never for the column-manager toggle. So the address is
                // findable without spending the width of the list on it, which
                // is the one column here that would need a whole row to itself.
                // test_an_address_is_searchable_while_its_column_is_hidden pins
                // that, since it rests on vendor internals rather than on a
                // documented promise.
                TextColumn::make('address')
                    ->label('Alamat')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(40)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Ditambahkan')
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
                    // Pinned to per-record deletes so the activity log entry is
                    // written for each one, and so CustomerResource::canDelete()
                    // is consulted per record rather than the whole selection
                    // going down in a single query the foreign key would then
                    // refuse halfway through.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada pelanggan')
            ->emptyStateDescription('Tambahkan orang yang membeli dari Anda, lalu catat penjualannya.');
    }
}

<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Both figures the bonus needs, as two aggregate subqueries on the
            // one list query. ->sum() on the column above supplies the first;
            // the second has no column to hang off, so it is asked for here.
            // Walking either relation per row would be a query per customer, and
            // this list is the screen most likely to hold a full page of them.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum('freeItemRedemptions', 'quantity'))
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

                // The item count and the bonus it earns, asked of the database
                // rather than of the relation: Customer::$total_quantity walks
                // the sales in PHP, which is one query per row here, while
                // ->sum() is one aggregate subquery on the list query — the same
                // reasoning the count column above records. The bonus is not a
                // second column: it is only ever read alongside the count it
                // comes from, and a column of mostly zeroes would cost a row's
                // width to say nothing.
                TextColumn::make('sales_sum_quantity')
                    ->label('Barang')
                    ->sum('sales', 'quantity')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => number_format((int) $state, 0, ',', '.'))
                    // What the list is scanned for is who is still owed
                    // something, so the description carries the *remainder*
                    // rather than the earned figure: a customer who has already
                    // collected both of their free items needs nothing done.
                    ->description(function (Customer $record): ?string {
                        $earned = Customer::freeItemsFor($record->sales_sum_quantity);
                        $remaining = self::remainingFreeItems($record);

                        return match (true) {
                            $remaining > 0 => '+'.$remaining.' gratis belum diambil',
                            $earned > 0 => 'gratis sudah diambil',
                            default => null,
                        };
                    }, position: 'below')
                    ->color(fn (Customer $record): string => match (true) {
                        self::remainingFreeItems($record) < 0 => 'danger',
                        self::remainingFreeItems($record) > 0 => 'success',
                        default => 'gray',
                    }),

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

    /**
     * Free items earned but not yet collected, from the two subqueries above.
     *
     * Reads the aggregates rather than Customer::$free_quantity_available, which
     * is the same arithmetic over loaded relations — the accessor is right on the
     * view screen, where the relations are already in memory, and is a pair of
     * queries per row here. Both divide through Customer::freeItemsFor(), so the
     * two routes cannot disagree about what twenty items are worth.
     *
     * Either aggregate arrives as null for a customer with no rows on that side,
     * which is why both are cast rather than added directly.
     */
    private static function remainingFreeItems(Customer $record): int
    {
        return Customer::freeItemsFor($record->sales_sum_quantity)
            - (int) $record->free_item_redemptions_sum_quantity;
    }
}

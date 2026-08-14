<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Newest first, like the cash book and the meter log: a sales ledger
            // is read from the most recent entry back.
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Tanggal')
                    // Stored in WIB already, so no timezone conversion is set
                    // here. translatedFormat() gives Indonesian month names
                    // because AppServiceProvider sets Carbon's locale.
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    // The products as the description rather than a second
                    // column: a sale is identified by who bought and what, and
                    // the list is scanned for exactly that pair.
                    ->description(fn (Sale $record): ?string => $record->items
                        ->map(fn ($item): string => $item->product?->name.' ×'.$item->quantity)
                        ->filter()
                        ->join(', ') ?: null),

                TextColumn::make('items_count')
                    ->label('Item')
                    ->counts('items')
                    ->alignEnd()
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                // All three totals are accessors over the loaded lines, so the
                // database has no column to sort them by. The expression is
                // spelled out per column through Sale::sumOfItems(), because
                // ->sortable() alone on a ->state() column renders a control
                // that silently reorders by nothing.
                TextColumn::make('catalog_total')
                    ->label('Harga katalog')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => 'Rp '.number_format($record->catalog_total, 0, ',', '.'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy(Sale::sumOfItems('quantity * catalog_price'), $direction)),

                TextColumn::make('marketing_total')
                    ->label('Harga marketing')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => 'Rp '.number_format($record->marketing_total, 0, ',', '.'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy(Sale::sumOfItems('quantity * marketing_price'), $direction))
                    ->toggleable(),

                TextColumn::make('profit')
                    ->label('Keuntungan')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => 'Rp '.number_format($record->profit, 0, ',', '.'))
                    ->color(fn (Sale $record): string => $record->profit < 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy(Sale::sumOfItems('quantity * (catalog_price - marketing_price)'), $direction)),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.name')
                    ->label('Dicatat oleh')
                    ->placeholder('Tidak diketahui')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Pelanggan')
                    ->relationship('customer', 'name', fn (Builder $query): Builder => $query->orderBy('name'))
                    ->searchable()
                    ->preload(),

                SelectFilter::make('product')
                    ->label('Produk')
                    ->relationship('items.product', 'name', fn (Builder $query): Builder => $query->orderBy('name'))
                    ->searchable()
                    ->preload(),

                Filter::make('occurred_at')
                    ->label('Rentang tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari')->native(false),
                        DatePicker::make('until')->label('Sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('occurred_at', '>=', $date))
                        // whereDate on the upper bound, not whereBetween on a
                        // datetime: an "until" of 20 Aug must include everything
                        // sold on the 20th, not stop at 00:00 that day.
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('occurred_at', '<=', $date)))
                    ->indicateUsing(function (array $data): ?string {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        return match (true) {
                            $from && $until => "Tanggal: {$from} sampai {$until}",
                            (bool) $from => "Sejak {$from}",
                            (bool) $until => "Sampai {$until}",
                            default => null,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned to per-record deletes so each sale writes its own
                    // activity log entry. The lines go with it through the
                    // foreign key cascade either way, but the single-query bulk
                    // path would take the audit trail down with the rows.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada penjualan')
            // Names whichever prerequisite is actually missing. The create button
            // hides until both exist, so an empty state saying only "catat
            // penjualan pertama Anda" would describe a button that is not there.
            ->emptyStateDescription(fn (): string => match (true) {
                ! Customer::query()->exists() => 'Tambahkan pelanggan terlebih dahulu di menu Pelanggan.',
                ! Product::query()->exists() => 'Tambahkan produk terlebih dahulu di menu Produk.',
                default => 'Catat pembelian pertama pelanggan Anda.',
            });
    }
}

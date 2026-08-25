<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Models\Customer;
use App\Models\Sale;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
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
                    ->weight('medium'),

                TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->alignEnd()
                    ->numeric()
                    ->sortable()
                    // Badged only when the order actually earned something, so
                    // the column is scanned for the exceptions rather than read
                    // row by row. The bonus has no column behind it and is not
                    // sortable for that reason — it is a function of `quantity`,
                    // which sorts identically.
                    ->description(fn (Sale $record): ?string => $record->free_quantity > 0
                        ? '+'.$record->free_quantity.' gratis'
                        : null),

                // The three stored figures, in the order they are read off a
                // note. Unlike the previous shape these are real columns, so
                // ->sortable() needs no expression — there is nothing derived
                // left for Filament to silently reorder by nothing.
                TextColumn::make('marketing_price')
                    ->label('Harga market')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => self::rupiah($record->marketing_price))
                    ->sortable(),

                TextColumn::make('shipping_cost')
                    ->label('Ongkir')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => self::rupiah($record->shipping_cost))
                    ->sortable(),

                TextColumn::make('catalog_price')
                    ->label('Harga katalog')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => self::rupiah($record->catalog_price))
                    ->sortable(),

                // The margin is the one figure here with no column behind it, so
                // its sort is spelled out. The expression lives on the model
                // beside the accessor it has to agree with.
                TextColumn::make('profit')
                    ->label('Keuntungan')
                    ->alignEnd()
                    ->state(fn (Sale $record): string => self::rupiah($record->profit))
                    ->color(fn (Sale $record): string => $record->profit < 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw(Sale::PROFIT_EXPRESSION.' '.($direction === 'desc' ? 'desc' : 'asc'))),

                // Both hidden by default. The list is scanned for figures, and
                // two more columns on a table that already scrolls sideways on a
                // phone would cost more than they are read — but "did this one
                // get paid" is a real question, so they are one toggle away
                // rather than absent.
                self::attachments('payment_proofs', Sale::PAYMENT_PROOFS, 'Bukti transfer'),
                self::attachments('shipping_proofs', Sale::SHIPPING_PROOFS, 'Resi'),

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
                    // activity log entry; the single-query bulk path fires no
                    // model events and would take the audit trail down with the
                    // rows.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada penjualan')
            // The create button hides until a customer exists, so the empty
            // state names that prerequisite rather than describing a button that
            // is not there.
            ->emptyStateDescription(fn (): string => Customer::query()->exists()
                ? 'Catat pembelian pertama pelanggan Anda.'
                : 'Tambahkan pelanggan terlebih dahulu di menu Pelanggan.');
    }

    /**
     * One image column bound to one collection, built from a shared factory for
     * the reason given in SaleForm: a missing ->visibility('private') renders a
     * broken image and logs nothing.
     */
    private static function attachments(string $name, string $collection, string $label): SpatieMediaLibraryImageColumn
    {
        return SpatieMediaLibraryImageColumn::make($name)
            ->label($label)
            ->collection($collection)
            ->conversion(Sale::THUMBNAIL)
            ->disk('local')
            // Same reason as on the form field: the private disk answers signed
            // URLs only, and without this the column asks for an unsigned one and
            // renders a broken image.
            ->visibility('private')
            ->circular()
            ->stacked()
            ->limit(3)
            ->limitedRemainingText()
            ->toggleable(isToggledHiddenByDefault: true);
    }

    /**
     * Grouped the Indonesian way, whole rupiah. Written out rather than with
     * ->money('IDR') for the reason given under Keuangan: money() renders two
     * decimal places unless told otherwise, and every figure here is whole.
     */
    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}

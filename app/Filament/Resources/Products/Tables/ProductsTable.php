<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
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

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Product $record): ?string => $record->code),

                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('catalog_price')
                    ->label('Harga katalog')
                    ->alignEnd()
                    // number_format rather than ->money('IDR'), matching the cash
                    // book: money() renders two decimal places unless told
                    // otherwise, and these columns are whole rupiah.
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('marketing_price')
                    ->label('Harga marketing')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->sortable(),

                // Derived from the two columns beside it rather than stored, so
                // it cannot disagree with them. The sort has to be spelled out
                // for the same reason — there is no column to order by, and
                // ->sortable() alone would render a control that reorders by
                // nothing.
                TextColumn::make('unit_profit')
                    ->label('Untung / unit')
                    ->alignEnd()
                    ->state(fn (Product $record): string => 'Rp '.number_format($record->unit_profit, 0, ',', '.'))
                    ->color(fn (Product $record): string => $record->unit_profit < 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("(catalog_price - marketing_price) {$direction}")),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('sale_items_count')
                    ->label('Terjual')
                    ->counts('saleItems')
                    ->alignEnd()
                    ->badge()
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
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada produk')
            ->emptyStateDescription('Masukkan produk katalog beserta harga katalog dan harga marketingnya.');
    }
}

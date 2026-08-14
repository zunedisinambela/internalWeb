<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Models\Sale;
use App\Models\SaleItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Penjualan')
                    ->columns(3)
                    ->components([
                        TextEntry::make('customer.name')
                            ->label('Pelanggan')
                            ->weight('bold'),

                        TextEntry::make('occurred_at')
                            ->label('Tanggal pembelian')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('user.name')
                            ->label('Dicatat oleh')
                            ->placeholder('Tidak diketahui'),

                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Produk yang dibeli')
                    ->description('Harga di bawah adalah salinan yang tersimpan pada penjualan ini, bukan harga katalog hari ini.')
                    ->components([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Produk'),
                                TableColumn::make('Jumlah')->alignEnd(),
                                TableColumn::make('Harga katalog')->alignEnd(),
                                TableColumn::make('Harga marketing')->alignEnd(),
                                TableColumn::make('Untung')->alignEnd(),
                            ])
                            ->components([
                                TextEntry::make('product.name')
                                    ->label('Produk')
                                    ->weight('medium'),

                                TextEntry::make('quantity')
                                    ->label('Jumlah'),

                                TextEntry::make('catalog_subtotal')
                                    ->label('Harga katalog')
                                    ->state(fn (SaleItem $record): string => self::rupiah($record->catalog_subtotal))
                                    // The unit price under the line total,
                                    // because a line reading "Rp 300.000" for
                                    // three units is otherwise indistinguishable
                                    // from one unit at that price.
                                    ->helperText(fn (SaleItem $record): string => '@ '.self::rupiah($record->catalog_price)),

                                TextEntry::make('marketing_subtotal')
                                    ->label('Harga marketing')
                                    ->state(fn (SaleItem $record): string => self::rupiah($record->marketing_subtotal))
                                    ->helperText(fn (SaleItem $record): string => '@ '.self::rupiah($record->marketing_price)),

                                TextEntry::make('profit')
                                    ->label('Untung')
                                    ->state(fn (SaleItem $record): string => self::rupiah($record->profit))
                                    ->weight('bold')
                                    ->color(fn (SaleItem $record): string => $record->profit < 0 ? 'danger' : 'success'),
                            ]),
                    ]),

                Section::make('Ringkasan')
                    ->columns(3)
                    ->components([
                        TextEntry::make('catalog_total')
                            ->label('Total harga katalog')
                            ->state(fn (Sale $record): string => self::rupiah($record->catalog_total))
                            ->helperText('Yang dibayar pelanggan.'),

                        TextEntry::make('marketing_total')
                            ->label('Total harga marketing')
                            ->state(fn (Sale $record): string => self::rupiah($record->marketing_total))
                            ->helperText('Yang dibayar ke Oriflame.'),

                        TextEntry::make('profit')
                            ->label('Keuntungan')
                            ->state(fn (Sale $record): string => self::rupiah($record->profit))
                            ->weight('bold')
                            ->size('lg')
                            ->color(fn (Sale $record): string => $record->profit < 0 ? 'danger' : 'success'),
                    ]),

                Section::make('Pencatatan')
                    ->columns(2)
                    ->collapsed()
                    ->components([
                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime('d M Y H:i:s'),

                        TextEntry::make('updated_at')
                            ->label('Diubah')
                            ->dateTime('d M Y H:i:s'),
                    ]),
            ]);
    }

    /**
     * Grouped the Indonesian way, whole rupiah. Written out here rather than
     * with ->money('IDR') for the reason given under Keuangan: money() renders
     * two decimal places unless told otherwise, and every figure in this feature
     * is a whole number.
     */
    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}

<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Models\Sale;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
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

                Section::make('Harga')
                    ->columns(3)
                    ->components([
                        TextEntry::make('marketing_price')
                            ->label('Harga market')
                            ->state(fn (Sale $record): string => self::rupiah($record->marketing_price))
                            ->helperText('Dibayar ke Oriflame.'),

                        TextEntry::make('shipping_cost')
                            ->label('Ongkir')
                            ->state(fn (Sale $record): string => self::rupiah($record->shipping_cost))
                            ->helperText('Ditanggung Anda, bukan ditagih ke pelanggan.'),

                        TextEntry::make('catalog_price')
                            ->label('Harga katalog')
                            ->state(fn (Sale $record): string => self::rupiah($record->catalog_price))
                            ->helperText('Dibayar pelanggan.'),
                    ]),

                Section::make('Ringkasan')
                    ->columns(2)
                    ->components([
                        TextEntry::make('total_cost')
                            ->label('Total modal')
                            ->state(fn (Sale $record): string => self::rupiah($record->total_cost))
                            ->helperText('Harga market ditambah ongkir.'),

                        TextEntry::make('profit')
                            ->label('Keuntungan')
                            ->state(fn (Sale $record): string => self::rupiah($record->profit))
                            ->weight('bold')
                            ->size('lg')
                            ->color(fn (Sale $record): string => $record->profit < 0 ? 'danger' : 'success'),
                    ]),

                Section::make('Lampiran')
                    ->columns(2)
                    ->components([
                        self::attachments('payment_proofs', Sale::PAYMENT_PROOFS, 'Bukti transfer'),
                        self::attachments('shipping_proofs', Sale::SHIPPING_PROOFS, 'Resi pengiriman'),
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
     * One image entry bound to one collection.
     *
     * Built from a shared factory for the reason MeterReadingInfolist gives:
     * ->visibility('private') missing from one of two copies renders a broken
     * image and logs nothing.
     */
    private static function attachments(string $name, string $collection, string $label): SpatieMediaLibraryImageEntry
    {
        return SpatieMediaLibraryImageEntry::make($name)
            ->label($label)
            ->collection($collection)
            ->conversion(Sale::THUMBNAIL)
            ->disk('local')
            // The private disk answers signed URLs only. See the note on the same
            // call in SaleForm.
            ->visibility('private')
            ->height(160)
            ->placeholder('Tidak ada berkas terlampir');
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

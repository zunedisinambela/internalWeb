<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Produk')
                    ->columns(3)
                    ->components([
                        TextEntry::make('name')
                            ->label('Nama produk')
                            ->weight('bold'),

                        TextEntry::make('code')
                            ->label('Kode')
                            ->placeholder('—')
                            ->copyable(),

                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),

                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Harga saat ini')
                    ->description('Angka ini yang disalin ke penjualan baru. Penjualan yang sudah tercatat memakai salinannya sendiri dan tidak ikut berubah.')
                    ->columns(3)
                    ->components([
                        TextEntry::make('catalog_price')
                            ->label('Harga katalog')
                            ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),

                        TextEntry::make('marketing_price')
                            ->label('Harga marketing')
                            ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),

                        TextEntry::make('unit_profit')
                            ->label('Keuntungan per unit')
                            ->state(fn (Product $record): string => 'Rp '.number_format($record->unit_profit, 0, ',', '.'))
                            ->weight('bold')
                            ->color(fn (Product $record): string => $record->unit_profit < 0 ? 'danger' : 'success'),
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
}

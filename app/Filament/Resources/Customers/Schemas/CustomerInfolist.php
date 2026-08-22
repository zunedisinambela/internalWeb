<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pelanggan')
                    ->columns(3)
                    ->components([
                        TextEntry::make('name')
                            ->label('Nama')
                            ->weight('bold'),

                        TextEntry::make('phone')
                            ->label('Telepon')
                            ->placeholder('—')
                            ->copyable(),

                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),

                        // The line breaks the user typed are the address's
                        // structure, so they are preserved rather than collapsed
                        // into one run of text.
                        TextEntry::make('address')
                            ->label('Alamat lengkap')
                            ->placeholder('—')
                            ->copyable()
                            ->columnSpanFull()
                            ->extraAttributes(['style' => 'white-space: pre-line']),

                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Ringkasan')
                    ->description('Dihitung dari seluruh penjualan atas nama pelanggan ini.')
                    ->columns(3)
                    ->components([
                        TextEntry::make('sales_summary_count')
                            ->label('Jumlah transaksi')
                            ->state(fn (Customer $record): string => number_format(
                                self::loaded($record)->sales->count(), 0, ',', '.',
                            )),

                        TextEntry::make('total_spent')
                            ->label('Total belanja')
                            ->state(fn (Customer $record): string => 'Rp '.number_format(
                                self::loaded($record)->total_spent, 0, ',', '.',
                            ))
                            ->helperText('Harga katalog, yaitu yang dibayar pelanggan.'),

                        TextEntry::make('total_profit')
                            ->label('Total keuntungan')
                            ->state(fn (Customer $record): string => 'Rp '.number_format(
                                self::loaded($record)->total_profit, 0, ',', '.',
                            ))
                            ->weight('bold')
                            ->color(fn (Customer $record): string => self::loaded($record)->total_profit < 0
                                ? 'danger'
                                : 'success'),
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
     * The record with its sales and their lines in memory.
     *
     * Customer's totals walk the sales, so without this each of the three
     * entries above would trigger its own query. loadMissing() is
     * idempotent, so calling it from every entry costs one load for the page
     * rather than one per entry — and putting the ->with() on the resource query
     * instead would pay for it on the list screen too, which shows none of this.
     */
    private static function loaded(Customer $record): Customer
    {
        return $record->loadMissing('sales');
    }
}

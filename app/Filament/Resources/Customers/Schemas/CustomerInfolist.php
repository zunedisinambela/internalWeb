<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Models\Sale;
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

                        // The count question, kept beside the transaction count
                        // rather than among the rupiah figures below it: the
                        // free item carries no money anywhere in this feature.
                        // See Sale::$free_quantity.
                        TextEntry::make('total_quantity')
                            ->label('Total barang')
                            ->state(fn (Customer $record): string => number_format(
                                self::loaded($record)->total_quantity, 0, ',', '.',
                            ).' barang'),

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

                // Its own section rather than three more entries in Ringkasan,
                // because these three are a different kind of number: two are
                // derived from the orders and one — what was collected — is a
                // fact somebody recorded, and the section that holds them sits
                // directly above the table those handovers are listed in.
                Section::make('Barang gratis')
                    ->description(sprintf(
                        'Setiap %d barang dibeli dapat 1 gratis. Pengambilannya dicatat di tabel bawah.',
                        Sale::FREE_ITEM_THRESHOLD,
                    ))
                    ->columns(3)
                    ->components([
                        // Counted across every order, not per order — two orders
                        // of ten earn one free item here and nothing on either
                        // sale. Customer::$free_quantity records why.
                        TextEntry::make('free_quantity')
                            ->label('Didapat')
                            ->state(fn (Customer $record): string => self::loaded($record)->free_quantity.' barang')
                            ->helperText(fn (Customer $record): string => sprintf(
                                'Kurang %d barang lagi untuk gratis berikutnya.',
                                self::loaded($record)->quantity_to_next_free_item,
                            )),

                        TextEntry::make('free_quantity_claimed')
                            ->label('Sudah diambil')
                            ->state(fn (Customer $record): string => self::loaded($record)->free_quantity_claimed.' barang')
                            ->helperText(fn (Customer $record): string => ($last = self::loaded($record)
                                ->freeItemRedemptions
                                ->sortByDesc('redeemed_at')
                                ->first()) === null
                                    ? 'Belum pernah diambil.'
                                    : 'Terakhir '.$last->redeemed_at->translatedFormat('d M Y H:i').'.'),

                        // Not clamped at zero: a negative figure means a
                        // handover was recorded against an order later corrected
                        // downwards, which is a real bookkeeping problem and is
                        // meant to be visible. See Customer::$free_quantity_available.
                        TextEntry::make('free_quantity_available')
                            ->label('Sisa belum diambil')
                            ->state(fn (Customer $record): string => self::loaded($record)->free_quantity_available.' barang')
                            ->weight('bold')
                            ->size('lg')
                            ->color(fn (Customer $record): string => match (true) {
                                self::loaded($record)->free_quantity_available < 0 => 'danger',
                                self::loaded($record)->free_quantity_available > 0 => 'success',
                                default => 'gray',
                            }),
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
     * The record with its sales and its handovers in memory.
     *
     * Every figure on this screen walks one of the two relations, so without
     * this each entry above would trigger its own query. loadMissing() is
     * idempotent, so calling it from every entry costs one load per relation for
     * the page rather than one per entry — and putting the ->with() on the
     * resource query instead would pay for it on the list screen too, which
     * shows none of this and asks the database for the same two figures with
     * aggregate subqueries instead.
     */
    private static function loaded(Customer $record): Customer
    {
        return $record->loadMissing(['sales', 'freeItemRedemptions']);
    }
}

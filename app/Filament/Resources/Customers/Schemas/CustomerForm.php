<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pelanggan')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nama yang Anda pakai untuk mengenali orang ini.'),

                        TextInput::make('phone')
                            ->label('Nomor telepon')
                            ->tel()
                            ->maxLength(30)
                            // Deliberately not unique and not required. Two
                            // people can share a phone, and most orders arrive
                            // from a chat where the number is already saved.
                            ->helperText('Opsional. Untuk menghubungi soal pesanan.'),

                        // Textarea rather than TextInput, and no maxLength: the
                        // column is `text` precisely because a full address runs
                        // past 255 characters, so a cap here would refuse what
                        // the row can hold. It is where the parcel goes, so it
                        // is read back line by line rather than scanned.
                        Textarea::make('address')
                            ->label('Alamat lengkap')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Opsional. Alamat pengiriman: jalan, RT/RW, kelurahan, kecamatan, kota dan kode pos.'),

                        Toggle::make('is_active')
                            ->label('Pelanggan aktif')
                            ->default(true)
                            ->helperText('Matikan untuk pelanggan yang sudah tidak membeli. Riwayat penjualannya tetap tersimpan.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Misalnya "teman kantor" atau "suka produk perawatan wajah".'),
                    ]),
            ])
            ->columns(1);
    }
}

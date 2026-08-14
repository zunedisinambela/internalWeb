<?php

namespace App\Filament\Resources\Customers\Schemas;

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

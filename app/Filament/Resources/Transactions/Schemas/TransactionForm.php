<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(2)
                    ->components([
                        // Two mutually exclusive choices with their own colour
                        // and icon, so the direction of the money is readable
                        // before the amount is. A Select would hide it behind a
                        // click on the screen where getting it wrong is worst.
                        ToggleButtons::make('type')
                            ->label('Jenis')
                            ->options(TransactionType::class)
                            ->default(TransactionType::Expense)
                            ->inline()
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('amount')
                            ->label('Jumlah')
                            ->prefix('Rp')
                            ->numeric()
                            // Whole rupiah only — the column is an integer, and
                            // a submitted 1500.75 would otherwise be truncated
                            // on the way in without telling anyone.
                            ->step(1)
                            ->integer()
                            ->minValue(1)
                            ->maxValue(999999999999)
                            ->required()
                            ->helperText('Dalam rupiah penuh, tanpa titik atau koma.'),

                        // Defaults to the moment the form is opened, which is
                        // what "waktu saat dibuat" means in practice. It stays
                        // editable: a receipt found later has to be datable to
                        // when the money actually moved, not to when it was
                        // typed in. now() is already WIB — APP_TIMEZONE is
                        // Asia/Jakarta and timestamps are stored in local time,
                        // so nothing is converted here.
                        DateTimePicker::make('occurred_at')
                            ->label('Tanggal dan waktu')
                            ->default(now())
                            ->seconds(false)
                            ->displayFormat('d M Y H:i')
                            ->native(false)
                            ->required()
                            ->maxDate(now()->addDay())
                            ->helperText('Otomatis terisi waktu sekarang. Ubah bila transaksi terjadi di lain waktu.'),

                        TextInput::make('description')
                            ->label('Keterangan')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Bukti')
                    ->description('Foto struk, nota atau bukti transfer. Bisa lebih dari satu.')
                    ->components([
                        SpatieMediaLibraryFileUpload::make('receipts')
                            ->hiddenLabel()
                            ->collection(Transaction::RECEIPTS)
                            ->disk('local')
                            // The switch that makes Filament ask for a signed,
                            // expiring URL instead of a plain one. Without it
                            // the component builds a public /storage link and
                            // the private disk answers 403 — every preview
                            // silently turns into a broken image.
                            ->visibility('private')
                            ->conversion(Transaction::THUMBNAIL)
                            ->multiple()
                            ->reorderable()
                            // Without this a second upload replaces the first
                            // set rather than adding to it.
                            ->appendFiles()
                            ->image()
                            ->imageEditor()
                            ->openable()
                            ->downloadable()
                            ->panelLayout('grid')
                            ->maxFiles(10)
                            ->maxSize(5 * 1024)
                            // Repeated from the collection deliberately: this
                            // rejects the file in the browser with a message,
                            // the collection rejects it server-side with an
                            // exception. Only one of the two is a good
                            // experience, and only one of the two is enforcement.
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('JPG, PNG atau WEBP. Maksimal 10 berkas, masing-masing 5 MB.'),
                    ]),
            ])
            ->columns(1);
    }
}

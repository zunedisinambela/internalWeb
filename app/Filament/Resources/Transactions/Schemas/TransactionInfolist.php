<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(3)
                    ->components([
                        TextEntry::make('type')
                            ->label('Jenis')
                            ->badge(),

                        // Formatted the same way as the table column, and for
                        // the same reason — see the note there.
                        TextEntry::make('amount')
                            ->label('Jumlah')
                            ->formatStateUsing(fn (int $state, Transaction $record): string => sprintf(
                                '%s Rp %s',
                                $record->type === TransactionType::Income ? '+' : '−',
                                number_format($state, 0, ',', '.'),
                            ))
                            ->color(fn (Transaction $record): string => $record->type->getColor())
                            ->weight('bold'),

                        TextEntry::make('occurred_at')
                            ->label('Tanggal dan waktu')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('description')
                            ->label('Keterangan')
                            ->columnSpanFull(),
                    ]),

                Section::make('Bukti')
                    ->components([
                        SpatieMediaLibraryImageEntry::make('receipts')
                            ->hiddenLabel()
                            ->collection(Transaction::RECEIPTS)
                            ->conversion(Transaction::THUMBNAIL)
                            ->disk('local')
                            // The private disk answers signed URLs only. See the
                            // note on the same call in TransactionForm.
                            ->visibility('private')
                            ->height(160)
                            ->placeholder('Tidak ada bukti terlampir'),
                    ]),

                Section::make('Pencatatan')
                    ->columns(3)
                    ->collapsed()
                    ->components([
                        TextEntry::make('user.name')
                            ->label('Dicatat oleh')
                            ->placeholder('Tidak diketahui'),

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

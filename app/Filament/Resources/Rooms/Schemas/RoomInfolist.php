<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kamar')
                    ->columns(3)
                    ->components([
                        TextEntry::make('name')
                            ->label('Nama kamar')
                            ->weight('bold'),

                        TextEntry::make('occupant')
                            ->label('Penghuni')
                            ->placeholder('Kosong'),

                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),

                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Meteran')
                    ->columns(2)
                    ->components([
                        // Read off the relation rather than stored, so it cannot
                        // fall out of step with the readings themselves.
                        TextEntry::make('last_reading')
                            ->label('Pencatatan terakhir')
                            ->placeholder('Belum ada pencatatan')
                            ->state(fn (Room $record): ?string => $record->latestReading()
                                ?->end_read_at->translatedFormat('d M Y H:i')),

                        TextEntry::make('last_end_kwh')
                            ->label('Angka meteran terakhir')
                            ->placeholder('—')
                            ->state(function (Room $record): ?string {
                                $reading = $record->latestReading();

                                return $reading === null
                                    ? null
                                    : number_format($reading->end_kwh, 0, ',', '.').' kWh';
                            })
                            ->helperText('Angka ini yang terisi otomatis sebagai kWh awal pencatatan berikutnya.'),
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

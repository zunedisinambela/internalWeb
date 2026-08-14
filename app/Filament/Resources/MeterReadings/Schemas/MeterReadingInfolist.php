<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Models\MeterReading;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MeterReadingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pencatatan meteran')
                    ->columns(3)
                    ->components([
                        TextEntry::make('room.name')
                            ->label('Kamar')
                            ->weight('bold'),

                        TextEntry::make('room.occupant')
                            ->label('Penghuni')
                            ->placeholder('Kosong'),

                        TextEntry::make('usage_kwh')
                            ->label('Pemakaian')
                            ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' kWh')
                            ->color(fn (MeterReading $record): ?string => $record->usage_kwh < 0 ? 'danger' : null)
                            ->weight('bold'),

                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                // The two ends laid out the way the form takes them, so the
                // screen that reads a reading back and the screen that records it
                // put the same photograph under the same number.
                Grid::make(2)
                    ->components([
                        Section::make('Pembacaan awal')
                            ->icon(Heroicon::OutlinedPlayCircle)
                            ->components([
                                TextEntry::make('start_read_at')
                                    ->label('Waktu')
                                    ->dateTime('d M Y H:i'),

                                TextEntry::make('start_kwh')
                                    ->label('kWh awal')
                                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' kWh')
                                    ->weight('bold'),

                                self::photos('photos_start', MeterReading::PHOTOS_START),
                            ]),

                        Section::make('Pembacaan akhir')
                            ->icon(Heroicon::OutlinedStopCircle)
                            ->components([
                                TextEntry::make('end_read_at')
                                    ->label('Waktu')
                                    ->dateTime('d M Y H:i'),

                                TextEntry::make('end_kwh')
                                    ->label('kWh akhir')
                                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' kWh')
                                    ->weight('bold'),

                                self::photos('photos_end', MeterReading::PHOTOS_END),
                            ]),
                    ]),

                // The rate is deliberately absent here, the way it is absent from
                // both form screens: it is not a figure decided at the meter, and
                // showing it beside the total invites reading the bill as a sum
                // that could be recomputed from today's tariff. The snapshot on
                // the row is still what produced this figure — it is reachable as
                // a toggleable column on the list, and every change to it is in
                // the activity log.
                Section::make('Tagihan')
                    ->components([
                        TextEntry::make('total_amount')
                            ->label('Total tagihan')
                            ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                            ->color(fn (MeterReading $record): string => $record->total_amount < 0 ? 'danger' : 'success')
                            ->weight('bold')
                            ->size('lg'),
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

    /**
     * One photo strip per collection. Same reason it is a helper on the form and
     * the table: ->visibility('private') missing from one copy is a silently
     * broken image, not an error.
     */
    private static function photos(string $name, string $collection): SpatieMediaLibraryImageEntry
    {
        return SpatieMediaLibraryImageEntry::make($name)
            ->label('Foto meteran')
            ->collection($collection)
            ->conversion(MeterReading::THUMBNAIL)
            ->disk('local')
            // The private disk answers signed URLs only. See the note on the same
            // call in MeterReadingForm.
            ->visibility('private')
            ->height(160)
            ->placeholder('Tidak ada foto terlampir');
    }
}

<?php

namespace App\Filament\Resources\MeterReadings\Tables;

use App\Models\MeterReading;
use App\Models\Room;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeterReadingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Newest first, like the cash book: a meter log is read from the
            // most recent entry back. Sorted on the closing moment, which is what
            // dates the period — see MeterReading::previousFor().
            ->defaultSort('end_read_at', 'desc')
            ->columns([
                TextColumn::make('end_read_at')
                    ->label('Dibaca')
                    // Stored in WIB already, so no timezone conversion is set
                    // here. translatedFormat() gives Indonesian month names
                    // because AppServiceProvider sets Carbon's locale.
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    // The opening moment as the description rather than as a
                    // second visible column: the pair is one period, and reading
                    // it takes both halves next to each other.
                    ->description(fn (MeterReading $record): string => 'dari '
                        .$record->start_read_at->translatedFormat('d M Y H:i')),

                TextColumn::make('start_read_at')
                    ->label('Pembacaan awal')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('room.name')
                    ->label('Kamar')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (MeterReading $record): ?string => $record->room?->occupant),

                TextColumn::make('start_kwh')
                    ->label('kWh awal')
                    ->alignEnd()
                    ->numeric(thousandsSeparator: '.')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('end_kwh')
                    ->label('kWh akhir')
                    ->alignEnd()
                    ->numeric(thousandsSeparator: '.')
                    ->sortable()
                    ->toggleable(),

                // Derived from the two columns beside it rather than stored, so
                // it cannot disagree with them. Sorting has to be spelled out for
                // the same reason — there is no column to order by, and letting
                // Filament guess would silently sort on nothing.
                TextColumn::make('usage_kwh')
                    ->label('Pemakaian')
                    ->alignEnd()
                    ->state(fn (MeterReading $record): string => number_format($record->usage_kwh, 0, ',', '.').' kWh')
                    ->color(fn (MeterReading $record): ?string => $record->usage_kwh < 0 ? 'danger' : null)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("(end_kwh - start_kwh) {$direction}")),

                TextColumn::make('rate')
                    ->label('Tarif')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_amount')
                    ->label('Tagihan')
                    ->alignEnd()
                    ->state(fn (MeterReading $record): string => 'Rp '.number_format($record->total_amount, 0, ',', '.'))
                    ->color(fn (MeterReading $record): string => $record->total_amount < 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("((end_kwh - start_kwh) * rate) {$direction}")),

                self::photos('photos_start', MeterReading::PHOTOS_START, 'Foto awal'),
                self::photos('photos_end', MeterReading::PHOTOS_END, 'Foto akhir'),

                TextColumn::make('user.name')
                    ->label('Dicatat oleh')
                    ->placeholder('Tidak diketahui')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('room_id')
                    ->label('Kamar')
                    ->relationship('room', 'name', fn (Builder $query): Builder => $query->orderBy('name'))
                    ->searchable()
                    ->preload(),

                // Matched on the closing moment only, not on either end. A period
                // that straddles the boundary belongs to the month it closed in,
                // which is the month it is billed in; matching both ends would
                // return a reading twice for two adjacent filters.
                Filter::make('end_read_at')
                    ->label('Rentang tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari')->native(false),
                        DatePicker::make('until')->label('Sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('end_read_at', '>=', $date))
                        // whereDate on the upper bound, not whereBetween on a
                        // datetime: an "until" of 20 Aug must include everything
                        // read on the 20th, not stop at 00:00 that day.
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('end_read_at', '<=', $date)))
                    ->indicateUsing(function (array $data): ?string {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        return match (true) {
                            $from && $until => "Tanggal: {$from} sampai {$until}",
                            (bool) $from => "Sejak {$from}",
                            (bool) $until => "Sampai {$until}",
                            default => null,
                        };
                    }),

                TernaryFilter::make('photos')
                    ->label('Foto')
                    ->placeholder('Semua')
                    ->trueLabel('Ada foto')
                    ->falseLabel('Tanpa foto')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('media'),
                        false: fn (Builder $query): Builder => $query->doesntHave('media'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned to per-record deletes. The single-query bulk path
                    // fires no model events, which would take the activity log
                    // entry and the photo files down with it — the rows would go
                    // and the images would stay on disk with nothing pointing at
                    // them.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada pencatatan')
            ->emptyStateDescription(fn (): string => Room::query()->exists()
                ? 'Catat angka meteran kamar pertama Anda.'
                : 'Tambahkan kamar terlebih dahulu di menu Kamar.');
    }

    /**
     * One thumbnail stack per photo collection. Built here rather than typed
     * twice for the same reason as on the form: ->visibility('private') missing
     * from one copy renders a broken image and logs nothing.
     */
    private static function photos(string $name, string $collection, string $label): SpatieMediaLibraryImageColumn
    {
        return SpatieMediaLibraryImageColumn::make($name)
            ->label($label)
            ->collection($collection)
            ->conversion(MeterReading::THUMBNAIL)
            ->disk('local')
            // Same reason as on the form field: the private disk answers signed
            // URLs only, and without this the column asks for an unsigned one and
            // renders a broken image.
            ->visibility('private')
            ->circular()
            ->stacked()
            ->limit(3)
            ->limitedRemainingText();
    }
}

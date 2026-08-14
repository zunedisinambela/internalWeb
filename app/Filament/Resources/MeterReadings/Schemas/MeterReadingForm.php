<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Models\ElectricityTariff;
use App\Models\MeterReading;
use App\Models\Room;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class MeterReadingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Shown only when there is no tariff to copy. The form still
                // opens: `rate` is an ordinary field, so the reading can be
                // recorded with a rate typed by hand and the tariff screen filled
                // in afterwards. Refusing to open would be the tidier rule and
                // the worse one — the meter has already been read by then, and
                // the figure is on a phone screen that will be gone tomorrow.
                Callout::make('Tarif listrik belum ditetapkan')
                    ->description('Isi tarif per kWh di bawah secara manual, atau tetapkan dulu di menu Tarif Listrik agar terisi otomatis.')
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visible(static fn (): bool => ElectricityTariff::current() === null),

                Section::make(static fn (): string => self::showsRate() ? 'Kamar dan tarif' : 'Kamar')
                    ->columns(2)
                    ->components([
                        Select::make('room_id')
                            ->label('Kamar')
                            ->relationship('room', 'name', fn ($query) => $query->orderBy('name'))
                            // Inactive rooms stay selectable rather than being
                            // filtered out. A reading is often entered after a
                            // tenant leaves and the room is closed, and a filter
                            // would leave the edit screen for such a reading with
                            // an empty select and no explanation.
                            ->getOptionLabelFromRecordUsing(fn (Room $record): string => $record->is_active
                                ? $record->name
                                : $record->name.' (tidak aktif)')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(static function (Set $set, Get $get, mixed $state, ?Model $record): void {
                                if (blank($state)) {
                                    return;
                                }

                                // The opening figure is the closing figure of the
                                // previous reading, and the opening moment is the
                                // moment that one closed — that is what makes the
                                // two ends a continuous meter rather than four
                                // unrelated fields.
                                //
                                // Prefilled, not locked: a meter that was replaced
                                // starts again from zero, and only the person
                                // holding the photograph knows that happened.
                                $previous = MeterReading::previousFor(
                                    (int) $state,
                                    $get('end_read_at') ? new \DateTimeImmutable($get('end_read_at')) : null,
                                    $record?->getKey(),
                                );

                                $set('start_kwh', $previous?->end_kwh ?? 0);

                                // Only when there is one. Overwriting the default
                                // with null on a room's first reading would empty
                                // a required field and say nothing about why.
                                if ($previous !== null) {
                                    // Formatted the way the picker itself keeps
                                    // state — it is ->seconds(false), so a value
                                    // carrying seconds is a shape the field never
                                    // produces on its own.
                                    $set('start_read_at', $previous->end_read_at->format('Y-m-d H:i'));
                                }
                            })
                            ->helperText('Pembacaan awal terisi otomatis dari pencatatan terakhir kamar ini.'),

                        TextInput::make('rate')
                            ->label('Tarif per kWh')
                            ->prefix('Rp')
                            ->suffix('/kWh')
                            // Hidden while recording, because the rate is not a
                            // decision taken at the meter: it is set once on the
                            // tariff screen and copied here. Asking for it again
                            // on every reading invites a typo into the one figure
                            // the tenant is billed by.
                            //
                            // On the edit screen too, deliberately. The cost is
                            // that a rate typed wrong is no longer correctable
                            // from the panel — it has to be fixed from tinker, or
                            // by removing the reading and recording it again. That
                            // trade was made knowingly: entering a reading is a
                            // frequent act and correcting a rate is a rare one, so
                            // the field earns its place on neither screen.
                            //
                            // It appears on exactly one path: no tariff exists, so
                            // there is nothing to copy and the column is NOT NULL.
                            // Hiding it there would refuse the save with a message
                            // naming a field nobody can see.
                            ->visible(static fn (): bool => self::showsRate())
                            // Load-bearing, and silent when missing. Filament does
                            // not dehydrate a hidden component — isDehydrated()
                            // returns false through isHiddenAndNotDehydratedWhenHidden()
                            // — so without this the rate never reaches the row and
                            // the snapshot the whole feature rests on is gone.
                            ->dehydratedWhenHidden()
                            // Neither ->numeric() nor ->integer(), so the field
                            // can show a grouped figure — see the note on the
                            // transaction amount for why those two are mutually
                            // exclusive with a thousands separator.
                            ->inputMode('numeric')
                            ->maxLength(9)
                            ->required()
                            ->rule(new WholeRupiah(min: 1, max: 100_000))
                            // Only on create. Reopening an old reading shows the
                            // rate stored on that row, which is the whole point
                            // of copying it — a raise must not reach backwards
                            // into a bill that was already read off the meter.
                            ->default(fn (): ?int => ElectricityTariff::currentRate())
                            ->live(onBlur: true)
                            ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                if (! WholeRupiah::isUnambiguous($state)) {
                                    return;
                                }

                                $set('rate', WholeRupiah::format($state));
                            })
                            ->formatStateUsing(static fn (mixed $state): ?string => WholeRupiah::format($state))
                            ->dehydrateStateUsing(static fn (mixed $state): ?int => WholeRupiah::toInteger($state))
                            // Not a closure any more: the field is only ever on
                            // screen when there is no tariff, so the "copied from
                            // the tariff in force" wording it used to carry became
                            // unreachable the moment it was hidden on both paths.
                            ->helperText('Belum ada tarif tersimpan. Isi manual, atau tetapkan dulu di menu Tarif Listrik agar terisi otomatis.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Misalnya "meteran diganti" atau "angka sulit dibaca".'),
                    ]),

                // The two ends of the period, side by side, each holding its own
                // figure, its own moment and its own photographs. Grouping them
                // this way rather than listing six fields flat is what makes the
                // pairing legible: the photograph sits under the number it is
                // evidence for, so uploading it against the wrong end takes a
                // deliberate mistake rather than a careless one.
                Grid::make(2)
                    ->components([
                        Section::make('Pembacaan awal')
                            ->description('Angka meteran saat periode dibuka.')
                            ->icon(Heroicon::OutlinedPlayCircle)
                            ->components([
                                DateTimePicker::make('start_read_at')
                                    ->label('Waktu pembacaan awal')
                                    ->default(now())
                                    ->seconds(false)
                                    ->displayFormat('d M Y H:i')
                                    ->native(false)
                                    ->required()
                                    ->maxDate(now()->addDay())
                                    // now() is already WIB — APP_TIMEZONE is
                                    // Asia/Jakarta and timestamps are stored in
                                    // local time, so nothing is converted here or
                                    // anywhere downstream.
                                    ->helperText('Terisi dari pembacaan akhir sebelumnya bila ada.'),

                                TextInput::make('start_kwh')
                                    ->label('kWh awal')
                                    ->suffix('kWh')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText('Angka meteran di awal periode.'),

                                self::photos(
                                    'photos_start',
                                    MeterReading::PHOTOS_START,
                                    'Foto meteran saat angka awal dibaca.',
                                ),
                            ]),

                        Section::make('Pembacaan akhir')
                            ->description('Angka meteran saat periode ditutup.')
                            ->icon(Heroicon::OutlinedStopCircle)
                            ->components([
                                DateTimePicker::make('end_read_at')
                                    ->label('Waktu pembacaan akhir')
                                    ->default(now())
                                    ->seconds(false)
                                    ->displayFormat('d M Y H:i')
                                    ->native(false)
                                    ->required()
                                    ->maxDate(now()->addDay())
                                    // A period that closes before it opens is not
                                    // a meter problem, it is a typo — and it would
                                    // sort the row into the wrong place forever,
                                    // since end_read_at is what the list, the
                                    // filter and previousFor() all read.
                                    //
                                    // OrEqual rather than strictly after: both
                                    // figures read in one visit to a room that was
                                    // just occupied is a real case, and the
                                    // minute-precision picker cannot tell it apart
                                    // from a mistake anyway.
                                    ->afterOrEqual('start_read_at')
                                    ->validationMessages([
                                        'after_or_equal' => 'Waktu pembacaan akhir tidak boleh mendahului waktu pembacaan awal.',
                                    ])
                                    ->helperText('Terisi waktu sekarang. Ubah bila meteran dibaca di lain waktu.'),

                                TextInput::make('end_kwh')
                                    ->label('kWh akhir')
                                    ->suffix('kWh')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    // A closing figure below the opening one is a
                                    // typo or a replaced meter, and the two need
                                    // different handling. Refusing it here means
                                    // the second case has to be entered
                                    // deliberately — with the opening figure set
                                    // to the new meter's own starting number —
                                    // rather than silently producing a negative
                                    // bill.
                                    ->gte('start_kwh')
                                    ->validationMessages([
                                        'gte' => 'kWh akhir tidak boleh lebih kecil dari kWh awal. Bila meteran diganti, isi kWh awal dengan angka awal meteran baru.',
                                    ])
                                    ->helperText('Angka meteran di akhir periode.'),

                                self::photos(
                                    'photos_end',
                                    MeterReading::PHOTOS_END,
                                    'Foto meteran saat angka akhir dibaca.',
                                ),
                            ]),
                    ]),

                Section::make('Perhitungan')
                    ->columns(2)
                    ->components([
                        // Computed here for the screen only; nothing is stored.
                        // The stored columns are the two meter figures and the
                        // rate, and everything else is derived from them — a
                        // stored total would be a fourth number that can disagree
                        // with the three it came from.
                        TextEntry::make('usage_preview')
                            ->label('Pemakaian')
                            ->state(static fn (Get $get): string => number_format(self::usage($get), 0, ',', '.').' kWh')
                            ->weight('bold'),

                        TextEntry::make('total_preview')
                            ->label('Total tagihan')
                            ->state(static fn (Get $get): string => 'Rp '.number_format(self::total($get), 0, ',', '.'))
                            ->weight('bold')
                            ->color(static fn (Get $get): string => self::usage($get) < 0 ? 'danger' : 'success'),
                    ]),

            ])
            ->columns(1);
    }

    /**
     * Whether the rate field is on screen at all — on create and on edit alike.
     *
     * One condition: there is no tariff to copy. The rate is set on the tariff
     * screen and copied here, so it is never asked for while recording; the only
     * time it has to be typed is when there is nothing to copy from and the
     * column is NOT NULL.
     *
     * Two callers — the field's own ->visible() and the section heading — so the
     * rule lives in one place. A heading reading "Kamar dan tarif" above a
     * section with no tariff in it is the kind of drift that survives review.
     */
    private static function showsRate(): bool
    {
        return ElectricityTariff::current() === null;
    }

    /**
     * One upload field bound to one photo collection.
     *
     * Both ends take identical rules, so they are built here rather than typed
     * twice — two copies would be two places for the private-disk flags to drift
     * apart, and the failure that causes is a silently broken image rather than
     * an error.
     */
    private static function photos(string $name, string $collection, string $helperText): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($name)
            ->label('Foto meteran')
            ->collection($collection)
            ->disk('local')
            // Makes Filament ask for a signed, expiring URL. The private disk
            // refuses an unsigned one before it even looks for the file, so
            // without this every preview silently becomes a broken image with
            // nothing in the log.
            ->visibility('private')
            ->conversion(MeterReading::THUMBNAIL)
            ->multiple()
            ->reorderable()
            // Without this a second upload replaces the first set rather than
            // adding to it.
            ->appendFiles()
            ->image()
            ->imageEditor()
            ->openable()
            ->downloadable()
            ->panelLayout('grid')
            ->maxFiles(5)
            ->maxSize(5 * 1024)
            // Repeated from the collection deliberately: this rejects the file in
            // the browser with a message, the collection rejects it server-side
            // with an exception. Only one of the two is a good experience, and
            // only one of the two is enforcement.
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->helperText($helperText.' JPG, PNG atau WEBP, maksimal 5 berkas @ 5 MB.');
    }

    /**
     * kWh between the two figures as the form currently stands.
     *
     * Not clamped at zero, for the same reason MeterReading::usage_kwh is not:
     * while the closing figure is still below the opening one the preview should
     * say so in red, not show a plausible Rp 0.
     */
    private static function usage(Get $get): int
    {
        return (int) $get('end_kwh') - (int) $get('start_kwh');
    }

    private static function total(Get $get): int
    {
        return self::usage($get) * (int) WholeRupiah::toInteger($get('rate'));
    }
}

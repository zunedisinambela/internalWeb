<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Models\MeterReading;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * One screen, read top to bottom: the two ends of the period, then the price,
 * then what is owed.
 *
 * The room select and the "no tariff has been set" warning that used to open
 * this form are gone with the screens behind them. What replaced them is the
 * simplest thing that keeps the same guarantee: everything the bill is computed
 * from is typed or defaulted *here*, on the row it belongs to, so no screen
 * elsewhere can change what a recorded period costs.
 */
class MeterReadingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                                    // Continues the previous period rather than
                                    // starting a fresh one at now(): the moment
                                    // the last reading closed is the moment this
                                    // one opens, which is what makes the log one
                                    // continuous meter rather than a pile of
                                    // unrelated periods.
                                    //
                                    // Formatted the way the picker keeps state —
                                    // it is ->seconds(false), so a value carrying
                                    // seconds is a shape the field never produces
                                    // on its own and every assertion written
                                    // against the field would disagree with it.
                                    ->default(fn (): string => MeterReading::previous()
                                        ?->end_read_at->format('Y-m-d H:i')
                                        ?? now()->format('Y-m-d H:i'))
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
                                    // Prefilled, not locked: a meter that was
                                    // replaced starts again from zero, and only
                                    // the person holding the photograph knows
                                    // that happened.
                                    ->default(fn (): int => MeterReading::previous()?->end_kwh ?? 0)
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText('Terisi dari kWh akhir sebelumnya. Ubah bila meteran diganti.'),

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
                                    // filter and previous() all read.
                                    //
                                    // OrEqual rather than strictly after: both
                                    // figures read in one visit is a real case,
                                    // and the minute-precision picker cannot tell
                                    // it apart from a mistake anyway.
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

                Section::make('Tarif')
                    ->description('Harga per kWh yang berlaku untuk periode ini.')
                    ->icon(Heroicon::OutlinedBolt)
                    ->columns(2)
                    ->components([
                        TextInput::make('rate')
                            ->label('Tarif per kWh')
                            ->prefix('Rp')
                            ->suffix('/kWh')
                            // Asked for on every reading now, rather than copied
                            // from a tariff screen that no longer exists. The
                            // figure still lands on this row and is still what
                            // the bill is computed from, so a price that changes
                            // next month cannot reach backwards into this period
                            // — that was the point of the copy, and it survives
                            // the screen it used to be copied from.
                            //
                            // Only ever *defaulted* from the previous reading.
                            // Recomputing it on save would be the repricing the
                            // whole design refuses.
                            ->default(fn (): ?int => self::defaultRate())
                            // Neither ->numeric() nor ->integer(), so the field
                            // can show a grouped figure — the two are mutually
                            // exclusive with a thousands separator, because both
                            // force type="number" and a number input cannot
                            // display "1.500".
                            ->inputMode('numeric')
                            ->maxLength(9)
                            ->required()
                            ->rule(new WholeRupiah(min: 1, max: 100_000))
                            ->live(onBlur: true)
                            ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                if (! WholeRupiah::isUnambiguous($state)) {
                                    return;
                                }

                                $set('rate', WholeRupiah::format($state));
                            })
                            ->formatStateUsing(static fn (mixed $state): ?string => WholeRupiah::format($state))
                            ->dehydrateStateUsing(static fn (mixed $state): ?int => WholeRupiah::toInteger($state))
                            ->helperText('Terisi dari pencatatan sebelumnya. Ubah bila tarif naik.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->helperText('Misalnya "meteran diganti" atau "angka sulit dibaca".'),
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
     * What the rate field opens at on a new reading.
     *
     * Carried forward from the previous reading, which is the only figure this
     * app still knows: the tariff table that used to answer this was removed
     * with the landlord-shaped screens. A tariff that has not moved is then one
     * fewer thing to type each month, and one that has moved is a field already
     * on screen waiting to be corrected.
     *
     * Null on the very first reading, and deliberately not a made-up default —
     * `rate` is NOT NULL and required, so the form asks rather than guessing a
     * number that would go straight onto a bill.
     */
    private static function defaultRate(): ?int
    {
        return MeterReading::previous()?->rate;
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

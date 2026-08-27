<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Filament\Forms\Components\RupiahInput;
use App\Models\MeterReading;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * One screen, read top to bottom: the two ends of the period, then what was
 * paid for it.
 *
 * The room select and the "no tariff has been set" warning that used to open
 * this form are gone with the screens behind them, and so is the price per kWh
 * that replaced them. Nothing on this form is a factor in a calculation any
 * more: the two meter figures are evidence of how much was used, and the amount
 * is what the bill said. That is the strongest version of the guarantee the
 * tariff table was removed to get — a recorded period cannot be repriced by any
 * screen anywhere, because nothing recomputes it.
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

                Section::make('Tagihan')
                    ->description('Jumlah yang harus dibayar untuk periode ini.')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(3)
                    ->components([
                        // Read-only, and the only arithmetic left on the screen.
                        // It is here rather than in a section of its own because
                        // it is what the amount beside it is checked against: a
                        // bill that jumped while the meter barely moved is
                        // visible in one glance, and neither figure is derived
                        // from the other any more, so the two can genuinely
                        // disagree.
                        TextEntry::make('usage_preview')
                            ->label('Pemakaian')
                            ->state(static fn (Get $get): string => number_format(self::usage($get), 0, ',', '.').' kWh')
                            ->color(static fn (Get $get): ?string => self::usage($get) < 0 ? 'danger' : null)
                            ->weight('bold'),

                        // Typed off the bill, not computed. There is deliberately
                        // no ->default() carrying the previous reading's amount
                        // forward the way the rate used to be: a price repeats
                        // month to month and an amount does not, so a prefill
                        // here would be a plausible wrong number sitting in the
                        // one field nobody can check against anything else.
                        RupiahInput::make('total_amount')
                            ->label('Total tagihan')
                            ->required()
                            // A period where the meter did not move really does
                            // cost nothing, and refusing Rp 0 with "Jumlah harus
                            // rupiah penuh" would describe the wrong problem.
                            // ->required() is still what catches an empty field;
                            // a zero has to be typed.
                            ->allowingZero()
                            // Narrows the component's own rule rather than
                            // replacing it — Filament appends, so both apply and
                            // a value has to satisfy each. The ceiling is what
                            // catches the typo this field is most exposed to: one
                            // extra zero on a figure nothing else on the row can
                            // contradict.
                            ->rule(new WholeRupiah(min: 0, max: 50_000_000))
                            ->helperText('Sesuai tagihan yang diterima untuk periode ini.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->helperText('Misalnya "meteran diganti" atau "angka sulit dibaca".'),
                    ]),

            ])
            ->columns(1);
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
     * say so in red rather than reading as a period of no consumption.
     */
    private static function usage(Get $get): int
    {
        return (int) $get('end_kwh') - (int) $get('start_kwh');
    }
}

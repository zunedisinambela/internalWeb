<?php

namespace App\Filament\Resources\ElectricityTariffs\Schemas;

use App\Models\ElectricityTariff;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ElectricityTariffForm
{
    /**
     * A rate above this is almost certainly a slipped digit. PLN's household
     * rates sit near Rp 1.500/kWh and a kost markup lands under Rp 3.000, so
     * Rp 100.000 is far outside anything real while still leaving room for
     * inflation nobody has to come back and edit this file for.
     */
    private const MAX_RATE = 100_000;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tarif')
                    ->description('Menaikkan tarif berarti menambah baris baru, bukan mengubah yang lama. Tagihan yang sudah dicatat memakai tarif yang berlaku saat itu dan tidak ikut berubah.')
                    ->columns(2)
                    ->components([
                        TextInput::make('rate')
                            ->label('Tarif per kWh')
                            ->prefix('Rp')
                            ->suffix('/kWh')
                            // Neither ->numeric() nor ->integer(), for the same
                            // reason as the transaction amount: both force
                            // type="number" through TextInput::getType(), and a
                            // number input will not display a thousands
                            // separator. The rules they would have registered
                            // live in WholeRupiah instead.
                            ->inputMode('numeric')
                            ->maxLength(9)
                            ->required()
                            ->rule(new WholeRupiah(min: 1, max: self::MAX_RATE))
                            ->live(onBlur: true)
                            ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                // Only regroups what can be read exactly one way.
                                // "1.500,75" is left as typed so the rule can
                                // refuse it rather than have it become 150.075.
                                if (! WholeRupiah::isUnambiguous($state)) {
                                    return;
                                }

                                $set('rate', WholeRupiah::format($state));
                            })
                            ->formatStateUsing(static fn (mixed $state): ?string => WholeRupiah::format($state))
                            ->dehydrateStateUsing(static fn (mixed $state): ?int => WholeRupiah::toInteger($state))
                            ->helperText('Rupiah penuh, tanpa sen.'),

                        DatePicker::make('effective_from')
                            ->label('Berlaku mulai')
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->required()
                            // Unique in the schema. Two tariffs on one day would
                            // make "which rate is in force" unanswerable, and the
                            // tiebreak would silently become insertion order.
                            ->unique(ignoreRecord: true)
                            ->helperText('Boleh diisi tanggal yang akan datang untuk menjadwalkan kenaikan.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Alasan perubahan, misalnya "menyesuaikan kenaikan PLN".'),
                    ]),

                // A banner rather than a disabled field: it carries no state,
                // must never be submitted, and saying so with ->dehydrated(false)
                // on an input is a way of asking a form control not to be one.
                Callout::make('Tarif yang berlaku sekarang')
                    ->description(static function (): string {
                        $current = ElectricityTariff::current();

                        return $current === null
                            ? 'Belum ada tarif yang ditetapkan, sehingga pencatatan meteran belum bisa dibuat.'
                            : 'Rp '.number_format($current->rate, 0, ',', '.').'/kWh, berlaku sejak '
                                .$current->effective_from->translatedFormat('d F Y').'.';
                    })
                    ->color(static fn (): string => ElectricityTariff::current() === null ? 'warning' : 'info')
                    ->icon(Heroicon::OutlinedBolt),
            ])
            ->columns(1);
    }
}

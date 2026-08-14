<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Forms\Components\RupiahInput;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Produk')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nama produk')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1)
                            ->helperText('Seperti tertulis di katalog.'),

                        TextInput::make('code')
                            ->label('Kode produk')
                            ->maxLength(30)
                            // Unique in the schema, so it has to be checked here
                            // too — otherwise the form hands back a constraint
                            // violation instead of a message under the field.
                            // ignoreRecord keeps an edit from colliding with
                            // itself.
                            ->unique(ignoreRecord: true)
                            ->helperText('Nomor di katalog Oriflame. Boleh dikosongkan.'),
                    ]),

                Section::make('Harga')
                    ->description('Harga katalog adalah yang dibayar pelanggan. Harga marketing adalah yang Anda bayar ke Oriflame.')
                    ->columns(3)
                    ->components([
                        RupiahInput::make('catalog_price')
                            ->label('Harga katalog')
                            ->required()
                            ->helperText('Harga di website resmi Oriflame.'),

                        RupiahInput::make('marketing_price')
                            ->label('Harga marketing')
                            ->required()
                            // A consultant price above the catalogue price is a
                            // loss on every unit, and in practice it is the two
                            // figures entered the wrong way round. Refusing it
                            // here means the rare genuine case — a clearance
                            // product sold below cost — has to be entered
                            // deliberately from tinker rather than arrived at by
                            // a slip of the keyboard.
                            //
                            // Not Laravel's ->lte(): both fields hold grouped
                            // strings while the form is open, and that rule
                            // would compare one of them by string length. See
                            // RupiahInput::notGreaterThan().
                            ->notGreaterThan(
                                'catalog_price',
                                'Harga marketing tidak boleh lebih besar dari harga katalog.',
                            )
                            ->helperText('Harga tebus Anda sebagai konsultan.'),

                        // Computed for the screen only; nothing is stored. The
                        // stored columns are the two prices, and the margin is
                        // derived from them — a stored margin would be a third
                        // number able to disagree with the two it came from.
                        TextEntry::make('unit_profit_preview')
                            ->label('Keuntungan per unit')
                            ->state(static fn (Get $get): string => 'Rp '.number_format(
                                self::unitProfit($get), 0, ',', '.',
                            ))
                            ->weight('bold')
                            ->color(static fn (Get $get): string => self::unitProfit($get) < 0
                                ? 'danger'
                                : 'success'),
                    ]),

                Section::make('Status')
                    ->columns(2)
                    ->components([
                        Toggle::make('is_active')
                            ->label('Produk aktif')
                            ->default(true)
                            ->helperText('Matikan untuk produk yang sudah tidak ada di katalog. Penjualan yang sudah tercatat tetap utuh.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * The margin as the form currently stands.
     *
     * Reads both fields back through WholeRupiah, because while the form is open
     * their state is the grouped string the user sees — "150.000", not 150000 —
     * and (int) on that would answer 150.
     *
     * Not clamped, for the same reason Product::$unit_profit is not: while the
     * marketing price is still above the catalogue price the preview should say
     * so in red rather than show a plausible Rp 0.
     */
    private static function unitProfit(Get $get): int
    {
        return (int) WholeRupiah::toInteger($get('catalog_price'))
            - (int) WholeRupiah::toInteger($get('marketing_price'));
    }
}

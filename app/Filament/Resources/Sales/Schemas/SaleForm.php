<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Filament\Forms\Components\RupiahInput;
use App\Models\Customer;
use App\Models\Product;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Penjualan')
                    ->columns(2)
                    ->components([
                        Select::make('customer_id')
                            ->label('Pelanggan')
                            ->relationship('customer', 'name', fn ($query) => $query->orderBy('name'))
                            // Inactive customers stay selectable rather than
                            // being filtered out, the same way inactive rooms do
                            // on the meter form: a sale is often written up after
                            // the fact, and a filter would leave the edit screen
                            // for such a sale with an empty select and no
                            // explanation.
                            ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->is_active
                                ? $record->name
                                : $record->name.' (tidak aktif)')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nama')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('phone')
                                    ->label('Nomor telepon')
                                    ->tel()
                                    ->maxLength(30),
                            ])
                            // A new customer usually arrives *with* their first
                            // order, so making that a trip to another screen and
                            // back would lose the half-typed sale.
                            ->createOptionModalHeading('Pelanggan baru'),

                        DateTimePicker::make('occurred_at')
                            ->label('Tanggal pembelian')
                            ->default(now())
                            ->seconds(false)
                            ->displayFormat('d M Y H:i')
                            ->native(false)
                            ->required()
                            ->maxDate(now()->addDay())
                            // now() is already WIB — APP_TIMEZONE is
                            // Asia/Jakarta and timestamps are stored in local
                            // time, so nothing is converted here or downstream.
                            ->helperText('Terisi waktu sekarang. Ubah bila pesanan masuk di lain waktu.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Misalnya "diantar ke kantor" atau "bayar minggu depan".'),
                    ]),

                Section::make('Produk yang dibeli')
                    ->description('Harga terisi otomatis dari katalog saat produk dipilih, lalu tersimpan sebagai salinan pada baris ini. Perubahan harga katalog nanti tidak mengubah penjualan yang sudah tercatat.')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->components([
                        Repeater::make('items')
                            ->hiddenLabel()
                            // Bound to the HasMany, so Filament writes the lines
                            // after the sale itself and reloads them on edit.
                            ->relationship()
                            // Table layout rather than stacked cards: a sale is
                            // read by scanning a column of figures, and six
                            // fields per card would put each line's prices on a
                            // different horizontal position from the last.
                            ->table([
                                TableColumn::make('Produk'),
                                TableColumn::make('Jumlah')->width('7rem')->alignEnd(),
                                TableColumn::make('Harga katalog')->width('12rem'),
                                TableColumn::make('Harga marketing')->width('12rem'),
                                TableColumn::make('Untung')->width('10rem')->alignEnd(),
                            ])
                            ->components([
                                Select::make('product_id')
                                    ->label('Produk')
                                    ->relationship('product', 'name', fn ($query) => $query->orderBy('name'))
                                    // Inactive products stay in the list for the
                                    // same reason inactive customers do — an old
                                    // sale has to stay openable — but they say so.
                                    ->getOptionLabelFromRecordUsing(fn (Product $record): string => $record->is_active
                                        ? $record->name
                                        : $record->name.' (tidak aktif)')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->distinct()
                                    // Two lines naming the same product are
                                    // almost always a double entry rather than an
                                    // intent; quantity is what expresses "three
                                    // of these".
                                    ->validationMessages([
                                        'distinct' => 'Produk ini sudah ada di daftar. Ubah jumlahnya saja.',
                                    ])
                                    ->live()
                                    ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                        $product = blank($state) ? null : Product::find($state);

                                        if ($product === null) {
                                            return;
                                        }

                                        // The copy. This is the moment the
                                        // snapshot is taken, and it is the whole
                                        // feature: from here the line carries its
                                        // own figures and never consults the
                                        // product again.
                                        //
                                        // Formatted, not raw — the fields hold a
                                        // grouped string while the form is open
                                        // and dehydrate back to integers on save.
                                        $set('catalog_price', WholeRupiah::format($product->catalog_price));
                                        $set('marketing_price', WholeRupiah::format($product->marketing_price));
                                    }),

                                TextInput::make('quantity')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true),

                                RupiahInput::make('catalog_price')
                                    ->label('Harga katalog')
                                    ->required(),

                                RupiahInput::make('marketing_price')
                                    ->label('Harga marketing')
                                    ->required()
                                    // Same reasoning as on the product form, and
                                    // the same reason it is not Laravel's ->lte():
                                    // these fields hold grouped strings during
                                    // validation. See RupiahInput::notGreaterThan().
                                    ->notGreaterThan(
                                        'catalog_price',
                                        'Harga marketing tidak boleh lebih besar dari harga katalog.',
                                    ),

                                // Per line, so a product entered at the wrong
                                // price shows up here rather than only in the
                                // total, where one wrong line among six is
                                // invisible.
                                TextEntry::make('line_profit')
                                    ->hiddenLabel()
                                    ->state(static fn (Get $get): string => 'Rp '.number_format(
                                        self::lineProfit($get), 0, ',', '.',
                                    ))
                                    ->weight('bold')
                                    ->color(static fn (Get $get): string => self::lineProfit($get) < 0
                                        ? 'danger'
                                        : 'success'),
                            ])
                            ->addActionLabel('Tambah produk')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->minItems(1),
                    ]),

                Section::make('Ringkasan')
                    ->columns(3)
                    ->components([
                        // Computed for the screen only; nothing here is stored.
                        // The stored figures are on the lines, and every total is
                        // a sum over them — a stored total would be a fourth
                        // number able to disagree with the three it came from.
                        TextEntry::make('catalog_total_preview')
                            ->label('Total harga katalog')
                            ->state(static fn (Get $get): string => 'Rp '.number_format(
                                self::totals($get)['catalog'], 0, ',', '.',
                            ))
                            ->helperText('Yang dibayar pelanggan.'),

                        TextEntry::make('marketing_total_preview')
                            ->label('Total harga marketing')
                            ->state(static fn (Get $get): string => 'Rp '.number_format(
                                self::totals($get)['marketing'], 0, ',', '.',
                            ))
                            ->helperText('Yang Anda bayar ke Oriflame.'),

                        TextEntry::make('profit_preview')
                            ->label('Keuntungan')
                            ->state(static fn (Get $get): string => 'Rp '.number_format(
                                self::totals($get)['profit'], 0, ',', '.',
                            ))
                            ->weight('bold')
                            ->size('lg')
                            ->color(static fn (Get $get): string => self::totals($get)['profit'] < 0
                                ? 'danger'
                                : 'success'),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * The margin on one repeater line as it currently stands.
     *
     * `$get` inside a repeater item is scoped to that item, so the bare field
     * names reach this line's own state rather than the first one's.
     */
    private static function lineProfit(Get $get): int
    {
        $quantity = (int) $get('quantity');

        return $quantity * (
            (int) WholeRupiah::toInteger($get('catalog_price'))
            - (int) WholeRupiah::toInteger($get('marketing_price'))
        );
    }

    /**
     * The three totals over every line, read from live form state.
     *
     * Both prices go through WholeRupiah::toInteger() because while the form is
     * open they are the grouped strings the user sees — "150.000", not 150000 —
     * and a bare (int) cast on that answers 150. The same trap the validation
     * rule avoids, arriving through arithmetic instead.
     *
     * This duplicates what Sale::$catalog_total and friends compute from saved
     * rows, and the duplication is unavoidable: those read integers off the
     * database, this reads strings out of an unsaved form. What keeps them in
     * step is that both are sums of `quantity × price` over the same lines, with
     * no rounding anywhere to disagree about.
     *
     * @return array{catalog: int, marketing: int, profit: int}
     */
    private static function totals(Get $get): array
    {
        $catalog = 0;
        $marketing = 0;

        foreach ($get('items') ?? [] as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            $catalog += $quantity * (int) WholeRupiah::toInteger($item['catalog_price'] ?? null);
            $marketing += $quantity * (int) WholeRupiah::toInteger($item['marketing_price'] ?? null);
        }

        return [
            'catalog' => $catalog,
            'marketing' => $marketing,
            'profit' => $catalog - $marketing,
        ];
    }
}

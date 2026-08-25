<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Filament\Forms\Components\RupiahInput;
use App\Models\Customer;
use App\Models\Sale;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

                        TextInput::make('quantity')
                            ->label('Jumlah produk')
                            ->required()
                            ->default(1)
                            // ->numeric() forces type="number", which is right
                            // here and wrong on the rupiah fields: a count needs
                            // no thousands separator, so there is nothing for the
                            // browser's number input to refuse. It also registers
                            // the integer and min rules that RupiahInput has to
                            // supply by hand.
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            // Live so the bonus below moves while the number is
                            // being typed. onBlur rather than on every keystroke,
                            // matching the rupiah fields — a round trip per digit
                            // makes the field feel heavy for no gain.
                            ->live(onBlur: true)
                            ->helperText(sprintf(
                                'Banyaknya barang dalam pesanan ini. Setiap %d barang dapat 1 gratis.',
                                Sale::FREE_ITEM_THRESHOLD,
                            )),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Misalnya "diantar ke kantor" atau "bayar minggu depan".'),
                    ]),

                Section::make('Harga')
                    ->description('Tiga angka yang menentukan keuntungan pesanan ini. Semuanya rupiah penuh, tanpa sen.')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(3)
                    ->components([
                        // Laid out in the order they are read off a note: what
                        // was paid, what the postage cost, what was charged.
                        RupiahInput::make('marketing_price')
                            ->label('Harga market')
                            ->required()
                            ->helperText('Yang Anda bayar ke Oriflame.')
                            // Not Laravel's ->lte(): these fields hold grouped
                            // strings during validation, and lte picks its
                            // comparison from is_numeric(), which reads
                            // "150.000" as a number and "1.500.000" as a string
                            // length. See RupiahInput::notGreaterThan().
                            ->notGreaterThan(
                                'catalog_price',
                                'Harga market tidak boleh lebih besar dari harga katalog.',
                            ),

                        RupiahInput::make('shipping_cost')
                            ->label('Ongkir')
                            ->required()
                            ->default(0)
                            // Zero is a real answer here rather than an empty
                            // field — most orders are handed over rather than
                            // posted — so the default WholeRupiah floor of 1 is
                            // lifted. See RupiahInput::allowingZero().
                            ->allowingZero()
                            ->helperText('Ongkos kirim yang Anda tanggung. Isi 0 bila diantar sendiri.'),

                        RupiahInput::make('catalog_price')
                            ->label('Harga katalog')
                            ->required()
                            ->helperText('Yang dibayar pelanggan.'),
                    ]),

                Section::make('Ringkasan')
                    ->columns(3)
                    ->components([
                        // Computed for the screen only; nothing here is stored.
                        // The margin is an accessor over the three columns above,
                        // so a stored copy would be a fourth number able to
                        // disagree with them.
                        TextEntry::make('total_cost_preview')
                            ->label('Total modal')
                            ->state(static fn (Get $get): string => self::rupiah(self::figures($get)['cost']))
                            ->helperText('Harga market ditambah ongkir.'),

                        TextEntry::make('profit_preview')
                            ->label('Keuntungan')
                            ->state(static fn (Get $get): string => self::rupiah(self::figures($get)['profit']))
                            ->weight('bold')
                            ->size('lg')
                            ->color(static fn (Get $get): string => self::figures($get)['profit'] < 0
                                ? 'danger'
                                : 'success'),

                        // The count question, kept away from the two money
                        // figures beside it on purpose: the free item carries no
                        // rupiah anywhere in this feature yet. Whether it is
                        // still paid for to Oriflame — and so whether it belongs
                        // in `marketing_price` — has not been decided, and
                        // folding a guess into the margin would put a number on
                        // screen that nobody entered.
                        TextEntry::make('free_quantity_preview')
                            ->label('Gratis')
                            ->state(static fn (Get $get): string => self::bonus($get).' barang')
                            ->weight('bold')
                            ->size('lg')
                            ->color(static fn (Get $get): string => self::bonus($get) > 0 ? 'success' : 'gray')
                            ->helperText(static fn (Get $get): string => self::bonusNote($get)),
                    ]),

                Section::make('Lampiran')
                    ->description('Bukti transfer dan resi disimpan terpisah, supaya sebuah berkas menyatakan sendiri ia bukti apa. Semuanya opsional.')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->columns(2)
                    ->components([
                        // Side by side rather than stacked, so the two drop zones
                        // are visibly different targets. Uploading a resi against
                        // the payment field then takes a deliberate mistake
                        // rather than a careless one — the same layout reasoning
                        // MeterReadingForm uses for its two ends.
                        self::attachments(
                            'payment_proofs',
                            Sale::PAYMENT_PROOFS,
                            'Bukti transfer',
                            'Tangkapan layar transfer dari pelanggan. Beberapa berkas bila dibayar dicicil.',
                        ),

                        self::attachments(
                            'shipping_proofs',
                            Sale::SHIPPING_PROOFS,
                            'Resi pengiriman',
                            'Resi dari kurir. Beberapa berkas bila dikirim dalam beberapa paket.',
                        ),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * One upload field bound to one collection.
     *
     * Written once and called twice rather than typed out per collection, for
     * the reason MeterReadingForm gives: the flag that matters most on these is
     * ->visibility('private'), and its absence produces a broken image with
     * nothing in the log. A second copy is a second chance to lose it silently.
     */
    private static function attachments(string $name, string $collection, string $label, string $helperText): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($name)
            ->label($label)
            ->collection($collection)
            ->disk('local')
            // Makes Filament ask for a signed, expiring URL. The private disk
            // refuses an unsigned one before it even looks for the file, so
            // without this every preview silently becomes a broken image with
            // nothing in the log.
            ->visibility('private')
            ->conversion(Sale::THUMBNAIL)
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
     * The modal and the margin as the form currently stands.
     *
     * Every figure goes through WholeRupiah::toInteger() because while the form
     * is open they are the grouped strings the user sees — "150.000", not
     * 150000 — and a bare (int) cast on that answers 150. The same trap the
     * validation rule avoids, arriving through arithmetic instead.
     *
     * This duplicates what Sale::$total_cost and Sale::$profit compute from a
     * saved row, and the duplication is unavoidable: those read integers off the
     * database, this reads strings out of an unsaved form. What keeps them in
     * step is that both are the same two subtractions with no rounding anywhere
     * to disagree about.
     *
     * @return array{cost: int, profit: int}
     */
    private static function figures(Get $get): array
    {
        $marketing = (int) WholeRupiah::toInteger($get('marketing_price'));
        $shipping = (int) WholeRupiah::toInteger($get('shipping_cost'));
        $catalog = (int) WholeRupiah::toInteger($get('catalog_price'));

        $cost = $marketing + $shipping;

        return [
            'cost' => $cost,
            'profit' => $catalog - $cost,
        ];
    }

    /**
     * The bonus as the form currently stands.
     *
     * Reads the same intdiv() Sale::$free_quantity does, for the same reason
     * figures() repeats the margin: that accessor reads an integer off a saved
     * row, this reads whatever is in an unsaved form. Both are one division with
     * no rounding to disagree about.
     */
    private static function bonus(Get $get): int
    {
        return intdiv(max(0, (int) $get('quantity')), Sale::FREE_ITEM_THRESHOLD);
    }

    /**
     * What to say underneath the bonus.
     *
     * Showing "0 barang" and nothing else answers the question asked but not the
     * one meant: somebody typing 18 wants to know they are two short, and that
     * is exactly the moment a consultant would offer to round the order up. So
     * the distance to the next free item is named whenever there is one.
     */
    private static function bonusNote(Get $get): string
    {
        $quantity = max(0, (int) $get('quantity'));
        $remaining = Sale::FREE_ITEM_THRESHOLD - ($quantity % Sale::FREE_ITEM_THRESHOLD);

        if ($quantity < 1) {
            return 'Isi jumlah produk terlebih dahulu.';
        }

        return sprintf('Kurang %d barang lagi untuk gratis berikutnya.', $remaining);
    }

    /**
     * Grouped the Indonesian way, whole rupiah — not ->money('IDR'), which
     * renders two decimal places unless told otherwise. See Keuangan.
     */
    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}

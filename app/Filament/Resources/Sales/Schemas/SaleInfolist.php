<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Models\Sale;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Penjualan')
                    ->columns(3)
                    ->components([
                        TextEntry::make('customer.name')
                            ->label('Pelanggan')
                            ->weight('bold'),

                        TextEntry::make('occurred_at')
                            ->label('Tanggal pembelian')
                            ->dateTime('d M Y H:i'),

                        // The count alone. The bonus this feeds is counted per
                        // customer, not per order — two orders of ten earn a
                        // free item between them and nothing on either row — so
                        // a per-sale figure here would be a second, smaller
                        // answer to the same question on the screen least able
                        // to explain the difference. It lives on the customer,
                        // beside the total it is divided from.
                        TextEntry::make('quantity')
                            ->label('Jumlah produk')
                            ->state(fn (Sale $record): string => sprintf('%d barang', $record->quantity))
                            ->helperText(sprintf(
                                'Bonus 1 gratis per %d barang dihitung dari total belanja pelanggan.',
                                Sale::FREE_ITEM_THRESHOLD,
                            )),

                        TextEntry::make('user.name')
                            ->label('Dicatat oleh')
                            ->placeholder('Tidak diketahui'),

                        TextEntry::make('note')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Harga')
                    ->columns(3)
                    ->components([
                        TextEntry::make('marketing_price')
                            ->label('Harga market')
                            ->state(fn (Sale $record): string => self::rupiah($record->marketing_price))
                            ->helperText('Dibayar ke Oriflame.'),

                        TextEntry::make('shipping_cost')
                            ->label('Ongkir')
                            ->state(fn (Sale $record): string => self::rupiah($record->shipping_cost))
                            ->helperText('Ditanggung Anda, bukan ditagih ke pelanggan.'),

                        TextEntry::make('catalog_price')
                            ->label('Harga katalog')
                            ->state(fn (Sale $record): string => self::rupiah($record->catalog_price))
                            ->helperText('Dibayar pelanggan.'),
                    ]),

                Section::make('Ringkasan')
                    ->columns(2)
                    ->components([
                        TextEntry::make('total_cost')
                            ->label('Total modal')
                            ->state(fn (Sale $record): string => self::rupiah($record->total_cost))
                            ->helperText('Harga market ditambah ongkir.'),

                        TextEntry::make('profit')
                            ->label('Keuntungan')
                            ->state(fn (Sale $record): string => self::rupiah($record->profit))
                            ->weight('bold')
                            ->size('lg')
                            ->color(fn (Sale $record): string => $record->profit < 0 ? 'danger' : 'success'),
                    ]),

                Section::make('Lampiran')
                    ->columns(2)
                    ->components([
                        self::attachments('payment_proofs', Sale::PAYMENT_PROOFS, 'Bukti transfer'),
                        self::attachments('shipping_proofs', Sale::SHIPPING_PROOFS, 'Resi pengiriman'),
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

    /**
     * One image entry bound to one collection.
     *
     * Built from a shared factory for the reason MeterReadingInfolist gives:
     * ->visibility('private') missing from one of two copies renders a broken
     * image and logs nothing.
     */
    private static function attachments(string $name, string $collection, string $label): SpatieMediaLibraryImageEntry
    {
        return SpatieMediaLibraryImageEntry::make($name)
            ->label($label)
            ->collection($collection)
            ->conversion(Sale::THUMBNAIL)
            ->disk('local')
            // The private disk answers signed URLs only. See the note on the same
            // call in SaleForm.
            ->visibility('private')
            ->height(160)
            ->placeholder('Tidak ada berkas terlampir')
            // Marks the wrapper the lightbox script attaches to. Every <a>
            // inside it becomes one slide, so a payment split across two
            // transfers is paged through rather than opened one at a time.
            //
            // The collection name is the group key rather than a shared
            // constant: the two entries sit side by side in one Section, and a
            // single key would page from a transfer receipt straight into a
            // courier resi — which is exactly the pairing two collections exist
            // to keep apart.
            ->extraAttributes(['data-lightbox' => $collection])
            // A closure taking `state` is what makes the URL per-image:
            // CanOpenUrl::hasStateBasedUrls() looks for that parameter by name,
            // and without it every thumbnail would link to the same file. The
            // state is the media uuid.
            ->url(fn (?string $state, Sale $record): ?string => self::attachmentUrl($state, $record));
    }

    /**
     * A signed, expiring link to one attachment's original file.
     *
     * The entry renders the `thumb` conversion, which is downscaled — zooming
     * into that defeats the point, and a resi is read for its tracking number.
     * So this returns the original, which is also the EXIF-bearing copy:
     * reaching it is meant to take a deliberate signed request, which a click
     * is. See the Media section of CLAUDE.md.
     *
     * The expiry mirrors SpatieMediaLibraryImageEntry::getImageUrl() rather than
     * inventing its own, so a thumbnail and the file behind it stop working at
     * the same moment instead of one outliving the other.
     *
     * The uuid is matched against the whole media relation rather than one
     * collection: uuids are unique per row, and the entry only ever hands over
     * a uuid it rendered itself.
     */
    private static function attachmentUrl(?string $uuid, Sale $record): ?string
    {
        if (blank($uuid)) {
            return null;
        }

        /** @var ?Media $media */
        $media = $record->getRelationValue('media')
            ->first(fn (Media $media): bool => $media->uuid === $uuid);

        if (! $media) {
            return null;
        }

        try {
            return $media->getTemporaryUrl(
                now()->addMinutes(config('filament.temporary_file_url_expiry_minutes', 30))->endOfHour(),
            );
        } catch (Throwable) {
            // The disk cannot sign URLs. Falling through to the plain URL keeps
            // the link working on a public disk; on the private one it is
            // refused by ServeFile, which is the correct outcome.
            return $media->getAvailableUrl([]);
        }
    }

    /**
     * Grouped the Indonesian way, whole rupiah. Written out here rather than
     * with ->money('IDR') for the reason given under Keuangan: money() renders
     * two decimal places unless told otherwise, and every figure in this feature
     * is a whole number.
     */
    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}

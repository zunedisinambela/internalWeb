<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class TransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(3)
                    ->components([
                        TextEntry::make('type')
                            ->label('Jenis')
                            ->badge(),

                        // Formatted the same way as the table column, and for
                        // the same reason — see the note there.
                        TextEntry::make('amount')
                            ->label('Jumlah')
                            ->formatStateUsing(fn (int $state, Transaction $record): string => sprintf(
                                '%s Rp %s',
                                $record->type === TransactionType::Income ? '+' : '−',
                                number_format($state, 0, ',', '.'),
                            ))
                            ->color(fn (Transaction $record): string => $record->type->getColor())
                            ->weight('bold'),

                        TextEntry::make('occurred_at')
                            ->label('Tanggal dan waktu')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('description')
                            ->label('Keterangan')
                            ->columnSpanFull(),
                    ]),

                Section::make('Bukti')
                    ->components([
                        SpatieMediaLibraryImageEntry::make('receipts')
                            ->hiddenLabel()
                            ->collection(Transaction::RECEIPTS)
                            ->conversion(Transaction::THUMBNAIL)
                            ->disk('local')
                            // The private disk answers signed URLs only. See the
                            // note on the same call in TransactionForm.
                            ->visibility('private')
                            ->height(160)
                            ->placeholder('Tidak ada bukti terlampir')
                            // Marks the wrapper the lightbox script attaches to.
                            // Every <a> inside it becomes one slide, so a
                            // transaction carrying several receipts is paged
                            // through rather than opened one at a time.
                            ->extraAttributes(['data-lightbox' => 'receipts'])
                            // A closure taking `state` is what makes the URL
                            // per-image: CanOpenUrl::hasStateBasedUrls() looks
                            // for that parameter by name, and without it every
                            // thumbnail would link to the same file. The state
                            // is the media uuid.
                            ->url(fn (?string $state, Transaction $record): ?string => static::receiptUrl($state, $record)),
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
     * A signed, expiring link to a receipt's original file.
     *
     * The entry renders the `thumb` conversion, which is re-encoded and so has
     * dropped almost all of the EXIF the phone wrote. This returns the original
     * instead, because zooming into a downscaled thumbnail defeats the point —
     * and reaching the original is meant to take a deliberate signed request,
     * which a click is. See the Media section of CLAUDE.md.
     *
     * The expiry mirrors SpatieMediaLibraryImageEntry::getImageUrl() rather than
     * inventing its own, so a thumbnail and the file behind it stop working at
     * the same moment instead of one outliving the other.
     */
    private static function receiptUrl(?string $uuid, Transaction $record): ?string
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
}

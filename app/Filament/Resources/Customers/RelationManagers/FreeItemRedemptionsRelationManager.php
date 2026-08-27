<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\Customer;
use App\Models\FreeItemRedemption;
use App\Models\Sale;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The handovers of the free items a customer has earned, on the customer's own
 * screen.
 *
 * A relation manager rather than a resource of its own, because a redemption is
 * unreadable away from the bonus it draws down: "1 barang, 27 Agt" answers
 * nothing without the "2 barang earned" it sits beside. Two consequences worth
 * knowing — there is no `/pengambilan-gratis` route and no global list, and
 * Shield generates permissions from resources, so this is gated through
 * FreeItemRedemptionPolicy against the *customer's* permissions instead. That
 * policy records why.
 */
class FreeItemRedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'freeItemRedemptions';

    protected static ?string $title = 'Pengambilan barang gratis';

    protected static ?string $modelLabel = 'pengambilan';

    protected static ?string $pluralModelLabel = 'pengambilan';

    /**
     * Writable on the *view* screen, which needs saying out loud.
     *
     * Filament makes relation managers read-only on a ViewRecord page by
     * default — `RelationManager::isReadOnly()` returns true for any page that
     * subclasses it — and the failure is silent in the worst way: the table
     * renders, the rows are there, and the create, edit and delete actions are
     * simply absent, as though nobody had permission. Recording a handover is
     * the entire reason this table is on the customer's screen, so the default
     * is refused here rather than panel-wide with
     * `readOnlyRelationManagersOnResourceViewPagesByDefault(false)`, which would
     * quietly change every relation manager added after it.
     *
     * The actions are still gated: each one consults
     * FreeItemRedemptionPolicy, which maps onto the customer's permissions.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('redeemed_at')
                    ->label('Tanggal pengambilan')
                    ->default(now())
                    ->seconds(false)
                    ->displayFormat('d M Y H:i')
                    ->native(false)
                    ->required()
                    ->maxDate(now()->addDay())
                    // now() is already WIB — APP_TIMEZONE is Asia/Jakarta and
                    // timestamps are stored in local time, so nothing is
                    // converted here or downstream.
                    ->helperText('Terisi waktu sekarang. Ubah bila barang diambil di lain waktu.'),

                TextInput::make('quantity')
                    ->label('Jumlah gratis diambil')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->default(1)
                    ->rule(static fn (FreeItemRedemptionsRelationManager $livewire, ?Model $record): Closure => static function (
                        string $attribute,
                        mixed $value,
                        Closure $fail,
                    ) use ($livewire, $record): void {
                        $available = $livewire->availableFor($record);

                        if ((int) $value > $available) {
                            $fail(sprintf(
                                'Sisa gratis pelanggan ini hanya %d barang.',
                                max(0, $available),
                            ));
                        }
                    })
                    ->helperText(fn (?Model $record): string => sprintf(
                        'Sisa yang bisa diambil: %d barang. Setiap %d barang dibeli dapat 1 gratis.',
                        max(0, $this->availableFor($record)),
                        Sale::FREE_ITEM_THRESHOLD,
                    )),

                TextInput::make('tracking_number')
                    ->label('Nomor resi')
                    ->maxLength(255)
                    ->helperText('Kosongkan bila barang diserahkan langsung.'),

                TextInput::make('note')
                    ->label('Catatan')
                    ->maxLength(255)
                    ->helperText('Misalnya "diambil bersama pesanan berikutnya".'),

                SpatieMediaLibraryFileUpload::make('shipping_proofs')
                    ->label('Foto resi')
                    ->collection(FreeItemRedemption::SHIPPING_PROOFS)
                    ->disk('local')
                    // Makes Filament ask for a signed, expiring URL. The private
                    // disk refuses an unsigned one before it even looks for the
                    // file, so without this every preview silently becomes a
                    // broken image with nothing in the log.
                    ->visibility('private')
                    ->conversion(FreeItemRedemption::THUMBNAIL)
                    ->multiple()
                    ->reorderable()
                    // Without this a second upload replaces the first set rather
                    // than adding to it.
                    ->appendFiles()
                    ->image()
                    ->imageEditor()
                    ->openable()
                    ->downloadable()
                    ->panelLayout('grid')
                    ->maxFiles(5)
                    ->maxSize(5 * 1024)
                    // Repeated from the collection deliberately: this rejects the
                    // file in the browser with a message, the collection rejects
                    // it server-side with an exception. Only one of the two is a
                    // good experience, and only one of the two is enforcement.
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->columnSpanFull()
                    ->helperText('Foto resi kurir. JPG, PNG atau WEBP, maksimal 5 berkas @ 5 MB.'),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tracking_number')
            ->defaultSort('redeemed_at', 'desc')
            ->columns([
                TextColumn::make('redeemed_at')
                    ->label('Tanggal diambil')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->alignEnd()
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn (int $state): string => $state.' gratis'),

                TextColumn::make('tracking_number')
                    ->label('Nomor resi')
                    ->searchable()
                    ->placeholder('Diserahkan langsung')
                    ->copyable()
                    ->copyMessage('Nomor resi disalin'),

                SpatieMediaLibraryImageColumn::make('shipping_proofs')
                    ->label('Foto resi')
                    ->collection(FreeItemRedemption::SHIPPING_PROOFS)
                    ->conversion(FreeItemRedemption::THUMBNAIL)
                    ->disk('local')
                    // Same reason as on the form field: the private disk answers
                    // signed URLs only, and without this the column asks for an
                    // unsigned one and renders a broken image.
                    ->visibility('private')
                    ->circular()
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText(),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.name')
                    ->label('Dicatat oleh')
                    ->placeholder('Tidak diketahui')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Catat pengambilan')
                    // Hidden rather than disabled when nothing is owed: a button
                    // that opens a form every submission of which is refused is a
                    // worse explanation than the sentence in the empty state.
                    ->visible(fn (): bool => $this->availableFor(null) > 0),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned to per-record deletes so each removal writes its own
                    // audit entry and takes its resi photograph with it, rather
                    // than the selection going down in one query that fires no
                    // model events. See CLAUDE.md's Filament conventions.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada pengambilan')
            ->emptyStateDescription(fn (): string => $this->availableFor(null) > 0
                ? sprintf('Pelanggan ini berhak atas %d barang gratis yang belum diambil.', $this->availableFor(null))
                : 'Barang gratis muncul setelah pelanggan membeli cukup banyak barang.');
    }

    /**
     * How many free items may still be handed over, read fresh from the
     * database.
     *
     * `fresh()` rather than the owner record already in memory, and that is the
     * point of the method: the owner was loaded when the page rendered, so a
     * redemption saved a moment ago is not in its relation and the form would
     * happily allow the same bonus to be collected twice. Reloading the two
     * relations reuses `Customer::$free_quantity_available` exactly rather than
     * writing the arithmetic a second time in SQL.
     *
     * When an existing row is being edited its own quantity is already inside
     * the claimed total, so it is added back — otherwise saving a redemption
     * without changing it would be refused by the row itself.
     */
    public function availableFor(?Model $record): int
    {
        /** @var Customer $customer */
        $customer = $this->getOwnerRecord()->fresh(['sales', 'freeItemRedemptions']);

        return $customer->free_quantity_available + ($record?->quantity ?? 0);
    }
}

<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Newest first: a cash book is read from the most recent entry back,
            // not from the day it was opened.
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Waktu')
                    // Stored in WIB already, so no timezone conversion is set
                    // here. translatedFormat() gives Indonesian month names
                    // because AppServiceProvider sets Carbon's locale.
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->description(fn (Transaction $record): string => $record->occurred_at->diffForHumans()),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->alignEnd()
                    ->sortable()
                    // Formatted by hand rather than with ->money('IDR'), which
                    // would work but cannot carry the leading sign — and the
                    // sign is the whole point of the column, since the same
                    // magnitude means opposite things on two rows. money() also
                    // needs decimalPlaces: 0 here, and leans on ext-intl for the
                    // grouping this does directly.
                    ->formatStateUsing(fn (int $state, Transaction $record): string => sprintf(
                        '%s Rp %s',
                        $record->type === TransactionType::Income ? '+' : '−',
                        number_format($state, 0, ',', '.'),
                    ))
                    ->color(fn (Transaction $record): string => $record->type->getColor())
                    ->weight('medium'),

                SpatieMediaLibraryImageColumn::make('receipts')
                    ->label('Bukti')
                    ->collection(Transaction::RECEIPTS)
                    ->conversion(Transaction::THUMBNAIL)
                    ->disk('local')
                    // Same reason as on the form field: the private disk only
                    // answers signed URLs, and without this the column asks for
                    // an unsigned one and renders a broken image.
                    ->visibility('private')
                    ->circular()
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText(),

                TextColumn::make('user.name')
                    ->label('Dicatat oleh')
                    ->placeholder('Tidak diketahui')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(TransactionType::class),

                Filter::make('occurred_at')
                    ->label('Rentang tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari')->native(false),
                        DatePicker::make('until')->label('Sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('occurred_at', '>=', $date))
                        // whereDate on the upper bound, not whereBetween on a
                        // datetime: an "until" of 20 Aug must include everything
                        // that happened on the 20th, not stop at 00:00 that day.
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('occurred_at', '<=', $date)))
                    ->indicateUsing(function (array $data): ?string {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        return match (true) {
                            $from && $until => "Tanggal: {$from} sampai {$until}",
                            (bool) $from => "Sejak {$from}",
                            (bool) $until => "Sampai {$until}",
                            default => null,
                        };
                    }),

                TernaryFilter::make('receipts')
                    ->label('Bukti')
                    ->placeholder('Semua')
                    ->trueLabel('Ada bukti')
                    ->falseLabel('Tanpa bukti')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('media'),
                        false: fn (Builder $query): Builder => $query->doesntHave('media'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pinned to per-record deletes. Filament's single-query bulk
                    // delete fires no model events, which would take the
                    // activity log entry and the receipt files down with it —
                    // the rows would go and the images would stay on disk with
                    // nothing left pointing at them.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Catat pemasukan atau pengeluaran pertama Anda.');
    }
}

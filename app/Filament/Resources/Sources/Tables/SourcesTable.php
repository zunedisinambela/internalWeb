<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Enums\TransactionType;
use App\Models\Source;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Source $record): ?string => $record->note),

                // Saldo dihitung di basis data, bukan dengan menjalankan
                // relasi tiap baris: dua subkueri sekali jalan, bukan dua kueri
                // per sumber. Keduanya null pada sumber yang belum punya
                // transaksi — bukan 0 — sehingga cast eksplisit di bawah bukan
                // hiasan.
                TextColumn::make('balance')
                    ->label('Saldo')
                    ->alignEnd()
                    ->state(fn (Source $record): int => (int) $record->transactions_sum_income - (int) $record->transactions_sum_expense)
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->weight('medium')
                    // Tanpa kolom di belakangnya, ->sortable() saja akan
                    // memasang kontrol yang tidak mengurutkan apa pun. Lihat
                    // catatan usage_kwh di CLAUDE.md.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("(COALESCE(transactions_sum_income, 0) - COALESCE(transactions_sum_expense, 0)) {$direction}"))
                    ->description(fn (Source $record): string => (int) $record->transactions_count.' transaksi', position: 'below'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Tidak aktif'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->emptyStateHeading('Belum ada sumber dana')
            ->emptyStateDescription('Tambahkan dompet atau rekening yang uangnya berpindah lewat sana.');
    }

    /**
     * Kedua sisi buku, per sumber, sebagai subkueri.
     *
     * Dipakai daftar dan halaman edit lewat SourceResource::getEloquentQuery(),
     * jadi ditulis sekali di sini: dua withSum yang terpisah di dua tempat
     * adalah dua kesempatan untuk lupa menyaring salah satu jenisnya, dan
     * hasilnya saldo yang masuk akal tapi salah.
     *
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public static function withBalance(Builder $query): Builder
    {
        return $query
            ->withCount('transactions')
            ->withSum(
                ['transactions as transactions_sum_income' => fn (Builder $q) => $q->where('type', TransactionType::Income->value)],
                'amount',
            )
            ->withSum(
                ['transactions as transactions_sum_expense' => fn (Builder $q) => $q->where('type', TransactionType::Expense->value)],
                'amount',
            );
    }
}

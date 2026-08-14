<?php

namespace App\Filament\Resources\Transactions;

use App\Filament\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Filament\Resources\Transactions\Schemas\TransactionForm;
use App\Filament\Resources\Transactions\Schemas\TransactionInfolist;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Models\Transaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $navigationLabel = 'Keuangan';

    // Indonesian has no plural inflection, so both labels are the same word.
    // Filament would otherwise print "Transaksis" wherever it pluralises.
    protected static ?string $modelLabel = 'transaksi';

    protected static ?string $pluralModelLabel = 'transaksi';

    // Ahead of the monitoring screens (90+) and user management (80): this is
    // what the panel is for, the rest is how it is kept honest.
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return TransactionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionsTable::configure($table);
    }

    /**
     * Both relations are rendered on every row — the recorder's name in a column
     * and the receipts in a stacked image column — so they are loaded once per
     * page instead of once per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'media']);
    }

    /**
     * Who may download the cash book as a spreadsheet.
     *
     * Deliberately the same permission that opens the list, and not a new one.
     * The export carries no column the table does not already show to the same
     * caller — including "Dicatat oleh", which is toggleable rather than
     * privileged — so a separate gate would restrict the format, not the data,
     * and anyone refused could still read every figure off the screen.
     *
     * What it *does* change is that the data leaves the panel, which is why it
     * is audited (see ExportTransactionsAction) rather than gated harder. If
     * this organisation ever decides that taking the book out of the building
     * is its own privilege, the answer is a Shield permission generated for the
     * resource, and it goes here — on the resource, where the record-level
     * checks live, not on the button.
     */
    public static function canExport(): bool
    {
        return static::canViewAny();
    }

    /**
     * Shown next to the navigation entry. Counting rows on every request would
     * be a query per page load for a number nobody acts on, so this is the
     * balance instead — the one figure worth seeing without opening the screen.
     */
    public static function getNavigationBadge(): ?string
    {
        return 'Rp '.number_format(static::balance(), 0, ',', '.');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::balance() < 0 ? 'danger' : 'success';
    }

    /**
     * Filament asks for the badge and its colour in two separate calls, so the
     * result is held for the rest of the request. Without this the sidebar costs
     * two identical aggregate queries on every page of the panel.
     */
    protected static ?int $balance = null;

    protected static function balance(): int
    {
        return static::$balance ??= Transaction::balance();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'create' => CreateTransaction::route('/create'),
            'view' => ViewTransaction::route('/{record}'),
            'edit' => EditTransaction::route('/{record}/edit'),
        ];
    }
}

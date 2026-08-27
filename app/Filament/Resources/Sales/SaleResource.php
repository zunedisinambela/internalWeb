<?php

namespace App\Filament\Resources\Sales;

use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Resources\Sales\Schemas\SaleInfolist;
use App\Filament\Resources\Sales\Tables\SalesTable;
use App\Models\Sale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|UnitEnum|null $navigationGroup = 'Oriflame';

    protected static ?string $navigationLabel = 'Penjualan';

    // Indonesian has no plural inflection, so both labels are the same word.
    protected static ?string $modelLabel = 'penjualan';

    protected static ?string $pluralModelLabel = 'penjualan';

    // First in the group: this is the screen worked in every day, while
    // customers are set up and then consulted.
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return SaleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SaleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    /**
     * The two relations the list renders per row, loaded once per page instead
     * of once per row. Every figure is now a column on the sale itself, so
     * nothing else has to come with them.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'create' => CreateSale::route('/create'),
            'view' => ViewSale::route('/{record}'),
            'edit' => EditSale::route('/{record}/edit'),
        ];
    }

    /**
     * Who may download the sales log.
     *
     * Deliberately the same permission that opens the list, and not a new one.
     * The export carries no column the table does not already show to the same
     * caller, so a separate gate would restrict the format rather than the
     * data — anyone refused could still read every figure off the screen.
     *
     * What it does change is that the figures leave the panel, and that the PDF
     * carries the attachments rather than a count of them: a resi is a
     * photograph of a customer's home address. That is why the download is
     * audited by the job rather than gated harder. If taking the log out of the
     * building ever becomes its own privilege, the answer is a Shield
     * permission generated for this resource, and it goes here — on the
     * resource, where the record-level checks live, not on the button.
     */
    public static function canExport(): bool
    {
        return static::canViewAny();
    }
}

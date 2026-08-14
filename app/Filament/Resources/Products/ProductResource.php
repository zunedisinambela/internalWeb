<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Schemas\ProductInfolist;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Oriflame';

    protected static ?string $navigationLabel = 'Produk';

    // Indonesian has no plural inflection, so both labels are the same word.
    protected static ?string $modelLabel = 'produk';

    protected static ?string $pluralModelLabel = 'produk';

    // Last in the group: the catalogue is filled in when it changes, while
    // sales are entered constantly.
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /**
     * Refuses to delete a product that has been sold.
     *
     * sale_items.product_id is restrictOnDelete, so the database refuses this
     * too; without the check the button hands over a raw QueryException instead
     * of a sentence. On the resource rather than the action, because Filament
     * consults the resource for the row action *and* for every record inside a
     * bulk delete — the same shape as RoomResource::canDelete().
     *
     * A product that has gone out of the catalogue is deactivated instead, which
     * takes it out of the sale form's select and leaves every sale that names it
     * readable.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! $record->saleItems()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}

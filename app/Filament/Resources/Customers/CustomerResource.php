<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\FreeItemRedemptionsRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Oriflame';

    protected static ?string $navigationLabel = 'Pelanggan';

    // Indonesian has no plural inflection, so both labels are the same word.
    protected static ?string $modelLabel = 'pelanggan';

    protected static ?string $pluralModelLabel = 'pelanggan';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    /**
     * Refuses to delete a customer who has bought something, turning a
     * QueryException from the restrictOnDelete foreign key into a button that is
     * simply not there.
     *
     * On the resource rather than on the action, because Filament consults the
     * resource for the row action *and* for every record inside a bulk delete —
     * a check on ->visible() alone would leave the bulk path open. The same
     * shape as RoomResource::canDelete(), and for the same reason: the foreign
     * key is the real enforcement and covers tinker, but a 500 page is a poor
     * way to learn a rule.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record)
            && ! $record->sales()->exists()
            && ! $record->freeItemRedemptions()->exists();
    }

    /**
     * The free items this customer has collected, shown on their own screen
     * because a handover is unreadable away from the bonus it draws down.
     * FreeItemRedemptionsRelationManager records why it is not a resource.
     */
    public static function getRelations(): array
    {
        return [
            FreeItemRedemptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    /**
     * Who may download the customer directory.
     *
     * The same permission that opens the list, for the reason the cash book
     * gives: the export shows no column the table does not, so a separate gate
     * would restrict the format rather than the data.
     *
     * It is worth being explicit about what that data is here. `address` is a
     * column of the export, so the file is a list of where people live — the
     * loosest of the three copies of a home address this app holds (see Access
     * control). Nothing about it is more sensitive than the screen it came
     * from; what changes is that it now exists outside the panel, where no
     * policy is consulted again. That is what the audit entry is for.
     */
    public static function canExport(): bool
    {
        return static::canViewAny();
    }
}

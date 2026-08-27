<?php

namespace App\Filament\Resources\ElectricityTariffs;

use App\Filament\Resources\ElectricityTariffs\Pages\CreateElectricityTariff;
use App\Filament\Resources\ElectricityTariffs\Pages\EditElectricityTariff;
use App\Filament\Resources\ElectricityTariffs\Pages\ListElectricityTariffs;
use App\Filament\Resources\ElectricityTariffs\Schemas\ElectricityTariffForm;
use App\Filament\Resources\ElectricityTariffs\Tables\ElectricityTariffsTable;
use App\Models\ElectricityTariff;
use App\Support\PanelCache;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ElectricityTariffResource extends Resource
{
    protected static ?string $model = ElectricityTariff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $recordTitleAttribute = 'rate';

    protected static string|UnitEnum|null $navigationGroup = 'Kost';

    protected static ?string $navigationLabel = 'Tarif Listrik';

    protected static ?string $modelLabel = 'tarif listrik';

    protected static ?string $pluralModelLabel = 'tarif listrik';

    // Last of the three: set once, then consulted rather than worked in.
    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return ElectricityTariffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ElectricityTariffsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    /**
     * The rate in force, beside the navigation entry.
     *
     * Same idiom as TransactionResource's balance badge, and held for the rest
     * of the request for the same reason: Filament asks for the badge and its
     * colour in two separate calls, so without the cache the sidebar costs two
     * identical queries on every page of the panel.
     */
    public static function getNavigationBadge(): ?string
    {
        $rate = static::currentRate();

        return $rate === null
            ? 'Belum diatur'
            : 'Rp '.number_format($rate, 0, ',', '.').'/kWh';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::currentRate() === null ? 'danger' : 'success';
    }

    protected static ?int $currentRate = null;

    protected static bool $currentRateResolved = false;

    /**
     * Null is a real answer here — an empty table means no tariff has been set —
     * so it cannot be cached with `??=`, which would re-query on every call.
     * PanelCache::remember() has the same problem and solves it the same way,
     * by wrapping the value rather than testing it.
     *
     * **The cache stops at this method.** ElectricityTariff::currentRate() is
     * also what MeterReadingForm defaults its rate field from, and that figure
     * is *copied onto the reading* and billed from there — see
     * docs/listrik-kost.md. A stale badge is a wrong number on a sidebar; a
     * stale default is a wrong number on a tenant's bill, permanently. So the
     * model method stays live and only this presentation path is cached.
     */
    protected static function currentRate(): ?int
    {
        if (! static::$currentRateResolved) {
            static::$currentRate = PanelCache::remember(
                PanelCache::RATE,
                ttl: PanelCache::rateTtl(),
                callback: static fn (): ?int => ElectricityTariff::currentRate(),
            );
            static::$currentRateResolved = true;
        }

        return static::$currentRate;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListElectricityTariffs::route('/'),
            'create' => CreateElectricityTariff::route('/create'),
            'edit' => EditElectricityTariff::route('/{record}/edit'),
        ];
    }
}

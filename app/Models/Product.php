<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One item out of the Oriflame catalogue, carrying both of its prices.
 *
 * `catalog_price` is what the official site charges. `marketing_price` is what
 * this consultant pays for it. The difference is the margin, and it is the whole
 * reason this table has two price columns instead of one.
 *
 * These figures are the *current* ones. They prefill a sale and they are never
 * what a recorded sale is computed from — each sale line copies both prices onto
 * itself as it is entered. See SaleItem, where the reasoning lives.
 *
 * **Prices are not versioned here, unlike ElectricityTariff.** That looks like an
 * inconsistency and is not. A tariff needs its own history because a bill is
 * recomputed from the rate in force on a date, and there is one rate for
 * everything; a catalogue reprices hundreds of products at once, so versioning
 * would mean a row per product per month for a question the snapshot on each
 * sale line already answers. What is lost is "what did this product cost in
 * July" for a product nobody sold in July — and activity_log records every price
 * change with its causer, which covers the case that actually comes up.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'code',
        'name',
        'catalog_price',
        'marketing_price',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'catalog_price' => 'integer',
            'marketing_price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * What one unit earns at today's prices.
     *
     * Derived rather than stored, so it cannot disagree with the two columns it
     * comes from. Not clamped: the form refuses a marketing price above the
     * catalogue price, so a negative here can only come from a row written
     * outside the form — and showing it as negative is how that becomes visible
     * rather than passing as a product that simply earns nothing.
     */
    protected function unitProfit(): Attribute
    {
        return Attribute::get(fn (): int => $this->catalog_price - $this->marketing_price);
    }

    /**
     * Audit trail. Both prices are on the allowlist and that is the point of it
     * here: a sale line's figures are copied off this row, so a line whose prices
     * match no current product is only explicable from the log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'catalog_price', 'marketing_price', 'is_active', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('product');
    }
}

<?php

namespace App\Models;

use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One product on one sale, at the two prices it carried when it was sold.
 *
 * `catalog_price` and `marketing_price` are copies taken from the Product as the
 * line is entered, never read back through the relation. That is the decision
 * this whole feature rests on, and it is the same one MeterReading makes about
 * the electricity rate:
 *
 * Oriflame issues a new catalogue every month and reprices most of it. If a line
 * read its prices through `product()`, entering the new catalogue would rewrite
 * every sale already recorded — August's margin quietly becoming September's,
 * with no row changed and nothing in activity_log to notice. Copying them means
 * a new catalogue applies to what is sold after it, which is what a new
 * catalogue means.
 *
 * The relation is still there and is still used: for the product's name on the
 * screen, and for prefilling a fresh line. Never for a figure on a saved one.
 */
class SaleItem extends Model
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'catalog_price',
        'marketing_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'catalog_price' => 'integer',
            'marketing_price' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What the customer pays for this line.
     *
     * Both factors are integers, so the product is exact — the payoff for
     * keeping rupiah out of floating point.
     */
    protected function catalogSubtotal(): Attribute
    {
        return Attribute::get(fn (): int => $this->quantity * $this->catalog_price);
    }

    /**
     * What this line cost the consultant.
     */
    protected function marketingSubtotal(): Attribute
    {
        return Attribute::get(fn (): int => $this->quantity * $this->marketing_price);
    }

    /**
     * The margin on this line.
     *
     * Deliberately not clamped at zero. The form refuses a marketing price above
     * the catalogue price, so a negative value here can only come from a row
     * written outside it — from a seeder, from tinker, or from a product whose
     * two prices were entered the wrong way round. Showing it as negative is how
     * that becomes visible; max(0, …) would render the same broken line as a
     * plausible sale that happened to earn nothing.
     */
    protected function profit(): Attribute
    {
        return Attribute::get(fn (): int => $this->catalog_subtotal - $this->marketing_subtotal);
    }

    /**
     * Audited under its own log name rather than folded into `sale`.
     *
     * Both prices are on the allowlist for the same reason meter_readings.rate
     * is: they are the columns whose values were copied from somewhere else, so
     * a line whose figures match no product is only explicable from the log.
     *
     * Lines removed by the cascade when a sale is deleted write nothing here — a
     * foreign key cascade fires no model events. That is the intended shape: the
     * sale's own `deleted` entry is the record of it, and one act should not
     * produce seven entries.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sale_id', 'product_id', 'quantity', 'catalog_price', 'marketing_price'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('sale_item');
    }
}

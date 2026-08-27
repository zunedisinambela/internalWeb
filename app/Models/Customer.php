<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Somebody who buys from this consultant.
 *
 * A row rather than a free text field on the sale: "Ayu" and "ayu" would be two
 * customers, and the question this feature is really for — what has this person
 * bought, and what did I make on it — could not be answered reliably for either.
 *
 * Customers are retired, not deleted. sales.customer_id is restrictOnDelete, so
 * the database refuses to remove anyone with a sale against them; is_active is
 * the exit. Deleting the customer would take the meaning out of the sales
 * without deleting them, which is the worst of the three options.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return HasMany<FreeItemRedemption, $this>
     */
    public function freeItemRedemptions(): HasMany
    {
        return $this->hasMany(FreeItemRedemption::class);
    }

    /**
     * Everything ever earned from this customer.
     *
     * Walks the sales rather than reading a stored figure, for the reason every
     * total in this feature is derived: a stored one would be a number able to
     * disagree with the rows it came from, and nothing would say which of the
     * two was right.
     *
     * The cost is that it is only cheap on a loaded relation, so the caller
     * loads it — `loadMissing('sales')` on the view screen. A `withSum` on the
     * list query would be faster and would be a second copy of the arithmetic;
     * the list therefore shows a count, not a margin.
     */
    protected function totalProfit(): Attribute
    {
        return Attribute::get(fn (): int => $this->sales->sum(
            fn (Sale $sale): int => $sale->profit,
        ));
    }

    /**
     * What this customer has paid across every order, at catalogue prices.
     *
     * Ongkir is not in it: the customer pays the catalogue price and the
     * shipping comes out of the consultant's margin. See Sale::$profit.
     */
    protected function totalSpent(): Attribute
    {
        return Attribute::get(fn (): int => $this->sales->sum(
            fn (Sale $sale): int => $sale->catalog_price,
        ));
    }

    /**
     * How many items this customer has ever bought, across every order.
     *
     * Derived like every other total here, and it is what the bonus below is
     * answerable from. Only cheap on a loaded relation — the callers that walk
     * it load it (`loadMissing('sales')` on the view screen); the list screen
     * asks the database for the same figure with ->sum() instead, which is why
     * the division lives in freeItemsFor() rather than inline in either.
     */
    protected function totalQuantity(): Attribute
    {
        return Attribute::get(fn (): int => $this->sales->sum(
            fn (Sale $sale): int => $sale->quantity,
        ));
    }

    /**
     * How many free items this customer has earned in total.
     *
     * **This counts across orders, and Sale::$free_quantity does not.** Two
     * orders of ten items each earn nothing per sale and one free item here,
     * which is the whole reason this exists as a second accessor rather than a
     * sum of the first: summing per-sale bonuses would throw away every
     * remainder at the row boundary, so the same twenty items would be worth a
     * free one or nothing depending on how many trips they were bought in.
     * A customer buying ten a month is the ordinary case, so that reading is
     * the one that matters.
     *
     * Carries no money, exactly as the per-sale figure does not — see
     * Sale::$free_quantity for why the margin is left alone.
     *
     * **Nothing records a bonus as claimed.** This is a lifetime count, so a
     * free item handed over is still counted here forever; deciding otherwise
     * means a column recording redemptions, not a change to this line.
     */
    protected function freeQuantity(): Attribute
    {
        return Attribute::get(fn (): int => self::freeItemsFor($this->total_quantity));
    }

    /**
     * How many free items this customer has actually collected.
     *
     * The one figure in this feature that is *recorded* rather than derived, and
     * it has to be: whether somebody turned up for their free item is a fact
     * about the world, not arithmetic over their orders. Summed from the
     * handovers rather than kept as a counter on this row for the ordinary
     * reason — a counter would be a second number able to disagree with the rows
     * it was incremented from, and deleting a mistaken handover would leave it
     * behind.
     */
    protected function freeQuantityClaimed(): Attribute
    {
        return Attribute::get(fn (): int => $this->freeItemRedemptions->sum(
            fn (FreeItemRedemption $redemption): int => $redemption->quantity,
        ));
    }

    /**
     * What this customer is still owed: earned minus collected.
     *
     * **Deliberately not clamped at zero.** The redemption form refuses to hand
     * over more than is available, so a negative figure here cannot come from
     * the panel — it can only come from a handover recorded against an order
     * that was later corrected downwards, or from a row written outside the
     * form. That is a real bookkeeping problem and showing it as a negative in
     * red is how it gets noticed; max(0, …) would render the same customer as
     * one who happens to be owed nothing, which is the reading that hides it.
     * The same reason Sale::$profit and MeterReading::$usage_kwh are not
     * clamped.
     */
    protected function freeQuantityAvailable(): Attribute
    {
        return Attribute::get(fn (): int => $this->free_quantity - $this->free_quantity_claimed);
    }

    /**
     * How many items are still to be bought before the next free one.
     *
     * Zero items owe the full threshold rather than nothing, so a customer with
     * no orders reads as "20 lagi" instead of as one about to earn a bonus.
     */
    protected function quantityToNextFreeItem(): Attribute
    {
        return Attribute::get(
            fn (): int => Sale::FREE_ITEM_THRESHOLD - ($this->total_quantity % Sale::FREE_ITEM_THRESHOLD),
        );
    }

    /**
     * The bonus arithmetic, in one place.
     *
     * The accessor above reads a figure summed in PHP off a loaded relation; the
     * customer list reads the same figure back from a ->sum('sales', 'quantity')
     * subquery, which arrives as null when the customer has no sales at all.
     * Both divide here so the two screens cannot start disagreeing about what
     * twenty items are worth.
     */
    public static function freeItemsFor(?int $quantity): int
    {
        return intdiv(max(0, (int) $quantity), Sale::FREE_ITEM_THRESHOLD);
    }

    /**
     * Audit trail with the columns listed explicitly, the same shape as
     * Transaction. `phone` is a personal detail rather than a business figure, and it is on
     * the list deliberately: a number changed on the wrong row is how a message
     * about an order reaches the wrong person.
     *
     * `address` is on it for the same reason and at a higher cost: a parcel
     * posted to a stale address is lost rather than merely misdirected, so the
     * previous value has to be recoverable. The cost is that activity_log then
     * holds home addresses, and its own retention is blank by default — see
     * Monitoring. Anyone holding ViewAny:Activity can read them there without
     * passing the customer policy.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'phone', 'address', 'is_active', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('customer');
    }
}

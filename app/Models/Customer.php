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
 * It exists for the same reason Room does rather than a free text field on the
 * sale: "Ayu" and "ayu" would be two customers, and the question this feature is
 * really for — what has this person bought, and what did I make on it — could
 * not be answered reliably for either.
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
     * Everything ever earned from this customer.
     *
     * Walks the sales and their lines rather than reading a stored figure, for
     * the reason every total in this feature is derived: a stored one would be a
     * number able to disagree with the lines it came from, and nothing would say
     * which of the two was right.
     *
     * The cost is that it is only cheap on a loaded relation, so the caller
     * loads it — `loadMissing('sales.items')` on the view screen. A `withSum` on
     * the list query would be faster and would be a second copy of the
     * arithmetic; the list therefore shows a count, not a margin.
     */
    protected function totalProfit(): Attribute
    {
        return Attribute::get(fn (): int => $this->sales->sum(
            fn (Sale $sale): int => $sale->profit,
        ));
    }

    /**
     * What this customer has paid across every sale, at catalogue prices.
     */
    protected function totalSpent(): Attribute
    {
        return Attribute::get(fn (): int => $this->sales->sum(
            fn (Sale $sale): int => $sale->catalog_total,
        ));
    }

    /**
     * Audit trail with the columns listed explicitly, the same shape as Room.
     * `phone` is a personal detail rather than a business figure, and it is on
     * the list deliberately: a number changed on the wrong row is how a message
     * about an order reaches the wrong person.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'phone', 'is_active', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('customer');
    }
}

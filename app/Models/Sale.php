<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One purchase by one customer: what they took, what the catalogue charges for
 * it, what it cost this consultant, and therefore what was earned.
 *
 * The worked example this was built from: Ayu takes products A, B and C. The
 * catalogue prices them at Rp 200.000 together; the consultant pays Rp 150.000
 * for the same three; the margin is Rp 50.000. All three figures are on this
 * model and none of them is stored — they are sums over the lines, so they
 * cannot disagree with the lines they came from. A stored total would be a
 * fourth number able to contradict the three it was derived from, which is the
 * same reason MeterReading keeps usage and the bill as accessors.
 */
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'customer_id',
        'occurred_at',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Stamps the signed-in user on rows created outside a Filament form, the
     * same way Transaction and MeterReading do. Left null when nobody is signed
     * in rather than guessed at — an unattributed row is honest, a guessed one
     * is not.
     */
    protected static function booted(): void
    {
        static::creating(function (self $sale): void {
            $sale->user_id ??= auth()->id();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * What the customer pays: the catalogue price of everything on the sale.
     */
    protected function catalogTotal(): Attribute
    {
        return Attribute::get(fn (): int => $this->items->sum(
            fn (SaleItem $item): int => $item->catalog_subtotal,
        ));
    }

    /**
     * What the sale cost this consultant, at the prices in force when each line
     * was entered.
     */
    protected function marketingTotal(): Attribute
    {
        return Attribute::get(fn (): int => $this->items->sum(
            fn (SaleItem $item): int => $item->marketing_subtotal,
        ));
    }

    /**
     * The margin. Not clamped at zero, for the reason given on
     * SaleItem::profit — a negative one means a line was written outside the
     * form, and rendering it in red is how that becomes visible.
     */
    protected function profit(): Attribute
    {
        return Attribute::get(fn (): int => $this->catalog_total - $this->marketing_total);
    }

    /**
     * A correlated SUM over this sale's lines, for ordering the list on a figure
     * that is not a column.
     *
     * The three totals above are accessors over a loaded relation, which the
     * database cannot sort by — and Filament sorting on a ->state() column with
     * no expression silently reorders by nothing (see the note in CLAUDE.md's
     * Filament conventions). This is the one place the arithmetic is written a
     * second time, so it is written once here and shared by all three columns
     * rather than three times in the table class.
     *
     * COALESCE, because a sale with no lines yet sums to NULL, and NULL sorts
     * apart from zero in a way that reads as data missing rather than as an
     * empty sale.
     *
     * @param  string  $expression  SQL over sale_items columns, e.g. "quantity * catalog_price"
     */
    public static function sumOfItems(string $expression): BuilderContract
    {
        return SaleItem::query()
            ->selectRaw("COALESCE(SUM({$expression}), 0)")
            ->whereColumn('sale_items.sale_id', 'sales.id')
            ->getQuery();
    }

    /**
     * Audit trail with an explicit allowlist, the same shape as Transaction.
     *
     * The lines are a relation, not columns, so LogsActivity cannot see them —
     * SaleItem carries its own trait for that, under its own log name. Splitting
     * them keeps "who changed this sale's customer or date" readable without
     * every line edit in between, and it is the same split LogRoleChange makes
     * for roles and the media listener makes for attachments.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'occurred_at', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('sale');
    }
}

<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One order by one customer, as three figures: what it cost this consultant,
 * what the shipping cost, and what the customer was charged.
 *
 * The worked example it is written for: Zunedi's order costs Rp 190.000 from
 * Oriflame, Rp 10.000 to send, and is sold on at the catalogue price of
 * Rp 220.000. The margin is Rp 20.000.
 *
 * **Ongkir is the consultant's cost, not a line on the customer's bill.** The
 * customer pays `catalog_price` and nothing more, so the margin is
 * `catalog − marketing − shipping`. Billing shipping on top would be a fourth
 * figure — what was actually charged — and the two readings are not
 * distinguishable after the fact, which is why the model states one.
 *
 * The margin is derived, never stored. A stored one would be a fourth number
 * able to contradict the three it came from, and nothing would say which was
 * right — the same reason MeterReading keeps usage and the bill as accessors.
 *
 * **There are no product lines.** This model used to own a `SaleItem` relation
 * against a `Product` catalogue, each line carrying a price snapshot so a
 * repriced catalogue could not rewrite a recorded sale. That was narrowed
 * deliberately: an order is written down here as one set of figures, and the
 * lines were machinery for a question nobody was asking. The snapshot concern
 * does not survive the narrowing — there is no catalogue row left to join to,
 * so nothing outside this row can move a figure on it.
 *
 * What it costs is per-product history: "what sells best" and "what did this
 * product cost in July" are no longer answerable. Bringing that back means
 * bringing the lines back, not adding a column here.
 *
 * **Two media collections carry the evidence**: the customer's transfer receipt
 * and the courier's resi, both on the private disk. They are rows in the `media`
 * table keyed by morph, which is why "more than one transfer" costs no schema
 * change and no migration.
 */
class Sale extends Model implements HasMedia
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity;

    /**
     * The two kinds of evidence an order leaves behind, one collection each.
     *
     * `payment-proofs` is the customer's transfer receipt — proof the money
     * arrived. `shipping-proofs` is the courier's resi — proof the goods left.
     * They answer different questions, and which file answers which is the whole
     * point of attaching them at all, so it is held by `collection_name` on the
     * row rather than by upload order. Order is destroyed by reordering or by
     * deleting one file, and neither leaves a trace that the pairing has
     * shifted. Same decision as MeterReading's two photo collections, and it
     * fails the same silent way if they are ever merged.
     *
     * Both are optional and both accept several files: a split payment is two
     * transfers, and an order sent in two parcels is two resi.
     *
     * A collection name that matches nothing registered is not an error: the
     * upload succeeds and the file lands on whichever disk the fall-through
     * picks, which is not the same disk on the Filament path and the addMedia()
     * path. See the Media section of CLAUDE.md.
     */
    public const PAYMENT_PROOFS = 'payment-proofs';

    public const SHIPPING_PROOFS = 'shipping-proofs';

    /** The conversion shown in lists and infolists. Never the original. */
    public const THUMBNAIL = 'thumb';

    protected $fillable = [
        'customer_id',
        'occurred_at',
        'quantity',
        'marketing_price',
        'shipping_cost',
        'catalog_price',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'quantity' => 'integer',
            'marketing_price' => 'integer',
            'shipping_cost' => 'integer',
            'catalog_price' => 'integer',
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
     * How many items are bought before one is given away.
     *
     * A constant rather than a column on `customers` or a settings row, because
     * today it is one rule for everybody and a table nobody edits is a table
     * that drifts out of date silently. When the threshold does start varying
     * per customer or per month, it becomes a column and this constant becomes
     * its default — moving it later costs nothing, because the only thing that
     * reads it is the accessor below.
     */
    public const FREE_ITEM_THRESHOLD = 20;

    /**
     * How many items this order earns for free.
     *
     * Derived, never stored, for the reason $profit is: a stored copy would be a
     * second number able to contradict `quantity`, and nothing on the row would
     * say which was right. It is also why nothing has to be recalculated when a
     * quantity is corrected — the figure follows the column by construction.
     *
     * **It carries no money yet.** The three price columns are totals for the
     * whole order and this does not touch them, so `total_cost` and `profit`
     * read exactly as they did before this column existed. What this answers is
     * the count question alone: "does this order qualify". Whether the free item
     * is still paid for to Oriflame — and therefore whether it belongs in
     * `marketing_price` — is a separate decision that has not been made, and
     * making it here by inference would put a figure on a margin nobody entered.
     */
    protected function freeQuantity(): Attribute
    {
        return Attribute::get(fn (): int => intdiv($this->quantity, self::FREE_ITEM_THRESHOLD));
    }

    /**
     * Everything this order cost the consultant: the goods plus the postage.
     */
    protected function totalCost(): Attribute
    {
        return Attribute::get(fn (): int => $this->marketing_price + $this->shipping_cost);
    }

    /**
     * The margin.
     *
     * Deliberately not clamped at zero. A sale can genuinely lose money once
     * shipping is counted — a small order posted a long way — and rendering that
     * as a negative figure in red is how it becomes visible. max(0, …) would
     * render the same order as one that happened to earn nothing, which is the
     * reading that hides it.
     */
    protected function profit(): Attribute
    {
        return Attribute::get(fn (): int => $this->catalog_price - $this->total_cost);
    }

    /**
     * SQL for the margin, for ordering the list by a figure that is not a
     * column.
     *
     * Filament sorting on a ->state() column with no expression silently
     * reorders by nothing (see CLAUDE.md's Filament conventions), so the
     * arithmetic has to be spelled out — and it is spelled out here rather than
     * inline in the table class so the one place it is written twice is beside
     * the accessor it must agree with.
     */
    public const PROFIT_EXPRESSION = '(catalog_price - marketing_price - shipping_cost)';

    /**
     * Attachments live on the private `local` disk, the decision Transaction
     * settled for this project. A transfer receipt carries a bank account
     * number and a name; a resi carries the customer's home address. Neither is
     * something to publish by URL, which is what the `public` disk does — no
     * role check, no policy, no activity_log entry.
     *
     * Every Filament component that renders them sets ->visibility('private') to
     * match; drop it from any one of them and that surface silently renders
     * broken images, because the private disk refuses an unsigned URL before it
     * looks for the file.
     *
     * Both collections are registered with identical rules. They are separate
     * only so that a file says for itself what it is evidence of.
     */
    public function registerMediaCollections(): void
    {
        foreach ([self::PAYMENT_PROOFS, self::SHIPPING_PROOFS] as $collection) {
            $this->addMediaCollection($collection)
                ->useDisk('local')
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        }
    }

    /**
     * Generated inline rather than on the queue, for the two reasons given on
     * Transaction: a deploy with no queue worker would produce originals and
     * never a single thumbnail, with nothing in the log to say so, and the
     * re-encode drops almost all of the EXIF the phone wrote.
     *
     * That second reason is weaker here than on a meter photograph — a transfer
     * receipt is usually a screenshot, which carries no GPS — but a resi is
     * often photographed at the counter, so the original is still only reached
     * by a deliberate signed request.
     *
     * Registered once and not pinned to a collection, so it applies to both.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->fit(Fit::Contain, 400, 400)
            ->nonQueued();
    }

    /**
     * Audit trail with an explicit allowlist, the same shape as Transaction.
     *
     * All three figures are on it, and that is the point of it here: they are
     * the whole record of the order, and a margin that reads differently today
     * than it did last month is only explicable from the log. There are no lines
     * to split off any more, so `sale` is now the single log name for this
     * feature's transactions.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'occurred_at', 'quantity', 'marketing_price', 'shipping_cost', 'catalog_price', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('sale');
    }
}

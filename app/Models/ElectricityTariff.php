<?php

namespace App\Models;

use Database\Factories\ElectricityTariffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The price of a kWh, from a given day onwards.
 *
 * Versioned rather than kept in a single settings row, and the difference is not
 * cosmetic. A single row answers "what is the rate now"; these rows also answer
 * "what was the rate in July", which is the question a tenant asks when their
 * bill goes up. Raising the price is a new row, never an edit — the old one stays
 * as the explanation for every reading recorded while it was in force.
 *
 * The rate is nevertheless *copied* onto each reading as it is recorded (see
 * MeterReading::$rate). This table is the source of the default and the history;
 * it is not consulted when a past bill is displayed. Those two mechanisms answer
 * different questions and neither can replace the other: without the copy a
 * tariff change would rewrite old bills, and without the history nobody could say
 * when the rate changed or who changed it.
 *
 * A row dated in the future is allowed and is how a raise is scheduled — current()
 * ignores it until the day arrives.
 */
class ElectricityTariff extends Model
{
    /** @use HasFactory<ElectricityTariffFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'rate',
        'effective_from',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /**
     * Stamps the author for rows created outside a Filament form — a seeder, a
     * console command, tinker. The create page sets it too, for the same reason
     * CreateTransaction does: keeping it out of the form state means a crafted
     * request cannot attribute a rate change to someone else.
     */
    protected static function booted(): void
    {
        static::creating(function (self $tariff): void {
            $tariff->user_id ??= auth()->id();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The rate in force on a given day, or null when no tariff has been set yet.
     *
     * "In force" is the latest row dated on or before that day. Future rows are
     * skipped, so a scheduled raise does not leak into today's readings.
     *
     * Null is a real answer and callers have to handle it: on a fresh install
     * this table is empty, and inventing a default rate would put a made-up
     * number onto a bill. The reading form refuses to open instead.
     */
    public static function current(?Carbon $at = null): ?self
    {
        return static::query()
            ->whereDate('effective_from', '<=', $at ?? Carbon::now())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * The current rate in rupiah per kWh, or null when none is set.
     */
    public static function currentRate(?Carbon $at = null): ?int
    {
        return static::current($at)?->rate;
    }

    /**
     * Audit trail. The rate is what every bill is computed from, so a change to
     * it is exactly the kind of thing the log exists for — and unlike the
     * two-factor secret, its value is safe to record.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['rate', 'effective_from', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tariff');
    }
}

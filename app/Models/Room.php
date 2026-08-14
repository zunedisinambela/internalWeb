<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One rented room, with its own electricity meter.
 *
 * It exists so a meter reading has something stable to be filed under. A free
 * text field would have done the same job on the form and none of it afterwards:
 * "Kamar 3" and "kamar 3" would be two rooms, and the previous reading — which
 * is what the next reading's opening figure is taken from — could not be found
 * reliably for either.
 *
 * Rooms are retired, not deleted. meter_readings.room_id is restrictOnDelete, so
 * the database refuses to remove a room that has any reading against it; that is
 * what is_active is for. Deleting the room would take the meaning out of the
 * readings without deleting them, which is the worst of the three options.
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'occupant',
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
     * @return HasMany<MeterReading, $this>
     */
    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * The most recent reading taken on this meter.
     *
     * Delegates to MeterReading::previousFor() rather than repeating the
     * ordering: the same figure is what the reading form prefills as the next
     * opening kWh, and two copies of that query would be two places for the
     * tiebreak to drift.
     */
    public function latestReading(?\DateTimeInterface $before = null): ?MeterReading
    {
        return MeterReading::previousFor($this->getKey(), $before);
    }

    /**
     * Audit trail, with the columns listed explicitly — the same shape as
     * Transaction and User. `occupant` is a person's name, so it is logged
     * deliberately rather than by accident: who was in the room when a reading
     * was taken is exactly what a disputed bill turns on.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'occupant', 'is_active', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('room');
    }
}

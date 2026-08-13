<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row settings record for monitoring retention.
 *
 * Read it through current(), never with find(). The row is created on first
 * access so a fresh install and a seeded one behave the same, and nothing has
 * to remember to seed it.
 */
class MonitoringSetting extends Model
{
    protected $fillable = [
        'visit_retention_days',
        'authentication_retention_days',
        'activity_retention_days',
        'last_pruned_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_retention_days' => 'integer',
            'authentication_retention_days' => 'integer',
            'activity_retention_days' => 'integer',
            'last_pruned_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /**
     * Retention is off unless a positive number of days is set. A null column
     * and a 0 both mean keep forever — the column is nullable, but a value can
     * still arrive as 0 from an emptied form field.
     */
    public function prunesVisits(): bool
    {
        return $this->visit_retention_days > 0;
    }

    public function prunesAuthentications(): bool
    {
        return $this->authentication_retention_days > 0;
    }

    public function prunesActivities(): bool
    {
        return $this->activity_retention_days > 0;
    }
}

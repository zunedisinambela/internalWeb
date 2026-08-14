<?php

namespace Database\Factories;

use App\Models\ElectricityTariff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ElectricityTariff>
 */
class ElectricityTariffFactory extends Factory
{
    protected $model = ElectricityTariff::class;

    public function definition(): array
    {
        return [
            // A plausible kost rate, but tests that assert on a total always
            // pass their own through rate() — a random one would make the
            // expected figure depend on the factory.
            'rate' => 1_500,
            'effective_from' => $this->nextEffectiveDate(),
            'note' => null,
            'user_id' => null,
        ];
    }

    public function rate(int $rate, ?string $effectiveFrom = null): static
    {
        return $this->state(fn (): array => array_filter([
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * The day after the latest tariff on record, or today when there is none.
     *
     * effective_from is unique, so a random date would collide as soon as a test
     * made two tariffs — and it would fail on the constraint rather than on the
     * assertion. Walking forward also matches how the table is really filled:
     * each new rate starts after the one it replaces.
     */
    protected function nextEffectiveDate(): string
    {
        $latest = ElectricityTariff::query()->max('effective_from');

        return $latest === null
            ? Carbon::now()->toDateString()
            : Carbon::parse($latest)->addDay()->toDateString();
    }
}

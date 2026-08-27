<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\FreeItemRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FreeItemRedemption>
 */
class FreeItemRedemptionFactory extends Factory
{
    protected $model = FreeItemRedemption::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'redeemed_at' => fake()->dateTimeBetween('-2 months', 'now'),
            // One bonus item is the ordinary handover. A test about collecting
            // two at once asks for it with ->quantity().
            'quantity' => 1,
            // Null by default: a free item handed over in person has no resi,
            // and a factory that always filled one would hide a form that
            // quietly required it.
            'tracking_number' => null,
            'note' => null,
            // Left null so the model's creating() hook is exercised rather than
            // hidden — a factory that guessed an author would mask it.
            'user_id' => null,
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->getKey()]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }

    public function shippedWith(string $trackingNumber): static
    {
        return $this->state(fn (): array => ['tracking_number' => $trackingNumber]);
    }

    public function redeemedAt(string $when): static
    {
        return $this->state(fn (): array => ['redeemed_at' => $when]);
    }
}

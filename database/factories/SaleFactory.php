<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        // A plausible order: the consultant pays about three quarters of what
        // the customer does, and postage is a round figure or nothing at all.
        $catalog = fake()->numberBetween(4, 40) * 25_000;

        return [
            'customer_id' => Customer::factory(),
            'occurred_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'marketing_price' => (int) ($catalog * 0.75),
            'shipping_cost' => fake()->randomElement([0, 10_000, 15_000, 20_000]),
            'catalog_price' => $catalog,
            'note' => null,
            // Left null so the model's creating() hook is exercised rather than
            // hidden — a factory that guessed an author would mask it.
            'user_id' => null,
        ];
    }

    /**
     * Exact figures, so a test asserting on a margin never depends on a random
     * one.
     */
    public function priced(int $marketing, int $catalog, int $shipping = 0): static
    {
        return $this->state(fn (): array => [
            'marketing_price' => $marketing,
            'shipping_cost' => $shipping,
            'catalog_price' => $catalog,
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->getKey()]);
    }

    public function occurredAt(string $when): static
    {
        return $this->state(fn (): array => ['occurred_at' => $when]);
    }
}

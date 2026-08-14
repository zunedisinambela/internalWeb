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
        return [
            'customer_id' => Customer::factory(),
            'occurred_at' => fake()->dateTimeBetween('-3 months', 'now'),
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

    public function occurredAt(string $when): static
    {
        return $this->state(fn (): array => ['occurred_at' => $when]);
    }
}

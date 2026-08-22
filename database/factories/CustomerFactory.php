<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            // Null by default: most customers are handed their order rather than
            // posted it, and a factory that always filled an address would hide
            // a form that quietly required one.
            'address' => null,
            'is_active' => true,
            'note' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function at(string $address): static
    {
        return $this->state(fn (): array => ['address' => $address]);
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}

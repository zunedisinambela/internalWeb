<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        // A catalogue price first, then a consultant price below it — never the
        // other way round. The form refuses a marketing price above the
        // catalogue one, so a factory that produced the reverse would hand every
        // test a row the UI cannot create.
        $catalog = fake()->numberBetween(2, 40) * 25_000;

        return [
            'code' => fake()->unique()->numerify('#####'),
            'name' => fake()->words(2, true),
            'catalog_price' => $catalog,
            'marketing_price' => (int) ($catalog * 0.75),
            'is_active' => true,
            'note' => null,
        ];
    }

    /**
     * Exact prices, so a test asserting on a margin never depends on a random
     * figure — the same reason TransactionFactory takes an explicit amount.
     */
    public function priced(int $catalog, int $marketing): static
    {
        return $this->state(fn (): array => [
            'catalog_price' => $catalog,
            'marketing_price' => $marketing,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

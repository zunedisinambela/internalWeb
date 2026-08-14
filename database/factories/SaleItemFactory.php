<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        $catalog = fake()->numberBetween(2, 40) * 25_000;

        return [
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 3),
            // Written out rather than read off the product, because that is what
            // the application does: the line carries its own copy. A factory that
            // joined instead would make a test asserting the snapshot pass for
            // the wrong reason.
            'catalog_price' => $catalog,
            'marketing_price' => (int) ($catalog * 0.75),
        ];
    }

    /**
     * An exact line, so a test asserting on a total or a margin never depends on
     * a random figure.
     */
    public function line(int $quantity, int $catalog, int $marketing): static
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
            'catalog_price' => $catalog,
            'marketing_price' => $marketing,
        ]);
    }

    /**
     * A line at the product's current prices — the same copy the sale form makes
     * when the product is picked.
     */
    public function ofProduct(Product $product, int $quantity = 1): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'catalog_price' => $product->catalog_price,
            'marketing_price' => $product->marketing_price,
        ]);
    }

    public function forSale(Sale $sale): static
    {
        return $this->state(fn (): array => ['sale_id' => $sale->getKey()]);
    }
}

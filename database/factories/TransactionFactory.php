<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(TransactionType::cases()),
            // Whole rupiah, in a range that would lose precision if the column
            // were ever moved to a float.
            'amount' => fake()->numberBetween(10_000, 25_000_000),
            'description' => fake()->sentence(4),
            'occurred_at' => fake()->dateTimeBetween('-3 months'),
            // Null, bukan Source::factory(): sebuah pabrik di sini akan
            // membuat satu rekening baru untuk setiap transaksi di setiap tes,
            // dan kolomnya memang boleh kosong. Tes yang butuh sumber memasang
            // sendiri lewat ->for($source).
            'source_id' => null,
            'user_id' => null,
        ];
    }

    public function income(int $amount = 1_000_000): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Income,
            'amount' => $amount,
        ]);
    }

    public function expense(int $amount = 1_000_000): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Expense,
            'amount' => $amount,
        ]);
    }
}

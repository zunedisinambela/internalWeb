<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        return [
            // unique() di kolomnya, jadi nama harus unik di sini juga — sebuah
            // faker yang mengulang kata akan menggagalkan tes yang tidak ada
            // hubungannya dengan sumber.
            'name' => fake()->unique()->company(),
            'note' => fake()->boolean(40) ? fake()->numerify('##########') : null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

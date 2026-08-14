<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            // `name` is unique in the schema, so the factory has to be too —
            // otherwise a test that makes three rooms fails on a constraint
            // rather than on what it was asserting.
            'name' => 'Kamar '.fake()->unique()->numberBetween(1, 999),
            'occupant' => fake()->name(),
            'is_active' => true,
            'note' => null,
        ];
    }

    public function vacant(): static
    {
        return $this->state(fn (): array => ['occupant' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

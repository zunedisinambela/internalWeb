<?php

namespace Database\Factories;

use App\Models\MeterReading;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeterReading>
 */
class MeterReadingFactory extends Factory
{
    protected $model = MeterReading::class;

    public function definition(): array
    {
        $start = fake()->numberBetween(1_000, 50_000);
        $openedAt = fake()->dateTimeBetween('-6 months', '-1 month');

        return [
            'room_id' => Room::factory(),
            'start_kwh' => $start,
            'end_kwh' => $start + fake()->numberBetween(10, 200),
            'rate' => 1_500,
            // A period, so the closing moment is always after the opening one —
            // the form refuses the reverse, and a factory that produced it would
            // hand every test a row the UI cannot create.
            'start_read_at' => $openedAt,
            'end_read_at' => (clone $openedAt)->modify('+30 days'),
            'note' => null,
            // Left null so the model's creating() hook is exercised rather than
            // hidden — a factory that guessed an author would mask it.
            'user_id' => null,
        ];
    }

    /**
     * Keeps the period running forwards when a test pins only one end of it.
     *
     * The opening moment above is random across five months, and most tests here
     * override `end_read_at` alone — so roughly one run in twenty drew a start
     * *after* the pinned end and failed on "Waktu pembacaan akhir tidak boleh
     * mendahului waktu pembacaan awal", a message about the form rather than
     * about the test. Dragging the start back is the same invariant the
     * definition states: a factory must not hand out a row the UI refuses to
     * create.
     *
     * afterMaking rather than afterCreating, so the row is corrected before it
     * is written rather than saved wrong and fixed afterwards.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (MeterReading $reading): void {
            if ($reading->start_read_at < $reading->end_read_at) {
                return;
            }

            $reading->start_read_at = $reading->end_read_at->copy()->subDays(30);
        });
    }

    /**
     * A reading with an exact consumption and an exact rate, so a test asserting
     * on the total never depends on a random figure.
     */
    public function usage(int $kwh, int $rate = 1_500, int $start = 1_000): static
    {
        return $this->state(fn (): array => [
            'start_kwh' => $start,
            'end_kwh' => $start + $kwh,
            'rate' => $rate,
        ]);
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn (): array => ['room_id' => $room->getKey()]);
    }
}

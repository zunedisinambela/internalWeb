<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, not cascade and not nullOnDelete. A reading
            // without a room means nothing — unlike transactions.user_id, where
            // an unattributed row is still a true financial record. So the
            // database refuses to delete a room that has readings, and a room
            // that stops being rented is deactivated instead (rooms.is_active).
            //
            // SQLite enforces this only with the foreign_keys pragma on, which
            // Laravel sets by default for its sqlite driver.
            $table->foreignId('room_id')->constrained()->restrictOnDelete();

            // Whole kWh, both of them. Same reasoning as the rupiah columns: a
            // float here would drift, and usage x rate is what becomes money.
            // Meters in this building read whole kWh, so nothing is lost by
            // refusing fractions outright rather than rounding them silently.
            $table->unsignedBigInteger('start_kwh');
            $table->unsignedBigInteger('end_kwh');

            // The rate in force when this reading was recorded, copied out of
            // electricity_tariffs rather than joined to it.
            //
            // This is the column that makes the tariff screen safe to use. A
            // join would recompute every past bill the moment a new rate is
            // entered — July's bill would quietly become August's rate, with no
            // row changed and nothing in the activity log to notice. Copying it
            // means a tariff change applies to what is recorded after it, which
            // is what raising a tariff actually means.
            //
            // It stays editable on the form, so a rate typed wrong can be
            // corrected on the row it belongs to.
            $table->unsignedBigInteger('rate');

            // When each of the two figures was read off the dial. Two moments,
            // not one: a reading covers a period, and the opening figure is read
            // at the start of it while the closing figure is read at the end.
            // A single `read_at` could only ever record one of the two, which
            // made the period the row describes unstated.
            //
            // end_read_at is what the row is dated by — it closes the period, so
            // it is what the list sorts on, what the date filter matches and what
            // previousFor() compares against. Indexed together with room_id
            // because the form looks the previous reading up per room to prefill
            // the opening figure and its moment.
            $table->dateTime('start_read_at');
            $table->dateTime('end_read_at');

            $table->string('note')->nullable();

            // Who recorded it. nullOnDelete, matching transactions.user_id.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['room_id', 'end_read_at']);
            $table->index('end_read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};

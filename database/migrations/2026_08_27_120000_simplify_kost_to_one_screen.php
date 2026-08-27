<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the Kost feature from three screens to one.
 *
 * The panel used to be shaped for a landlord: rooms to file a reading under, and
 * a versioned tariff table a reading copied its rate from. It is now shaped for
 * the tenant recording their own meter, so both of those disappear and a reading
 * stands on its own — two figures, two moments, their photographs, and the rate
 * it is billed at.
 *
 * **No bill loses its meaning.** meter_readings.rate was always a snapshot copied
 * out of electricity_tariffs rather than a join to it (see docs/listrik-kost.md),
 * so every reading already carries the price it was billed at. Dropping the
 * tariff table removes the history of *when the price changed* and nothing else;
 * the amount owed on every recorded period is still computed from columns on its
 * own row.
 *
 * What is lost is the rooms themselves — names and occupants — and the tariff
 * rows. Both are gone for good once this runs. The readings survive intact.
 *
 * The order below is not cosmetic. SQLite refuses ALTER TABLE ... DROP COLUMN on
 * a column that is indexed or named in a foreign key, and reports it as a plain
 * "error in table" that names neither, so the index and the constraint come off
 * first. dropForeign() on SQLite falls through to a full table rebuild rather
 * than a DDL statement — the rows are copied, which is why this is safe to run
 * against a populated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'end_read_at']);
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropColumn('room_id');
        });

        // Dropped after the column, not before: while the foreign key still
        // stood, the database would refuse to remove the table it points at.
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('electricity_tariffs');
    }

    /**
     * Rebuilds the schema, and cannot rebuild the data.
     *
     * The two tables come back empty and room_id comes back **nullable**, which
     * the original column was not. That difference is deliberate and is the
     * honest shape of a reversal: the readings kept by up() have no room to point
     * at any more, so a NOT NULL column could only be restored by inventing a
     * room for them to belong to.
     */
    public function down(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('occupant')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('electricity_tariffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rate');
            $table->date('effective_from')->unique();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['room_id', 'end_read_at']);
        });
    }
};

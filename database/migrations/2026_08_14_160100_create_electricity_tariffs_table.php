<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electricity_tariffs', function (Blueprint $table) {
            $table->id();

            // Rupiah per kWh, whole rupiah — the same decision as
            // transactions.amount, and for the same reason: SQLite has no real
            // DECIMAL type, so a decimal column comes back through PDO as a
            // float and a rate multiplied by a few hundred kWh drifts. See the
            // Keuangan section of CLAUDE.md.
            $table->unsignedBigInteger('rate');

            // The day this rate starts applying. A date, not a datetime: a
            // tariff changes on a day, not at a minute, and storing a time would
            // invite a reading taken that morning to fall under the old rate for
            // reasons nobody could see on screen.
            //
            // Unique, because "which tariff is in force" is resolved by taking
            // the latest effective_from on or before a date. Two rows sharing
            // one date would make that question ambiguous, and the tiebreak
            // would silently become insertion order.
            $table->date('effective_from')->unique();

            $table->string('note')->nullable();

            // Who set it. nullOnDelete, matching transactions.user_id: removing
            // an account must not erase the rate history it left behind, since
            // past bills are still explained by it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electricity_tariffs');
    }
};

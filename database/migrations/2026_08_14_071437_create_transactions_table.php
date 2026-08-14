<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // 'income' / 'expense', cast to App\Enums\TransactionType. A string
            // rather than a database enum: SQLite has no enum type, and adding
            // a third kind later would otherwise need a table rebuild.
            $table->string('type');

            // Whole rupiah, never a decimal. SQLite has no real DECIMAL type —
            // decimal(15,2) becomes NUMERIC affinity and comes back through PDO
            // as a float, which cannot represent 0.1 exactly, so totals drift by
            // a cent once the numbers get large. IDR is not spent in fractions,
            // so integer rupiah removes the problem instead of managing it.
            // Unsigned: direction lives in `type`, and a negative expense would
            // mean the same row could be read two ways.
            $table->unsignedBigInteger('amount');

            $table->string('description');

            // When the money actually moved, which is not the same as when the
            // row was typed in. Defaults to now() in the form, but stays
            // editable so a receipt found a week later can be dated correctly.
            // Indexed because every list, filter and total sorts on it.
            $table->dateTime('occurred_at')->index();

            // Who recorded it. nullOnDelete, not cascade — matching the
            // monitoring tables: removing an account must not erase the
            // financial record it left behind.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Every screen filters by type and orders by date.
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

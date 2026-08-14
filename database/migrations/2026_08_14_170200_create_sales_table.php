<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, matching meter_readings.room_id. A sale with no
            // customer is not a record of anything — unlike user_id below, where
            // an unattributed row is still a true one. Retiring a customer is
            // customers.is_active.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // When the customer actually bought, not when the row was typed.
            // Defaults to now() on the form but stays editable, for the same
            // reason transactions.occurred_at does: an order written up from a
            // chat message three days later has to be datable to the order.
            //
            // Already WIB — timestamps in this project carry no offset. See the
            // Locale and timezone section of CLAUDE.md.
            $table->dateTime('occurred_at');

            $table->string('note')->nullable();

            // Who recorded it. nullOnDelete, matching transactions.user_id:
            // removing an account must not erase the sales it recorded.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The list sorts on occurred_at and the date filter matches on it;
            // the pair covers "everything Ayu bought, newest first", which is
            // the customer page's whole query.
            $table->index('occurred_at');
            $table->index(['customer_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};

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

            // The three figures the whole feature is about, all whole rupiah in
            // an unsignedBigInteger for the reason set out under Keuangan:
            // SQLite has no real DECIMAL, and these are what become money.
            //
            // They are typed per sale rather than summed from product lines.
            // That is a deliberate narrowing — this used to be a Sale -> SaleItem
            // -> Product structure carrying a price snapshot per line, and it was
            // more machinery than the way these sales are actually recorded. What
            // is written down for one order is: what the consultant paid, what
            // the shipping cost, and what the customer was charged. So that is
            // what the row holds.
            //
            // What was given up with the lines: per-product history, "what did I
            // sell most of", and the price snapshot that kept a repriced
            // catalogue from rewriting past sales. The snapshot problem does not
            // come back, because there is no catalogue table left to join to —
            // every figure here was typed onto this row and nothing else can move
            // it.

            // What this consultant paid Oriflame for the order.
            $table->unsignedBigInteger('marketing_price');

            // Shipping, and it is a cost borne by the consultant rather than
            // billed on top: the customer pays catalog_price and nothing more.
            // Default 0 because most orders are handed over rather than posted,
            // and a required field for the usual case would be typed as 0 every
            // time anyway.
            $table->unsignedBigInteger('shipping_cost')->default(0);

            // What the customer pays.
            $table->unsignedBigInteger('catalog_price');

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

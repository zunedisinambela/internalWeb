<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_item_redemptions', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, matching sales.customer_id. A redemption with no
            // customer records nothing — the whole row is "this person collected
            // what they had earned". Retiring a customer is customers.is_active.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // When the free item was actually collected, not when the row was
            // typed. Defaults to now() on the form and stays editable, the same
            // reason sales.occurred_at does: a handover written up from a chat
            // message two days later has to be datable to the handover.
            //
            // Already WIB — timestamps in this project carry no offset. See the
            // Locale and timezone section of CLAUDE.md.
            $table->dateTime('redeemed_at');

            // How many free items were collected at once. Default 1 because that
            // is the ordinary handover; a customer sitting on two bonuses can
            // collect both in one parcel, and splitting that into two rows would
            // invent a second date nobody recorded.
            //
            // This is a count of *bonus* items and never touches a sale's
            // figures — the free item carries no money anywhere in this feature.
            // See Sale::$free_quantity.
            $table->unsignedInteger('quantity')->default(1);

            // The courier's tracking number, as typed. A string rather than a
            // stricter type on purpose: every courier formats these differently,
            // and a validated shape would refuse whichever one is used next.
            // Nullable because a free item handed over in person has no resi at
            // all, which is the common case for a nearby customer.
            $table->string('tracking_number')->nullable();

            $table->string('note')->nullable();

            // Who recorded it. nullOnDelete, matching sales.user_id: removing an
            // account must not erase the handovers it recorded.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The customer screen asks for one customer's redemptions newest
            // first, and Customer::$free_quantity_claimed sums quantity per
            // customer. Both are covered by the pair.
            $table->index(['customer_id', 'redeemed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_item_redemptions');
    }
};

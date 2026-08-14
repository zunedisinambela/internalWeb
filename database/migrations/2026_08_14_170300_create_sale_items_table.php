<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete, and the only cascade in this project. A line
            // belongs to its sale and means nothing without it, so deleting the
            // sale has to take the lines with it — leaving them would be rows
            // pointing at nothing that still sum into any total that forgot to
            // join.
            //
            // The cascade runs in the database and fires no model events, so the
            // audit entry for a removed line comes from the sale's own `deleted`
            // entry rather than per line. That is deliberate: a sale deleted
            // whole is one act, and a log holding six extra entries for its lines
            // would bury it.
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, like sales.customer_id. A line naming a product
            // that no longer exists cannot be read, and the catalogue's exit is
            // products.is_active rather than deletion.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // The load-bearing pair, and the reason this table exists rather
            // than a JSON column on `sales`.
            //
            // Both are snapshots of `products` taken when the sale is recorded,
            // never joined to. Oriflame reprices its whole catalogue every month,
            // so a join would make every past sale read the current figures:
            // entering September's catalogue would silently rewrite what Ayu
            // bought in August, with no row changed, nothing in activity_log, and
            // a profit figure that had been correct becoming a different number.
            // Copying them means a new catalogue applies to what is sold after
            // it, which is what a new catalogue actually means.
            //
            // Same decision as meter_readings.rate, and it fails the same silent
            // way if it is ever replaced by a join.
            $table->unsignedBigInteger('catalog_price');
            $table->unsignedBigInteger('marketing_price');

            $table->timestamps();

            // Every read of a sale loads its lines, and the product filter on the
            // sales list goes the other way.
            $table->index('sale_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // The number Oriflame prints beside the product in the catalogue.
            // Nullable, because a product can be recorded from a photograph of a
            // page before anyone bothers with the code — and unique, so the same
            // product is not entered twice under two spellings of its name.
            //
            // SQLite allows any number of NULLs in a unique index, so "no code
            // yet" stays available to every row that wants it.
            $table->string('code')->nullable()->unique();

            $table->string('name');

            // The two prices, both whole rupiah in an unsignedBigInteger for the
            // reason set out under Keuangan: SQLite has no real DECIMAL, and a
            // price is what becomes money.
            //
            // These are the *current* figures, used to prefill a sale. They are
            // not what a past sale is computed from — each sale item carries its
            // own copy. See the sale_items migration.
            $table->unsignedBigInteger('catalog_price');
            $table->unsignedBigInteger('marketing_price');

            // Products are discontinued every catalogue. Deleting one would be
            // refused by sale_items.product_id anyway, so this is the exit: an
            // inactive product stays out of the sale form's select and stays
            // readable on every sale that already names it.
            $table->boolean('is_active')->default(true);

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

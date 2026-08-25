<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // How many items the order contained.
            //
            // It does **not** change what the three price columns mean. They
            // stay totals for the whole order — what the consultant paid, what
            // the postage cost, what the customer was charged — so the margin
            // is still `catalog - marketing - shipping` and no arithmetic on
            // this feature moved. Reading them as unit prices instead would
            // silently reinterpret every row already recorded, which is the
            // class of change the tariff snapshot under Listrik kost exists to
            // prevent.
            //
            // What it is for is the count itself: a bonus of one free item per
            // 20 bought is a question about quantity, and until this column
            // existed nothing here could answer it.
            //
            // Default 1, not 0. The rows already in the table are real orders of
            // at least one item, so 1 is what they say; 0 would make them read
            // as orders of nothing and would feed a bonus calculation with a
            // figure nobody entered. The default is also what makes the column
            // addable at all — a NOT NULL column cannot be added to a table that
            // already holds rows without one.
            //
            // unsignedInteger rather than the unsignedBigInteger the rupiah
            // columns use: bigint there is about money, and a count of items in
            // one order does not approach four billion.
            $table->unsignedInteger('quantity')->default(1)->after('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};

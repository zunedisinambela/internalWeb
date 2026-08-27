<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the per-kWh rate with the amount actually paid.
 *
 * The feature was written to compute a bill: two meter figures times a price
 * per kWh. The tenant recording their own meter does not compute anything —
 * they are handed a figure and they pay it. So the row now stores that figure
 * and the multiplication is gone.
 *
 * **No recorded bill changes value.** total_amount is backfilled with exactly
 * what the accessor it replaces returned, `(end_kwh - start_kwh) * rate`, so
 * every existing period keeps the amount it already showed. What is lost is the
 * *decomposition* — how much of that amount was kWh and how much was price. That
 * is the whole point of the change and is not recoverable from what remains.
 *
 * Note the direction: total_amount stops being derived and becomes stored, which
 * is the reverse of the rule the rest of the row follows. It is not an exception
 * to that rule but a consequence of the input moving. A stored total was refused
 * while it was computable from three columns beside it, because a fourth number
 * able to disagree with them is worse than a query; now it is the number typed
 * off the bill and there is nothing for it to disagree with. usage_kwh stays
 * derived for the original reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added with a default so the column can be NOT NULL before a single row
        // has a value — SQLite refuses to add a NOT NULL column with no default
        // to a populated table, and there is no ordering of the two statements
        // that avoids it. The default comes back off below, so a row written
        // without an amount fails loudly rather than recording a free month.
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('total_amount')->default(0)->after('end_kwh');
        });

        // Exactly what MeterReading::total_amount returned before this ran.
        DB::table('meter_readings')->update([
            'total_amount' => DB::raw('(end_kwh - start_kwh) * rate'),
        ]);

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('total_amount')->default(null)->change();
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropColumn('rate');
        });
    }

    /**
     * Rebuilds the column and cannot rebuild the price.
     *
     * A rate is recovered by integer division, `total_amount / usage`, which is
     * the right answer only when the amount divides evenly — and a bill handed
     * over by a landlord rarely does. A period of zero usage has no rate at all
     * and is restored as 0, which the form's own floor of 1 would refuse. Both
     * are the honest shape of a reversal rather than defects: the price per kWh
     * stopped being a fact this table records.
     */
    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('rate')->default(0)->after('end_kwh');
        });

        DB::table('meter_readings')
            ->whereRaw('end_kwh > start_kwh')
            ->update(['rate' => DB::raw('total_amount / (end_kwh - start_kwh)')]);

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('rate')->default(null)->change();
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });
    }
};

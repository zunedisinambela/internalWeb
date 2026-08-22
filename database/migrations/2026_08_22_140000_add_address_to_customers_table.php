<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // `text`, not `string`. A full Indonesian address — jalan, RT/RW,
            // kelurahan, kecamatan, kota and kode pos — routinely runs past the
            // 255 characters a VARCHAR would give it, and the overflow is
            // silent on a database that is not in strict mode. SQLite would not
            // enforce the limit at all, so the local suite could never catch it.
            //
            // Nullable and placed after `phone` for the same reason that column
            // is nullable: most orders are handed over rather than posted, and a
            // required address would be typed as "-" on half the rows.
            $table->text('address')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};

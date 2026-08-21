<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A second thing to sign in with, beside the email address.
     *
     * The column is NOT NULL and unique, so every account can always be
     * reached by either identifier. That is what makes the login page's rule
     * — an '@' means email, anything else means username — total: there is no
     * account the username branch cannot find.
     *
     * It is added nullable, backfilled, then tightened. A NOT NULL column
     * cannot be added to a table that already holds rows, and a unique index
     * created before the backfill would refuse the second empty value.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
        });

        $this->backfill();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->unique('username');
        });
    }

    /**
     * Derives a username for every account that predates the column.
     *
     * The local part of the address is the closest thing to a name these rows
     * carry. It is lowercased and stripped to what the form accepts, so a
     * backfilled value is one the user could have typed themselves — anything
     * else would be a name nobody can reproduce at the login screen.
     */
    private function backfill(): void
    {
        $taken = [];

        foreach (DB::table('users')->select('id', 'email')->orderBy('id')->get() as $user) {
            $base = Str::lower(Str::before($user->email, '@'));
            $base = preg_replace('/[^a-z0-9_-]/', '', $base);
            $base = $base === '' ? 'user' : $base;

            // The id is the tiebreak rather than a counter: it is already
            // unique, so a second pass over the same table produces the same
            // names instead of drifting.
            $username = isset($taken[$base]) ? $base.$user->id : $base;
            $taken[$username] = true;
            $taken[$base] = true;

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};

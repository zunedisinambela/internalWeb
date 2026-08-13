<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel access moved to Filament Shield roles, so is_admin is now a second
     * source of truth that could disagree with the roles table. Drop it.
     *
     * Anyone holding is_admin should be given a role before this runs; the
     * accompanying ShieldSeeder plus AdminUserSeeder do that for the seeded
     * account.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });
    }
};

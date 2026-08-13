<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns backing Filament's app (TOTP) multi-factor authentication.
     *
     * Both are `text`, not `string`: they hold Laravel-encrypted payloads, and
     * an encrypted value is far longer than the plaintext it wraps. The
     * recovery codes column holds a JSON array of eight bcrypt hashes, which
     * overruns a 255-character column outright.
     *
     * Nullable is what makes the feature opt-in — a null secret means the user
     * has not enabled it, which is exactly how AppAuthentication::isEnabled()
     * decides.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention for the activity log.
 *
 * Kept in the same row as the other two so one screen drives all three. The
 * activitylog package has its own clean_after_days config and an
 * activitylog:clean command, but a config value cannot be edited from a page —
 * that command is left unscheduled and PruneMonitoring reads this instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            // Null means keep forever, and that is the deliberate default:
            // the activity log is where deletions of the other two tables are
            // recorded, so it is the last thing that should expire quietly.
            $table->unsignedSmallInteger('activity_retention_days')
                ->nullable()
                ->after('authentication_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $table->dropColumn('activity_retention_days');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention settings for the monitoring tables.
 *
 * These live in the database rather than config/user-monitoring.php because
 * the panel writes them. A config file cannot be edited from a request:
 * config:cache compiles the whole config into a single PHP file at deploy
 * time, so anything written afterwards is ignored, and generating PHP from
 * user input is how a settings screen turns into remote code execution.
 *
 * One row, read through MonitoringSetting::current().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_settings', function (Blueprint $table) {
            $table->id();

            // Null means keep forever. Storing "disabled" as null rather than 0
            // keeps the meaning explicit: 0 days would otherwise read as
            // "delete everything immediately".
            $table->unsignedSmallInteger('visit_retention_days')->nullable();
            $table->unsignedSmallInteger('authentication_retention_days')->nullable();

            // Lets the settings screen say whether the scheduler is actually
            // running, instead of implying that retention is being applied.
            $table->timestamp('last_pruned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_settings');
    }
};

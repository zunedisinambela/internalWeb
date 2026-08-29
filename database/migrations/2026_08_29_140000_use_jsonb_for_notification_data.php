<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filament's topbar counts unread notifications with where('data->format',
 * 'filament'), which the Postgres grammar compiles to "data"->>'format'.
 * That operator only exists for json/jsonb, but Laravel's stock notifications
 * migration declares `data` as text -- so on Postgres every authenticated page
 * died with SQLSTATE[42883]. MySQL tolerates ->> on a text column, which is
 * why the upstream migration gets away with it there.
 *
 * jsonb rather than json: it normalises whitespace and can carry a GIN index
 * if the notification payload ever needs to be searched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // A plain TYPE change cannot cast text to jsonb on its own; USING is
        // required, so this stays raw SQL rather than a Blueprint ->change().
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};

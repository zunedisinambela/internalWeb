<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            // What is written on the door. Unique, because it is what a meter
            // reading is filed under and what the landlord says out loud — two
            // rooms called "A3" would make every reading ambiguous, and nothing
            // downstream could tell which one was meant.
            $table->string('name')->unique();

            // Who lives there now. Deliberately a plain string and not a
            // relation to `users`: a tenant is not someone who signs into this
            // panel, and giving them a row in `users` would mean giving them a
            // login that canAccessPanel() has to keep refusing.
            $table->string('occupant')->nullable();

            // A room that is no longer rented out. Retiring rather than deleting
            // is the only option this schema leaves — see the restrictOnDelete
            // on meter_readings.room_id.
            $table->boolean('is_active')->default(true);

            $table->string('note')->nullable();

            $table->timestamps();

            // The list defaults to active first, then by name.
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};

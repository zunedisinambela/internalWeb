<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Nullable because a customer is often a friend whose number is
            // already in the phone. Required here it would be typed as "-" on
            // half the rows, which is worse than absent.
            $table->string('phone')->nullable();

            // Customers are retired, not deleted — sales.customer_id is
            // restrictOnDelete, so the database refuses to remove anyone who has
            // ever bought something. Same shape as rooms.is_active.
            $table->boolean('is_active')->default(true);

            $table->string('note')->nullable();

            $table->timestamps();

            // The list searches and sorts on the name, and it is the only column
            // anyone looks a customer up by.
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

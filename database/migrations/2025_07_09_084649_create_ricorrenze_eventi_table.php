<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ricorrenze_eventi', function (Blueprint $table) {
            $table->id();
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->integer('interval')->default(1);
            $table->json('by_day')->nullable(); // ['MO', 'WE']
            $table->timestamp('until')->nullable(); // null = infinite
            $table->enum('type', ['custom', 'rrule', 'manual'])->nullable(); // NEW: Recurrence engine
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ricorrenze_eventi');
    }
};

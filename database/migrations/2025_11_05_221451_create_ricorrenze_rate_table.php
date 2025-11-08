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
        Schema::create('ricorrenze_rate', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_rate_id')->constrained('piani_rate')->onDelete('cascade');
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->integer('interval')->default(1);
            $table->json('by_day')->nullable(); // ['MO', 'WE']
            $table->integer('by_month_day')->nullable();
            $table->timestamp('until')->nullable(); // null = infinite
            $table->text('rrule');
            $table->string('timezone')->default('Europe/Rome');
            $table->timestamps();
            $table->unique('piano_rate_id');
            $table->index(['frequency', 'until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ricorrenze_rate');
    }
};

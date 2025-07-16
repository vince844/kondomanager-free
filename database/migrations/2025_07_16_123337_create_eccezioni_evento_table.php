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
        Schema::create('eccezioni_evento', function (Blueprint $table) {
            $table->id();
            // Foreign key to the recurring rule
            $table->foreignId('recurrence_id')
                ->constrained('ricorrenze_eventi')
                ->onDelete('cascade');
            // Optionally link to the specific event, useful for overrides
            $table->foreignId('evento_id')
                ->nullable()
                ->constrained('eventi')
                ->nullOnDelete();
            // The date/time of the original occurrence being excluded/modified
            $table->timestamp('exception_date');
            // If true, the occurrence is skipped entirely
            $table->boolean('is_deleted')->default(true);
            // Optionally allow replacing with an updated version of the event (overrides)
            $table->json('override_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eccezioni_evento');
    }
};

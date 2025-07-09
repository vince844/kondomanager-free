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
        Schema::create('eventi', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->text('note')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time');

            // Relationships
            $table->foreignId('category_id')->nullable()->constrained('categorie_evento')->nullOnDelete();
            $table->foreignId('recurrence_id')->nullable()->constrained('ricorrenze_eventi')->nullOnDelete();

            // Visibility (public for all, private for specific users, hidden for admin use only)
            $table->enum('visibility', ['public', 'private', 'hidden'])->default('public');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventi');
    }
};

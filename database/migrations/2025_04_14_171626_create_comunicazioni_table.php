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
        Schema::create('comunicazioni', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('description');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->enum('priority', ['bassa', 'media', 'alta', 'urgente'])->default('bassa');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_private')->default(false);
            $table->boolean('is_published')->default(true);
            $table->boolean('is_approved')->default(true);
            $table->boolean('can_comment')->default(true);
            $table->string('slug')->unique();
            $table->string('reference')->unique()->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comunicazioni');
    }
};

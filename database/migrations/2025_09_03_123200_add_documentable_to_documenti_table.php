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
        Schema::table('documenti', function (Blueprint $table) {
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->string('documentable_type')->nullable();
            $table->index(['documentable_id', 'documentable_type'], 'documentable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documenti', function (Blueprint $table) {
            $table->dropIndex('documentable_index');
            $table->dropColumn(['documentable_id', 'documentable_type']);
        });
    }
};

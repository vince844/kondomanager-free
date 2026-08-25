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
        if (! Schema::hasTable('backups')) {
            Schema::create('backups', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('filename')->nullable();
                $table->string('disk')->default('backups');
                $table->string('status')->default('pending')->index();
                // Snapshot di avanzamento mostrato alla UI (percentuale, fase, contatori)
                $table->json('progress')->nullable();
                // Stato interno riprendibile dello step-runner (tabella corrente,
                // ultima chiave primaria, indice file nello zip, ...)
                $table->json('checkpoint')->nullable();
                // Copia del manifest.json incluso nello zip, per mostrare i
                // dettagli in UI senza dover aprire l'archivio
                $table->json('manifest')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('checksum', 64)->nullable();
                $table->text('error')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};

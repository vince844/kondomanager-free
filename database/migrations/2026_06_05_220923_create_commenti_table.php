<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commenti', function (Blueprint $table) {
            $table->id();

            // Relazione polimorfica → commentable_type + commentable_id (+ indice composto)
            $table->morphs('commentable');

            // Autore (utente autenticato)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Denormalizzazione per scoping multi-tenancy
            $table->foreignId('condominio_id')
                  ->nullable()
                  ->constrained('condomini')
                  ->cascadeOnDelete();

            $table->text('corpo');

            // Moderazione/approvazione (no delete duri)
            // - pubblicato: visibile a tutti
            // - in_attesa:  visibile solo a chi ha APPROVE_COMMENTS_SEGNALAZIONI
            // - nascosto:   rimosso da un moderatore
            $table->enum('stato', ['pubblicato', 'in_attesa', 'nascosto'])->default('in_attesa');
            $table->foreignId('moderato_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderato_at')->nullable();

            // Indicatore "modificato"
            $table->timestamp('modificato_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id', 'stato']);
            $table->index('condominio_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Pattern cleanup KI: guard hasColumn() per gestire esecuzioni parziali.
     */
    public function down(): void
    {
        Schema::dropIfExists('commenti');
    }
};

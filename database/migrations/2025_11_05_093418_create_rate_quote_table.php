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
        Schema::create('rate_quote', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rata_id')->constrained('rate')->onDelete('cascade');
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->foreignId('immobile_id')->nullable()->constrained('immobili')->onDelete('set null');
            $table->bigInteger('importo')->default(0); // in centesimi
            $table->bigInteger('importo_pagato')->default(0); // in centesimi
            $table->enum('stato', ['da_pagare', 'parzialmente_pagata', 'pagata', 'annullata'])->default('da_pagare');
            $table->date('data_scadenza')->nullable();
            $table->date('data_pagamento')->nullable();
            $table->string('riferimento_pagamento')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('stato');
            $table->index('data_scadenza');
            $table->index('data_pagamento');
            $table->index(['rata_id', 'stato']); 
            $table->index(['anagrafica_id', 'stato']); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_quote');
    }
};

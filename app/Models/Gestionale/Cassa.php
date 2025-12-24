<?php

namespace App\Models\Gestionale;

use App\Models\ContoCorrente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cassa extends Model
{
    protected $table = 'casse';
    
    protected $fillable = [
        'condominio_id', 
        'nome', 
        'descrizione',
        'tipo', 
        'conto_contabile_id', 
        'attiva', 
        'note'
    ];

    // Relazione con il Conto Corrente (solo se tipo = banca)
    public function contoCorrente(): MorphOne
    {
        return $this->morphOne(ContoCorrente::class, 'contable');
    }

    // Relazione con il Piano dei Conti (Obbligatoria da schema)
    public function contoContabile(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class);
    }
}

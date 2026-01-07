<?php

namespace App\Models\Gestionale;

use App\Models\ContoCorrente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cassa extends Model
{
    protected $table = 'casse';
    
    protected $fillable = [
        'condominio_id', 
        'nome', 
        'descrizione',
        'tipo', 
        'conto_contabile_id', 
        'saldo_iniziale',
        'attiva', 
        'note'
    ];

    public function movimenti(): HasMany
    {
        return $this->hasMany(RigaScrittura::class, 'cassa_id');
    }

    public function contoCorrente(): MorphOne
    {
        return $this->morphOne(ContoCorrente::class, 'contable');
    }

    public function contoContabile(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class);
    }
}
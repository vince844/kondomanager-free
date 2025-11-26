<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditoResiduo extends Model
{
    protected $table = 'crediti_residui';

    protected $fillable = [
        'anagrafica_id',
        'condominio_id',
        'esercizio_id',
        'gestione_id',
        'piano_rate_id',
        'importo',
        'data_generazione',
        'stato',
        'note',
    ];

    protected $casts = [
        'data_generazione' => 'date',
        'importo' => 'integer',
    ];

    public function pianoRate(): BelongsTo
    {
        return $this->belongsTo(PianoRate::class);
    }

    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Anagrafica::class);
    }
}

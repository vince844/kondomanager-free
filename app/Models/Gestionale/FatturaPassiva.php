<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Model;

class FatturaPassiva extends Model
{
    use HasProtocolNumber;

    protected $table = 'fatture_passive';
    protected $guarded = ['id'];

    // stato_approvazione — ciclo di vita:
    //   da_approvare   → inserita, in attesa di revisione
    //   approvata      → ratificata dall'amministratore o dall'assemblea
    //   contestata     → il condominio ha sollevato obiezioni
    //   sforo_motivato → registrata con override budget, ratifica assembleare pendente
    protected $casts = [
        'data_documento'      => 'date',
        'data_scadenza'       => 'date',
        'is_pregresso'        => 'boolean',
        'dati_extra'          => 'array',
        // Cast espliciti per i BigInt: senza questi Laravel li restituisce
        // come stringa in alcuni contesti, causando errori nei calcoli frontend
        'importo_imponibile'  => 'integer',
        'importo_iva'         => 'integer',
        'importo_ritenuta'    => 'integer',
        'totale_documento'    => 'integer',
        'netto_a_pagare'      => 'integer',
    ];

    public function righe()
    {
        return $this->hasMany(RigaFattura::class);
    }

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class);
    }

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    public function scritture()
    {
        return $this->belongsToMany(ScritturaContabile::class, 'fattura_scrittura')
            ->withPivot(['importo_allocato', 'tipo'])
            ->withTimestamps();
    }

    public function documenti()
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    // Scope utile per la dashboard "Da ratificare"
    public function scopeSforiPendenti($query)
    {
        return $query->where('stato_approvazione', 'sforo_motivato');
    }
}

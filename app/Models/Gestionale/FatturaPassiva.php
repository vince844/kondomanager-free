<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Saldo;
use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
        'data_documento'             => 'date',
        'data_scadenza'              => 'date',
        'is_pregresso'               => 'boolean',
        'data_competenza_originaria' => 'date', // NUOVO: Cast della data origine
        'dati_extra'                 => 'array',
        'importo_imponibile'         => 'integer',
        'importo_iva'                => 'integer',
        'importo_ritenuta'           => 'integer',
        'totale_documento'           => 'integer',
        'netto_a_pagare'             => 'integer',
    ];

    // ── RELAZIONI ORIGINALI (Ripristinate) ───────────────────────────────────

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


    // ── NUOVE RELAZIONI PER DEBITI PREGRESSI ─────────────────────────────────

    /**
     * Il debito verso il fornitore nello Stato Patrimoniale Iniziale
     * (Valorizzato solo se is_pregresso = true)
     */
    public function debitoPatrimonialeIniziale()
    {
        return $this->belongsTo(Saldo::class, 'saldo_patrimoniale_id');
    }

    /**
     * Le fonti di copertura per questa fattura (Il Double Lock)
     */
    public function coperture()
    {
        return $this->hasMany(FatturaCopertura::class, 'fattura_passiva_id');
    }


    // ── SCOPES & METODI HELPER (Per il Widget e le Analisi) ──────────────────

    public function scopeSforiPendenti($query)
    {
        return $query->where('stato_approvazione', 'sforo_motivato');
    }

    public function scopePregresse($query)
    {
        return $query->where('is_pregresso', true);
    }

    /**
     * La Sentinella: Cerca le fatture pregresse a rischio prescrizione (> 5 anni)
     */
    public function scopeInPrescrizione($query)
    {
        return $query->pregresse()
                     ->whereNotNull('data_competenza_originaria')
                     ->where('data_competenza_originaria', '<=', Carbon::now()->subYears(5));
    }

    /**
     * Calcola quanto di questa fattura è già stato "coperto"
     * (Somma in centesimi)
     */
    public function getImportoCopertoAttribute(): int
    {
        return $this->coperture()->sum('importo');
    }

    /**
     * Restituisce l'eventuale scoperto (es. per la Copertura Mista)
     */
    public function getScopertoAttribute(): int
    {
        return $this->totale_documento - $this->importo_coperto;
    }
}
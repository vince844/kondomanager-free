<?php

namespace App\Models\Gestionale;

use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Saldo;
use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Builder;
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
        'data_documento'                => 'date',
        'data_scadenza'                 => 'date',
        'is_pregresso'                  => 'boolean',
        'data_competenza_originaria'    => 'date',
        'dati_extra'                    => 'array',
        'importo_imponibile'            => 'integer',
        'importo_iva'                   => 'integer',
        'importo_ritenuta'              => 'integer',
        'totale_documento'              => 'integer',
        'netto_a_pagare'                => 'integer',
        // ── v1.9.1 — Pagamento Fatture ──────────────────────────────────────
        // stato_pagamento è un READ MODEL materializzato: ricalcolato da
        // PagamentoFornitoreService::ricalcolaStatoFattura() ad ogni modifica pivot.
        // Non aggiornare direttamente — usare il service.
        'stato_pagamento'               => StatoPagamentoFattura::class,
        'ultimo_ricalcolo_pagamento_at' => 'datetime',
        // Change counter per invalidazione cache/UI — NON è un optimistic lock.
        'versione_allocazioni'          => 'integer',
        'inconsistenza_pagamento'       => 'boolean',
        // ultimo_errore_ricalcolo → TEXT, nessun cast necessario
    ];

    // ── Relazioni originali ──────────────────────────────────────────────────

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

    /**
     * Scritture contabili collegate tramite la pivot fattura_scrittura.
     *
     * FK esplicite necessarie: la pivot usa fattura_passiva_id e scrittura_contabile_id
     * che NON seguono la convenzione Laravel (che inferisce fattura_id / scrittura_id).
     *
     * Tipi pivot (TipoAllocazioneFattura):
     *   - competenza    → registrazione iniziale debito (NON partecipa al saldo)
     *   - pagamento     → chiusura tramite cassa (partecipa al saldo)
     *   - compensazione → chiusura tramite NC/netting (partecipa al saldo)
     *
     * INVARIANTE storni: importo_allocato può essere NEGATIVO.
     */
    public function scritture()
    {
        return $this->belongsToMany(
            ScritturaContabile::class,
            'fattura_scrittura',          // tabella pivot
            'fattura_passiva_id',         // FK di questa classe nella pivot
            'scrittura_contabile_id'      // FK dell'altra classe nella pivot
        )
        ->using(FatturaScrittura::class)
        ->withPivot(['importo_allocato', 'tipo'])
        ->withTimestamps();
    }

    public function documenti()
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    // ── Relazione diretta hasMany per withSum() ───────────────────────────────

    /**
     * Righe pivot filtrate per tipo pagamento/compensazione.
     *
     * Usata con withSum('allocazioniPagamento', 'importo_allocato') per calcolare
     * il totale pagato come subquery, evitando GROUP BY + aggregazione manuale.
     */
    public function allocazioniPagamento()
    {
        return $this->hasMany(FatturaScrittura::class, 'fattura_passiva_id')
                     ->whereIn('tipo', TipoAllocazioneFattura::perCalcoloSaldo());
    }

    // ── Relazioni pregressi ──────────────────────────────────────────────────

    public function debitoPatrimonialeIniziale()
    {
        return $this->belongsTo(Saldo::class, 'saldo_patrimoniale_id');
    }

    public function coperture()
    {
        return $this->hasMany(FatturaCopertura::class, 'fattura_passiva_id');
    }

    // ── Scopes originali ─────────────────────────────────────────────────────

    public function scopeSforiPendenti(Builder $query): Builder
    {
        return $query->where('stato_approvazione', 'sforo_motivato');
    }

    public function scopePregresse(Builder $query): Builder
    {
        return $query->where('is_pregresso', true);
    }

    public function scopeInPrescrizione(Builder $query): Builder
    {
        return $query->pregresse()
                     ->whereNotNull('data_competenza_originaria')
                     ->where('data_competenza_originaria', '<=', Carbon::now()->subYears(5));
    }

    // ── Accessor originali ───────────────────────────────────────────────────

    public function getImportoCopertoAttribute(): int
    {
        return $this->coperture()->sum('importo');
    }

    public function getScopertoAttribute(): int
    {
        return $this->totale_documento - $this->importo_coperto;
    }

    // ── v1.9.1 — Accessor pagamento ──────────────────────────────────────────

    /**
     * Somma delle allocazioni di PAGAMENTO e COMPENSAZIONE sulla pivot.
     *
     * INVARIANTE: esclude SEMPRE tipo='competenza'.
     * La competenza è la registrazione iniziale del debito — non chiude il debito.
     *
     * Attenzione N+1: non usare in loop senza eager loading.
     */
    public function getTotaleAllocatoAttribute(): int
    {
        return (int) $this->scritture()
            ->wherePivotIn('tipo', TipoAllocazioneFattura::perCalcoloSaldo())
            ->sum('fattura_scrittura.importo_allocato');
    }

    /**
     * Residuo da saldare = netto_a_pagare − totale_allocato.
     * Negativo = overpayment → anomalia, vedi inconsistenza_pagamento.
     */
    public function getResiduoAttribute(): int
    {
        return $this->netto_a_pagare - $this->totale_allocato;
    }

    // ── v1.9.1 — Scopes pagamento ─────────────────────────────────────────────

    /** Fatture senza alcun pagamento registrato (totale allocato = 0). */
    public function scopeAperte(Builder $query): Builder
    {
        return $query->where('stato_pagamento', StatoPagamentoFattura::APERTA);
    }

    /** Fatture con residuo da saldare (aperte o parzialmente pagate). */
    public function scopeConResiduo(Builder $query): Builder
    {
        return $query->whereIn('stato_pagamento', [
            StatoPagamentoFattura::APERTA,
            StatoPagamentoFattura::PARZIALE,
        ]);
    }

    /**
     * Fatture approvate con residuo — pronte per il pagamento.
     * Usato dal Payment Sentinel e dalla Distinta Pagamento.
     */
    public function scopePagabili(Builder $query): Builder
    {
        return $query->where('stato_approvazione', 'approvata')
                     ->whereIn('stato_pagamento', [
                         StatoPagamentoFattura::APERTA,
                         StatoPagamentoFattura::PARZIALE,
                     ]);
    }

    /**
     * Fatture con anomalia contabile — richiedono riconciliazione manuale.
     */
    public function scopeConInconsistenza(Builder $query): Builder
    {
        return $query->where('inconsistenza_pagamento', true);
    }
}
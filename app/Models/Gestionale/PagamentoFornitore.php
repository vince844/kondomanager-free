<?php

namespace App\Models\Gestionale;

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoDetrazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Metadati documentali di un pagamento al fornitore.
 *
 * Relazione 1:1 con ScritturaContabile (voce del giornale contabile).
 * La logica contabile vive in scritture_contabili + fattura_scrittura (pivot).
 * Questa tabella cattura: IBAN, causale, snapshot fornitore, Bonifico Parlante, ecc.
 *
 * ── Pattern storno (self-referential) ───────────────────────────────────────
 * Il record di storno punta al pagamento originale tramite pagamento_padre_id.
 * Ogni record mantiene il proprio scrittura_contabile_id (la scrittura inversa).
 * Flusso:
 *   1. Crea storno #N con pagamento_padre_id = id_originale, stato = 'confermato'
 *   2. Aggiorna originale: stato = 'stornato', motivo_storno = '...'
 * Navigazione: $pagamento->storno() / $storno->padre()
 *
 * ── Storno cross-esercizio (Variante B1) ────────────────────────────────────
 * Se l'esercizio del pagamento originale è chiuso, lo storno viene registrato
 * nell'esercizio corrente aperto → storno_cross_esercizio = true, esercizio_storno_id valorizzato.
 */
class PagamentoFornitore extends Model
{
    protected $table = 'pagamenti_fornitori';

    protected $guarded = ['id'];

    protected $casts = [
        'data_pagamento'         => 'date',
        'data_valuta'            => 'date',
        'metodo_pagamento'       => MetodoPagamento::class,
        'stato'                  => StatoPagamentoFornitore::class,
        'tipo_detrazione'        => TipoDetrazione::class,
        'bonifico_parlante'      => 'boolean',
        'storno_cross_esercizio' => 'boolean',
        'fornitore_snapshot'     => 'array',
        'beneficiari_detrazione' => 'array',
        'importo_lordo'          => 'integer',
        'importo_ritenuta'       => 'integer',
        'importo_netto'          => 'integer',
        'importo_commissione'    => 'integer',
    ];

    // ── Relazioni core ───────────────────────────────────────────────────────

    /**
     * Scrittura contabile corrispondente (1:1 vincolante).
     * Immutabile dopo la creazione del pagamento.
     */
    public function scrittura(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_contabile_id');
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class);
    }

    /**
     * Conto bancario o cassa da cui esce la liquidità.
     * Tipicamente ruolo = 'conto_bancario' o 'cassa' su conti_contabili.
     */
    public function contoCorrente(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class, 'conto_corrente_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Storno (self-referential) ────────────────────────────────────────────

    /**
     * Il pagamento originale che questo record storna.
     * NULL su tutti i record non-storno.
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(PagamentoFornitore::class, 'pagamento_padre_id');
    }

    /**
     * Il record di storno che annulla questo pagamento.
     * NULL se il pagamento non è stato stornato.
     */
    public function storno(): HasOne
    {
        return $this->hasOne(PagamentoFornitore::class, 'pagamento_padre_id');
    }

    /**
     * Esercizio in cui è registrato lo storno cross-esercizio (Variante B1).
     * NULL se storno_cross_esercizio = false.
     */
    public function esercizioStorno(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class, 'esercizio_storno_id');
    }

    // ── Relazioni delegate ───────────────────────────────────────────────────

    /**
     * Documenti allegati (ricevuta bonifico, distinta, ecc.).
     */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    /**
     * Fatture toccate da questo pagamento — delegate via scrittura → pivot.
     *
     * NON è una relazione Eloquent diretta: non eager-loadable con with('fatture').
     * Per eager loading: $pagamento->load('scrittura.fatture').
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function fatture()
    {
        return $this->scrittura->fatture();
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeConfermati(Builder $query): Builder
    {
        return $query->where('stato', StatoPagamentoFornitore::CONFERMATO);
    }

    public function scopeStornati(Builder $query): Builder
    {
        return $query->where('stato', StatoPagamentoFornitore::STORNATO);
    }

    /** Pagamenti di bonifico (richiedono IBAN valorizzato). */
    public function scopeBonifici(Builder $query): Builder
    {
        return $query->where('metodo_pagamento', MetodoPagamento::BONIFICO);
    }

    /** Pagamenti con Bonifico Parlante attivo (detrazioni fiscali). */
    public function scopeBonificiParlanti(Builder $query): Builder
    {
        return $query->where('bonifico_parlante', true);
    }
}
<?php

namespace App\Models\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;

/**
 * Rappresenta un movimento in Prima Nota (Libro Giornale) del gestionale.
 * Ogni scrittura è composta da una testata e da più righe di dettaglio in Dare/Avere.
 *
 * PRINCIPIO LEDGER-CENTRIC: questa tabella è la verità immutabile.
 * - Gli storni creano scritture inverse (scrittura_padre_id → originale), mai soft-delete.
 * - Il blocco per esercizio chiuso si applica su questa tabella (esercizio_id),
 *   non sulle fatture passive.
 *
 * NOTA SoftDeletes: presente per retrocompatibilità con codice pre-v1.9.1.
 * Le nuove operazioni (pagamenti, storni) NON devono mai usare soft-delete:
 * il ledger è append-only per principio architetturale.
 */
class ScritturaContabile extends Model
{
    use SoftDeletes, HasProtocolNumber;

    protected $table = 'scritture_contabili';

    protected $fillable = [
        'condominio_id',
        'gestione_id',
        'esercizio_id',
        'data_registrazione',
        'data_competenza',
        'numero_protocollo',
        'causale',
        'descrizione',
        'tipo_movimento',
        'scrittura_padre_id',
        'stato',
        'created_by',
        'registrata_by',
        'registrata_at',
        'note',
        // v1.9.1: UUID per prevenire doppi inserimenti su retry/concorrenza.
        // Il service lo imposta prima del DB::transaction().
        // Su duplicato: QueryException su UNIQUE → IdempotencyKeyDuplicateException.
        'idempotency_key',
    ];

    protected $casts = [
        'condominio_id'      => 'integer',
        'data_registrazione' => 'date',
        'data_competenza'    => 'date',
        'registrata_at'      => 'datetime',
        // v1.9.1: tipo_movimento era ENUM MySQL, ora VARCHAR(50).
        // Il Backed Enum gestisce sia lettura che scrittura.
        'tipo_movimento'     => TipoMovimentoContabile::class,
    ];

    // ── Relazioni ────────────────────────────────────────────────────────────

    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class, 'gestione_id');
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function righe(): HasMany
    {
        return $this->hasMany(RigaScrittura::class, 'scrittura_id');
    }

    public function documentabile(): MorphTo
    {
        return $this->morphTo();
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_padre_id');
    }

    public function figlie(): HasMany
    {
        return $this->hasMany(ScritturaContabile::class, 'scrittura_padre_id');
    }

    public function quotePagate(): BelongsToMany
    {
        return $this->belongsToMany(
            RataQuote::class,
            'quota_scrittura',
            'scrittura_contabile_id',
            'rate_quota_id'
        )
        ->withPivot(['importo_pagato', 'data_pagamento'])
        ->withTimestamps();
    }

    /**
     * Fatture passive collegate tramite la pivot fattura_scrittura.
     *
     * FK esplicite necessarie: la pivot usa fattura_passiva_id e scrittura_contabile_id
     * che NON seguono la convenzione Laravel (che inferisce fattura_id / scrittura_id).
     *
     * Relazione inversa di FatturaPassiva::scritture().
     * Usata da PagamentoFornitore::fatture() tramite delega.
     */
    public function fatture(): BelongsToMany
    {
        return $this->belongsToMany(
            FatturaPassiva::class,
            'fattura_scrittura',          // tabella pivot
            'scrittura_contabile_id',     // FK di questa classe nella pivot
            'fattura_passiva_id'          // FK dell'altra classe nella pivot
        )
        ->using(FatturaScrittura::class)
        ->withPivot(['importo_allocato', 'tipo'])
        ->withTimestamps();
    }

    /**
     * Il pagamento fornitore collegato a questa scrittura (1:1 inversa).
     *
     * v1.9.1: ogni scrittura di tipo pagamento_fornitore ha esattamente
     * un record PagamentoFornitore che ne contiene i metadati documentali.
     * NULL se la scrittura non è legata a un pagamento fornitore.
     */
    public function pagamentoFornitore(): HasOne
    {
        return $this->hasOne(PagamentoFornitore::class, 'scrittura_contabile_id');
    }

    // ── Metodi ───────────────────────────────────────────────────────────────

    /**
     * Verifica il principio della Partita Doppia.
     * La somma in Dare deve essere uguale alla somma in Avere.
     */
    public function isQuadrata(): bool
    {
        $dare  = $this->righe->where('tipo_riga', 'dare')->sum('importo');
        $avere = $this->righe->where('tipo_riga', 'avere')->sum('importo');

        return $dare === $avere;
    }
}
<?php

namespace App\Models\Gestionale;

use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany; 
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;

/**
 * Class ScritturaContabile
 * * Rappresenta un movimento in Prima Nota (Libro Giornale) del gestionale.
 * Ogni scrittura è composta da una testata e da più righe di dettaglio in Dare/Avere.
 *
 * @property int $id
 * @property int $condominio_id
 * @property int|null $gestione_id
 * @property int $esercizio_id
 * @property \Illuminate\Support\Carbon|null $data_registrazione
 * @property \Illuminate\Support\Carbon|null $data_competenza
 * @property string|null $numero_protocollo
 * @property string $causale
 * @property string|null $descrizione
 * @property string $tipo_movimento
 * @property int|null $scrittura_padre_id
 * @property string $stato
 * @property int|null $created_by
 * @property int|null $registrata_by
 * @property \Illuminate\Support\Carbon|null $registrata_at
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Condominio $condominio
 * @property-read Gestione|null $gestione
 * @property-read Esercizio $esercizio
 * @property-read \Illuminate\Database\Eloquent\Collection|RigaScrittura[] $righe
 * @property-read ScritturaContabile|null $padre
 * @property-read \Illuminate\Database\Eloquent\Collection|ScritturaContabile[] $figlie
 * @property-read \Illuminate\Database\Eloquent\Collection|RataQuote[] $quotePagate
 * @property-read Model|\Eloquent $documentabile
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
        'note'
    ];

    protected $casts = [
        'data_registrazione' => 'date',
        'data_competenza'    => 'date',
        'registrata_at'      => 'datetime',
    ];

    // --- RELAZIONI ---

    /**
     * Ottiene la gestione (Ordinaria, Straordinaria, ecc.) a cui appartiene la scrittura.
     */
    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class, 'gestione_id');
    }

    /**
     * Ottiene il condominio in cui è stata registrata l'operazione.
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    /**
     * Ottiene l'esercizio contabile (es. Anno 2026) di riferimento.
     */
    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    /**
     * Ottiene le singole righe (movimenti in Dare o Avere) che compongono questa scrittura.
     */
    public function righe(): HasMany
    {
        // Usa 'scrittura_id' come definito nella migration righe_scritture
        return $this->hasMany(RigaScrittura::class, 'scrittura_id');
    }

    /**
     * Relazione polimorfica per collegare la scrittura a un documento (es. FatturaPassiva).
     */
    public function documentabile(): MorphTo
    {
        return $this->morphTo();
    }
    
    /**
     * Verifica il principio della Partita Doppia: la somma dei movimenti in Dare 
     * deve essere esattamente uguale alla somma dei movimenti in Avere.
     * * @return bool True se la scrittura è bilanciata (quadrata), False altrimenti.
     */
    public function isQuadrata(): bool
    {
        $dare = $this->righe->where('tipo_riga', 'dare')->sum('importo');
        $avere = $this->righe->where('tipo_riga', 'avere')->sum('importo');
        
        return $dare === $avere;
    }

    /**
     * Ottiene la scrittura contabile "Madre" da cui deriva questo movimento 
     * (es. la scrittura padre di uno storno_credito o di una rettifica).
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_padre_id');
    }

    /**
     * Ottiene le scritture derivate (figlie) collegate a questa registrazione originaria 
     * (es. le scritture di compensazione credito o gli storni generati a cascata).
     */
    public function figlie(): HasMany
    {
        return $this->hasMany(ScritturaContabile::class, 'scrittura_padre_id');
    }

    /**
     * RELAZIONE INVERSA (Pivot)
     * Restituisce tutte le quote rateali pagate (o parzialmente pagate) con questa scrittura.
     * Sfrutta la tabella `quota_scrittura` per leggere o manipolare (tramite Eager Loading) 
     * l'importo storico applicato alla singola rata, fondamentale per Storni e Compensazioni.
     */
    public function quotePagate(): BelongsToMany
    {
        return $this->belongsToMany(
            RataQuote::class, 
            'quota_scrittura',       // Tabella pivot
            'scrittura_contabile_id', // Chiave esterna in pivot per questo modello
            'rate_quota_id'           // Chiave esterna in pivot per l'altro modello
        )
        ->withPivot(['importo_pagato', 'data_pagamento']) 
        ->withTimestamps();
    }
}
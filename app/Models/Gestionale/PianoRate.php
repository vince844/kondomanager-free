<?php

namespace App\Models\Gestionale;

use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Database\Factories\Gestionale\PianoRateFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modello PianoRate
 * * Rappresenta un piano di ripartizione e incasso delle spese (Rate) 
 * per una specifica Gestione (ordinaria o straordinaria) del Condominio.
 * Gestisce il flusso di approvazione legale e l'audit trail delle delibere.
 */
class PianoRate extends Model
{
    use HasFactory;

    protected $table = 'piani_rate';

    protected $fillable = [
        'gestione_id',
        'condominio_id',
        'nome',
        'descrizione',
        'metodo_distribuzione',
        'numero_rate',
        'giorno_scadenza',
        'data_inizio',
        'attivo',
        'note',
        'nota_scoperti',
        'stato',
        // --- CAMPI DELIBERA E AUDIT ---
        'data_delibera_assemblea',
        'numero_verbale',
        'nota_approvazione',
        'approvato_da_user_id',
        'approvato_il',
        // --- FEATURE 2: PIANI STRAORDINARI ---
        'tipo',
        'tipo_autorizzazione',
        'motivazione_autorizzazione',
    ];

    protected $casts = [
        'stato'                   => StatoPianoRate::class,
        'data_inizio'             => 'date',
        'data_delibera_assemblea' => 'date',      // Cast automatico a Carbon per formattazione agevole
        'approvato_il'            => 'datetime',  // Cast automatico a Carbon con orario
        'attivo'                  => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELAZIONI
    |--------------------------------------------------------------------------
    */

    /**
     * Ottiene la Gestione (ordinaria/straordinaria) a cui appartiene questo piano.
     * Un piano rate non può esistere al di fuori di una gestione attiva.
     *
     * @return BelongsTo
     */
    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    /**
     * Ottiene il Condominio proprietario di questo piano rate.
     *
     * @return BelongsTo
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    /**
     * Ottiene la configurazione di ricorrenza (es. mensile, bimestrale) 
     * associata alla generazione automatica delle scadenze di questo piano.
     *
     * @return HasOne
     */
    public function ricorrenza(): HasOne
    {
        return $this->hasOne(RicorrenzaRata::class);
    }

    /**
     * Ottiene tutte le Rate fisiche (scadenze) generate da questo piano.
     * Ogni rata conterrà a sua volta le singole quote addebitate ai condòmini.
     *
     * @return HasMany
     */
    public function rate(): HasMany
    {
        return $this->hasMany(Rata::class);
    }

    /**
     * Ottiene lo storico dei movimenti di budget associati a questo piano.
     * Cruciale per la funzione "Sposta Spesa" e per tracciare i travasi
     * di fondi tra un capitolo e l'altro (Audit Log contabile).
     *
     * @return HasMany
     */
    public function budgetMovements(): HasMany
    {
        return $this->hasMany(BudgetMovement::class);
    }

    /**
     * Ottiene i capitoli di spesa (Conti) coperti da questo piano rate.
     * * CRUCIALE: Il metodo withPivot() carica i campi 'importo' e 'note' dalla 
     * tabella di collegamento. Questo permette al piano rate di coprire un 
     * capitolo anche solo parzialmente (es. finanzio solo 1.000€ su 5.000€ totali).
     *
     * @return BelongsToMany
     */
    public function capitoli(): BelongsToMany
    {
        return $this->belongsToMany(Conto::class, 'piano_rate_capitoli', 'piano_rate_id', 'conto_id')
                    ->withPivot(['importo', 'note']) 
                    ->withTimestamps();
    }

    /**
     * Ottiene l'amministratore (User) che ha verificato il verbale 
     * e approvato legalmente questo piano rate (Audit Trail).
     *
     * @return BelongsTo
     */
    public function approvatoDa(): BelongsTo
    { 
        return $this->belongsTo(User::class, 'approvato_da_user_id'); 
    }

    /**
     * Le fatture collegate a questo piano rate (solo per piani straordinari).
     */
    public function fatture(): BelongsToMany
    {
        return $this->belongsToMany(FatturaPassiva::class, 'piano_rate_fatture');
    }

    /**
     * Le fatture impreviste o ad personam associate a questo piano rate straordinario.
     */
    public function fattureStraordinarie(): BelongsToMany
    {
        return $this->belongsToMany(FatturaPassiva::class, 'piano_rate_fatture')
                    ->withPivot('importo_collegato')
                    ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | FACTORIES
    |--------------------------------------------------------------------------
    */

    /**
     * Collega esplicitamente la Factory corretta per i test automatizzati.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return PianoRateFactory::new();
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modello Saldo
 * * Rappresenta un singolo "cassetto" finanziario del Wallet di un condòmino.
 * A differenza delle versioni precedenti, ogni saldo è ora vincolato a una specifica
 * gestione (Ordinaria, Straordinaria, ecc.) per garantire la separazione dei fondi
 * richiesta dall'Art. 1130-bis c.c.
 */
class Saldo extends Model
{
    protected $table = 'saldi';

    /**
     * Campi assegnabili massivamente.
     * Abbiamo aggiunto gestione_id e is_applicato per supportare il nuovo sistema a Wallet.
     */
    protected $fillable = [
        'esercizio_id',   // L'esercizio di riferimento (es. 2026)
        'condominio_id',  // Il condominio di appartenenza
        'anagrafica_id',  // Il soggetto (proprietario/inquilino)
        'immobile_id',    // L'unità immobiliare specifica
        'gestione_id',    // La gestione a cui appartiene il debito/credito (Novità v1.9)
        'saldo_iniziale', // Debito (-) o Credito (+) pregresso in centesimi
        'saldo_finale',   // Saldo risultante a fine esercizio
        'origine',        // 'manuale', 'importato', 'automatico'
        'is_applicato',   // Se true, il saldo è bloccato perché già inserito in un piano rate (Novità v1.9)
    ];

    /**
     * Cast degli attributi.
     * Gestiamo i saldi come integer (centesimi) per evitare problemi di approssimazione decimale.
     */
    protected $casts = [
        'saldo_iniziale' => 'integer',
        'saldo_finale'   => 'integer',
        'is_applicato'   => 'boolean',
        'origine'        => 'string',
    ];

    // --- RELAZIONI ---

    /**
     * Ottiene la gestione associata a questo specifico saldo.
     * Fondamentale per dividere i debiti (es. Ordinaria vs Lavori Tetto).
     */
    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    /**
     * L'esercizio contabile in cui questo saldo è stato registrato come "iniziale".
     */
    public function esercizio(): BelongsTo
    { 
        return $this->belongsTo(Esercizio::class); 
    }

    /**
     * Il condominio di riferimento.
     */
    public function condominio(): BelongsTo
    { 
        return $this->belongsTo(Condominio::class); 
    }

    /**
     * Il condòmino a cui appartiene questo debito/credito.
     */
    public function anagrafica(): BelongsTo
    { 
        return $this->belongsTo(Anagrafica::class); 
    }

    /**
     * L'unità immobiliare a cui è agganciato il saldo (per gestire la solidarietà nel subentro).
     */
    public function immobile(): BelongsTo
    { 
        return $this->belongsTo(Immobile::class); 
    }

    // --- HELPER METODS ---

    /**
     * Determina se il saldo rappresenta un debito per il condòmino.
     */
    public function isDebito(): bool
    {
        return $this->saldo_iniziale < 0;
    }

    /**
     * Determina se il saldo rappresenta un credito per il condòmino.
     */
    public function isCredito(): bool
    {
        return $this->saldo_iniziale > 0;
    }
}
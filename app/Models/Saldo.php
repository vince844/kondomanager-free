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
        'piano_rate_id',  // Il piano rate che ha assorbito questo saldo (chi ha chiuso il lucchetto)
        // Positivo = DEBITO del condòmino, negativo = CREDITO. È la convenzione di tutto il
        // progetto — vedi `docs/architettura_saldi_iniziali.md`. Il commento diceva il
        // contrario, e stava proprio sulla riga che chiunque legge per capire il segno.
        'saldo_iniziale', // Debito (+) o Credito (-) pregresso in centesimi
        'saldo_finale',   // Saldo risultante a fine esercizio
        'origine',        // 'manuale', 'importato', 'automatico'
        'is_applicato',   // Se true, il saldo è bloccato perché già inserito in un piano rate (Novità v1.9)
        'fornitore_id',
        'descrizione'
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
     * Il piano rate che ha assorbito questo saldo, cioè chi ha chiuso il
     * lucchetto. Null quando il saldo è libero: senza questo legame lo sblocco
     * andava dedotto leggendo le quote generate, e ogni ricalcolo lo perdeva.
     */
    public function pianoRate(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Gestionale\PianoRate::class, 'piano_rate_id');
    }

    /**
     * Questo saldo è ormai intoccabile?
     *
     * Non basta che sia stato assorbito da un piano: finché quel piano non è
     * emesso o incassato è ancora interamente riscrivibile, e vietare la
     * correzione del saldo che lo alimenta sarebbe più severo di quanto il
     * sistema sia con il piano stesso.
     *
     * Restano bloccati i saldi con `is_applicato` ma senza titolare: sono i
     * debiti verso fornitori e i dati storici anteriori alla beta.32, per i
     * quali non è possibile stabilire quale piano li tenga.
     */
    public function eBloccato(): bool
    {
        if ($this->pianoRate) {
            return $this->pianoRate->eImmutabile();
        }

        return (bool) $this->is_applicato;
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

    // `isDebito()` e `isCredito()` sono stati RIMOSSI nella beta.43. Erano invertiti rispetto
    // alla convenzione del progetto — `isDebito()` rispondeva `saldo_iniziale < 0` — e non
    // avevano un solo chiamante in tutto il codice. Non sono stati corretti ma tolti: un
    // metodo con quel nome è esattamente ciò che qualcuno chiamerebbe in buona fede scrivendo
    // logica sui segni, e avrebbe ottenuto il verso opposto senza alcun modo di accorgersene.
    // Chi serve, scriva il confronto: è una riga, e si legge.
}
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
        'subentro_id',    // Il passaggio di titolarità che ha generato la riga di conguaglio (B2, D9); nullo per i saldi ordinari
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
     * Il passaggio di titolarità da cui nasce questa riga di conguaglio (B2, 1.11.0-beta.31). Le due
     * righe della coppia — credito all'uscente, debito all'entrante — portano lo stesso `subentro_id`
     * e sommano zero: `SaldoInizialeController` non ne tocca una sola (invariante 19).
     */
    public function subentro()
    {
        return $this->belongsTo(\App\Models\Gestionale\Subentro::class, 'subentro_id');
    }

    /** Perché una riga dell'arretrato non si modifica né si cancella da sola: il Wallet lo dice con queste parole. */
    public const FRASE_ARRETRATO = 'Questa riga è una delle due dell\'arretrato di una successione (la posizione del defunto passata a un erede: due righe di segno opposto, somma zero): non si modifica e non si cancella da sola. Si toglie annullando il passaggio dallo storico dell\'unità («Passaggi registrati»); se un piano l\'ha già assorbita, resta il saldo manuale di segno opposto.';

    /**
     * Decisione 67 (3): perché un saldo da cui una successione ha calcolato l'arretrato agli eredi non si modifica né si cancella;
     * null se nessuna lo ha letto.
     */
    public function fraseFonteDellArretrato(): ?string
    {
        $successione = \App\Models\Gestionale\Subentro::successioneCheLeggeISaldi([(int) $this->id], (int) $this->condominio_id);
        if ($successione === null) {
            return null;
        }
        $defunto = $successione->uscente?->nome ?? ($successione->registro['nomi']['uscente'] ?? 'questa persona');

        // Rilievo GC12 del giro sulle correzioni: le righe degli eredi possono essere un credito; la correzione che resta è la differenza;
        // e una fonte che è la riga di un conguaglio si toglie annullando quel conguaglio.
        return sprintf('Questo saldo è fra le cifre da cui è calcolato l\'arretrato di %s, passato agli eredi con la successione del %s: cambiarlo qui lascerebbe sbagliate le righe degli eredi. Per correggerlo annulla la successione dallo storico dell\'unità («Passaggi registrati»), correggi il saldo (se è una riga di conguaglio, annulla quel conguaglio) e registra di nuovo la successione; se la successione non si può più annullare, scrivi agli eredi un saldo manuale per la differenza, ogni erede per la sua quota.',
            $defunto, \Carbon\CarbonImmutable::parse($successione->decorrenza)->locale('it')->translatedFormat('j F Y'));
    }

    /**
     * Rilievo L1 della Fase 1-bis: una gamba del conguaglio di una successione con l'arretrato agli eredi. Conguaglio e arretrato fanno un
     * conto solo, e l'annullamento del solo conguaglio si rifiuta: la strada è annullare il passaggio.
     */
    public const FRASE_CONGUAGLIO_CON_ARRETRATO = 'Questa riga è una delle due del conguaglio di una successione con l\'arretrato agli eredi: conguaglio e arretrato fanno un conto solo, e la riga non si modifica né si cancella da sola. Si toglie annullando la successione dallo storico dell\'unità («Passaggi registrati»); se un piano l\'ha già assorbita, resta il saldo manuale di segno opposto.';

    /** Una delle righe dell'arretrato di una successione (1.11.0-beta.44, decisione 65), non del conguaglio. */
    public function dellArretrato(): bool
    {
        return $this->subentro_id !== null && in_array((int) $this->id, $this->subentro?->saldiDellArretrato() ?? [], true);
    }

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
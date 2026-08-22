<?php

namespace App\Models\Gestionale;

use App\Traits\RisolveIFigliDelleRotte;
use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Saldo;
use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class FatturaPassiva extends Model
{
    use RisolveIFigliDelleRotte;

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

    /**
     * I piani rate che hanno agganciato questa fattura (lato inverso di
     * PianoRate::fatture). Serviva solo al controller, che finora leggeva il
     * pivot a mano con una query grezza.
     */
    public function pianiRate(): BelongsToMany
    {
        return $this->belongsToMany(PianoRate::class, 'piano_rate_fatture');
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    /*
    |--------------------------------------------------------------------------
    | ELIMINABILITÀ
    |--------------------------------------------------------------------------
    */

    /**
     * Perché questa fattura non si può eliminare — o `null` se si può.
     *
     * Esiste per una ragione sola: prima i motivi erano **sette** dentro
     * `FatturaPassivaController::destroy()`, e il menu della riga ne guardava
     * **due**. Da qui due difetti opposti, entrambi reali:
     *
     *   - la voce «Elimina» spariva senza dire perché (divieto muto);
     *   - e quando compariva, poteva comunque essere rifiutata dal server
     *     (fondo confermato, piano approvato, più scritture, esercizio chiuso).
     *
     * Ora la guardia è una sola. Il controller la chiama per decidere, la lista
     * la chiama per spiegare: non possono più divergere, perché sono la stessa
     * riga di codice.
     *
     * Ogni messaggio dice anche **come uscirne**: un divieto senza via d'uscita
     * è la ragione per cui la segnalazione parlava di ansia, non di un errore.
     *
     * Usa le relazioni, non query diirette, così in elenco basta un
     * `with([...])` per evitare l'N+1.
     */
    public function motivoBloccoEliminazione(): ?string
    {
        if ($this->dati_extra['is_stornata'] ?? false) {
            return 'Questa fattura è già stata annullata con uno storno contabile: non c\'è altro da eliminare. '
                 . 'Se vuoi tornare indietro, elimina la nota di credito generata dallo storno.';
        }

        $coperturaConfermata = $this->coperture
            ->where('tipo_copertura', 'fondo_riserva')
            ->where('stato', 'confermata')
            ->isNotEmpty();

        if ($coperturaConfermata) {
            return 'La copertura dal fondo è stata confermata con un giroconto: eliminare la fattura lascerebbe '
                 . 'il giroconto orfano, cioè fondo consumato senza più traccia del perché. '
                 . 'Storna prima il giroconto di conferma dalla pagina Giroconti.';
        }

        foreach ($this->pianiRate as $piano) {
            if ($piano->eImmutabile()) {
                return "La fattura è nel piano rate «{$piano->nome}», che ha già rate emesse o incassate. "
                     . 'Annulla le emissioni di quel piano, oppure usa lo Storno.';
            }

            $stato = is_object($piano->stato) ? $piano->stato->value : $piano->stato;

            if ($stato === 'approvato') {
                return "La fattura è nel piano rate «{$piano->nome}», che è approvato (art. 1135 c.c.). "
                     . 'Riporta il piano in bozza per poterla eliminare.';
            }
        }

        if ($this->stato_pagamento !== StatoPagamentoFattura::APERTA) {
            return 'La fattura risulta pagata o parzialmente saldata. Per non riscrivere il Libro Giornale '
                 . 'storna prima il pagamento dalla sezione Pagamenti fornitori, poi usa lo Storno sulla fattura.';
        }

        if ($this->scritture->count() > 1) {
            return 'La fattura è collegata a più scritture contabili: eliminarla ne lascerebbe alcune senza '
                 . 'documento. Usa lo Storno, che le chiude tutte in modo tracciato.';
        }

        if (($this->esercizio?->stato) === 'chiuso') {
            return 'La fattura appartiene a un esercizio chiuso e già rendicontato: quel bilancio non si '
                 . 'riscrive. Usa lo Storno, che registra la rettifica nell\'esercizio corrente.';
        }

        return null;
    }

    /**
     * Lo stesso valore, esposto come attributo perché possa viaggiare nel
     * payload con `->append('motivo_blocco_eliminazione')`. Non è una colonna:
     * resta fuori dal database e non viene mai scritto.
     */
    public function getMotivoBloccoEliminazioneAttribute(): ?string
    {
        return $this->motivoBloccoEliminazione();
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
     * Quanto resta da saldare (fattura) o da compensare (nota di credito), in centesimi.
     *
     * **Positivo = ancora da chiudere. Negativo = allocato più del dovuto**, cioè un'anomalia —
     * vedi `inconsistenza_pagamento`. Vale per tutti e due i tipi di documento, ed è il punto.
     *
     * ## ⚠️ Perché c'è `abs()` sul netto, e cosa costava non averlo (corretto nella beta.67)
     *
     * `FatturaPassivaService` registra le note di credito moltiplicando per **−1**: il netto di una
     * nota è negativo. L'allocato invece è sempre positivo, perché è la somma di quanto si è
     * consumato. Facendo `netto − allocato` su una nota, ogni compensazione **allontanava** il
     * risultato da zero:
     *
     *     nota da € 2.440,00, niente compensato → −244000, letto come € 2.440,00 disponibili  ✓
     *     dopo aver compensato € 1.220,00       → −366000, letto come € 3.660,00 disponibili  ✗
     *
     * **Il credito cresceva mentre lo si spendeva.** E non restava un numero storto a video:
     * l'endpoint delle pendenze lo espone con `abs()`, e la guardia sull'eccesso in
     * `PagamentoFornitoreService` confronta magnitudo — `abs($allocatoProposto) > abs($residuo)` —
     * quindi leggeva la cifra gonfiata e **accettava** la compensazione di troppo: € 3.660,00
     * consumati da una nota che ne vale € 2.440,00, tre fatture chiuse a «pagata» e zero euro
     * usciti di cassa.
     *
     * La convenzione qui sotto è la stessa che `PagamentoFornitoreService::ricalcolaStatoFattura()`
     * usa già da sé (`abs($totale)` contro `abs($netto)`) ed è per questo che la macchina degli
     * stati era giusta mentre il residuo no: erano due letture diverse dello stesso fatto.
     *
     * ⚠️ **La correzione sta qui e non nei chiamanti**, che sono tre e di cui **uno è la guardia**.
     * Un numero che va letto al contrario a seconda del tipo di documento è un numero che il
     * prossimo chiamante sbaglierà in buona fede.
     *
     * Il presidio è `tests/Feature/Gestionale/CreditoNotaCreditoNonCresceTest.php`.
     */
    public function getResiduoAttribute(): int
    {
        return abs($this->netto_a_pagare) - $this->totale_allocato;
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

    /**
     * Le rotte annidate sotto questo modello, e la relazione che porta a ciascun figlio.
     *
     * Vedi il blocco in testa a `App\Traits\RisolveIFigliDelleRotte` per il perché serve: Laravel
     * deriverebbe il nome con una pluralizzazione inglese, e su nomi italiani sbaglia sempre.
     *
     * @return array<string, string>
     */
    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'documento' => 'documenti',
        ];
    }

}
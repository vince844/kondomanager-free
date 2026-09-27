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
        // B2 (1.11.0-beta.31): il periodo di competenza dichiarato. Nullo = cascata di D3.
        'competenza_dal'                => 'date:Y-m-d',
        'competenza_al'                 => 'date:Y-m-d',
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

        // I piani, con la regola unica di modifica, eliminazione e storno (`pianiConGrado()`, 1.11.0-beta.35): conta il
        // piano più avanzato. Con rate emesse il motivo non propone più lo storno: finché il piano non ha incassato, lo
        // storno lo rifiuta.
        if ($voce = $this->pianoAlGrado('incassato', 'emesso', 'approvato')) {
            $nome = $voce['piano']->nome;

            return match ($voce['grado']) {
                'incassato' => $this->motivoBloccoStorno()
                    ?? "La fattura è nel piano rate «{$nome}», che ha già incassato rate: non si elimina, usa lo storno.",
                'emesso' => "La fattura è nel piano rate «{$nome}», che ha già rate emesse. "
                     . 'Annulla le emissioni di quel piano e riportalo in bozza per poterla eliminare.',
                'approvato' => "La fattura è nel piano rate «{$nome}», che è approvato (art. 1135 c.c.). "
                     . 'Riporta il piano in bozza per poterla eliminare.',
            };
        }

        if ($this->stato_pagamento !== StatoPagamentoFattura::APERTA) {
            return 'La fattura risulta pagata o parzialmente saldata. Per non riscrivere il Libro Giornale '
                 . 'storna prima il pagamento dalla sezione Pagamenti fornitori, poi usa lo Storno sulla fattura.';
        }

        // Le due guardie qui sotto mandano allo storno: se lo storno oggi è rifiutato per un piano rate, lo dicono con la
        // via, come la modifica (verifica delle correzioni della Fase 1-bis, 1.11.0-beta.35).
        $bloccoStorno = $this->motivoBloccoStorno();
        $poi = $bloccoStorno ? ' Lo storno però ora non è possibile: ' . $bloccoStorno : '';

        if ($this->scritture->count() > 1) {
            return 'La fattura è collegata a più scritture contabili: eliminarla ne lascerebbe alcune senza '
                 . 'documento. Usa lo Storno, che le chiude tutte in modo tracciato.' . $poi;
        }

        if (($this->esercizio?->stato) === 'chiuso') {
            return 'La fattura appartiene a un esercizio chiuso e già rendicontato: quel bilancio non si '
                 . 'riscrive. Usa lo Storno, che registra la rettifica nell\'esercizio corrente.' . $poi;
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

    /*
    |--------------------------------------------------------------------------
    | STORNO E PIANI RATE — una regola sola (1.11.0-beta.35, R1 della Fase 1-bis)
    |--------------------------------------------------------------------------
    */

    /** @var list<array{piano: PianoRate, grado: string}>|null */
    protected ?array $pianiConGradoLetti = null;

    /**
     * I piani rate che contengono questa fattura, ognuno con il grado a cui è arrivato: la base della regola unica di
     * modifica, eliminazione e storno.
     *
     * Le tre guardie leggevano i piani ciascuna a modo suo, e si contraddicevano: la modifica mandava allo storno, e lo
     * storno non guardava i piani — la fattura annullata restava in `piano_rate_fatture`, il motore la leggeva senza
     * escludere le stornate e le rate la chiedevano ancora. Registrata di nuovo e messa in un piano nuovo, i condòmini la
     * pagavano due volte. Decisione di Vincenzo del 27/09/2026: lo storno segue la scala dell'eliminazione.
     *
     * Quattro gradi, dal più avanzato: `incassato` (una quota ha un incasso, o un credito compensato o rimborsato:
     * `importo_pagato ≠ 0`), `emesso` (una quota è a giornale), `approvato`, `bozza`. I movimenti si leggono prima dello
     * stato, perché il server lascia riportare in bozza anche un piano già emesso.
     *
     * @return list<array{piano: PianoRate, grado: string}>
     */
    public function pianiConGrado(): array
    {
        return $this->pianiConGradoLetti ??= $this->pianiRate->sortBy('id')->values()->map(function (PianoRate $piano) {
            $stato = is_object($piano->stato) ? $piano->stato->value : $piano->stato;

            return ['piano' => $piano, 'grado' => match (true) {
                $piano->haIncassiRegistrati() => 'incassato',
                $piano->haRateEmesse() => 'emesso',
                $stato === 'approvato' => 'approvato',
                default => 'bozza',
            }];
        })->all();
    }

    /**
     * Il piano più avanzato fra i gradi chiesti, cercati nell'ordine in cui si passano — o `null`. Con una fattura divisa
     * fra più piani (Coda 156) non conta il primo piano, conta quello che chiede più passi: una fattura in un piano in
     * bozza e in uno che ha già incassato si storna, non si modifica.
     *
     * @return array{piano: PianoRate, grado: string}|null
     */
    public function pianoAlGrado(string ...$gradi): ?array
    {
        foreach ($gradi as $grado) {
            foreach ($this->pianiConGrado() as $voce) {
                if ($voce['grado'] === $grado) {
                    return $voce;
                }
            }
        }

        return null;
    }

    /**
     * Perché questa fattura non si può stornare — o `null` se si può. Come `motivoBloccoEliminazione()`: il controller la
     * chiama per decidere e l'elenco per spiegare, e ogni motivo dice la via d'uscita.
     *
     * Le prime quattro guardie vivevano in `StornoFatturaController` (stessi messaggi); quella sui piani è nuova. Finché
     * un piano non ha incassato niente, la fattura prima si toglie dal piano — e in un piano da fatture l'unico modo è
     * eliminarlo, perché una fattura sola non si stacca. Con incassi lo storno resta possibile: la fattura è annullata
     * davvero e la contabilità deve dirlo; lo dice `avvisoStorno()`.
     */
    public function motivoBloccoStorno(): ?string
    {
        if ($this->dati_extra['is_stornata'] ?? false) {
            return 'Questa fattura è già stata stornata in precedenza.';
        }

        // ⚠️ **Si guarda il TIPO del documento, non il segno del netto.**
        // La guardia leggeva `netto_a_pagare < 0` e dava della nota di credito a qualunque
        // documento a credito. Ma una fattura ordinaria PUÒ essere a credito: le righe negative
        // sono legittime (il file 06 dei collaudi ne ha una) e se gli storni di riga superano
        // gli addebiti il netto è negativo su un documento di tipo `fattura`. Quel documento
        // restava senza nessuna via di rettifica — la modifica rimanda allo storno, e lo storno
        // negava adducendo un tipo di documento che non era il suo. Il messaggio, per giunta,
        // nominava una nota di credito a chi aveva in mano una fattura.
        // Trovato dalla Fase 1-bis della beta.19, lente «segno».
        if ($this->tipo_documento === 'nota_credito') {
            return 'Una nota di credito non si storna: rettifica la fattura che annulla, oppure registra il documento che il fornitore ha emesso.';
        }

        // Una fattura con pagamenti vivi non può essere annullata da una sola nota di
        // credito: il denaro è già uscito dalla cassa e quel movimento va stornato per
        // primo, altrimenti restano un'uscita di cassa senza debito che la giustifichi e
        // — dopo un'eventuale eliminazione della NC — una fattura "aperta" con pagamenti
        // ancora allocati.
        if ($this->stato_pagamento !== StatoPagamentoFattura::APERTA) {
            return 'La fattura ha pagamenti registrati. Storna prima il pagamento dalla sezione Pagamenti fornitori, poi la fattura.';
        }

        // Beta.19: una copertura CONFERMATA ha un giroconto vivo nel giornale — il
        // fondo è già stato decurtato per questa fattura. Stornare la fattura
        // lasciando in piedi il giroconto consumerebbe il fondo per un debito che
        // non esiste più. Prima si storna il giroconto (la copertura torna in
        // attesa), poi la fattura.
        if ($this->coperture->where('tipo_copertura', 'fondo_riserva')->where('stato', 'confermata')->isNotEmpty()) {
            return 'La copertura dal fondo è già stata confermata con un giroconto. Storna prima il giroconto di conferma dalla pagina Giroconti, poi la fattura.';
        }

        $altre = 'le altre fatture del piano, se ce ne sono, tornano disponibili per un piano nuovo.';

        // Il piano più avanzato fra quelli che non hanno incassato: è quello che chiede più passi.
        if ($voce = $this->pianoAlGrado('emesso', 'approvato', 'bozza')) {
            $nome = $voce['piano']->nome;

            return match ($voce['grado']) {
                'emesso' => "La fattura è nel piano rate «{$nome}», che ha già rate emesse: dopo lo storno quelle rate la chiederebbero ancora. "
                    . "Annulla le emissioni, riporta il piano in bozza ed eliminalo, poi storna la fattura; {$altre}",
                'approvato' => "La fattura è nel piano rate «{$nome}», approvato: dopo lo storno le sue rate la chiederebbero ancora. "
                    . "Riporta il piano in bozza ed eliminalo, poi storna la fattura; {$altre}",
                'bozza' => "La fattura è nel piano rate «{$nome}», in bozza: dopo lo storno le sue rate la chiederebbero ancora. "
                    . "Elimina prima il piano, poi storna la fattura; {$altre}",
            };
        }

        return null;
    }

    /**
     * Cosa resta dopo lo storno di una fattura che sta in un piano che ha già incassato — o `null`. Lo mostra la conferma
     * dello storno: il piano non si rettifica da solo, e il versato per una spesa annullata è una decisione dell'assemblea.
     */
    public function avvisoStorno(): ?string
    {
        $nomi = collect($this->pianiConGrado())
            ->where('grado', 'incassato')
            ->map(fn (array $voce) => '«' . $voce['piano']->nome . '»')
            ->values();

        if ($nomi->isEmpty()) {
            return null;
        }

        $dove = $nomi->count() === 1
            ? "La fattura è nel piano rate {$nomi->first()}, che ha già incassato rate."
            : 'La fattura è nei piani rate ' . $nomi->slice(0, -1)->implode(', ') . ' e ' . $nomi->last() . ', che hanno già incassato rate.';

        return $dove . ' Lo storno annulla la fattura in contabilità, ma le rate del piano restano e continuano a chiederla: '
            . 'quanto i condòmini hanno versato per questa spesa va restituito o destinato con una delibera.';
    }

    public function getMotivoBloccoStornoAttribute(): ?string
    {
        return $this->motivoBloccoStorno();
    }

    public function getAvvisoStornoAttribute(): ?string
    {
        return $this->avvisoStorno();
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


    /**
     * Questa nota di credito è nata da uno **storno**, quindi non è compensabile né pagabile?
     *
     * ⚠️ **Due criteri, e il secondo esiste per le note già a database.** La correzione della
     * Coda 124 marca le note nuove con `dati_extra.nota_storno`, ma le note generate **prima** di
     * quella correzione quella chiave non ce l'hanno e restavano compensabili: il fornitore
     * poteva vedersi decurtare un pagamento con una nota che non ha mai emesso. Era la Coda 133.
     *
     * Il secondo criterio non ha bisogno di nessuna migrazione, perché il legame esiste già ed è
     * sempre esistito: quando `StornoFatturaController` genera la nota, **congela la fattura
     * originale** scrivendole `dati_extra.stornata_da_id` con l'id della nota. Una nota puntata da
     * una fattura è nata da uno storno, comunque sia stata marcata.
     *
     * ⚠️ **Il verso conta.** Si guarda chi *punta* questa nota, non cosa questa nota dichiara di
     * sé: è ciò che permette di riconoscere le storiche senza toccarle. E lascia intatto il caso
     * opposto — una nota di credito **vera**, emessa dal fornitore, non è puntata da nessuno e
     * resta compensabile come sempre.
     */
    public function eNataDaStorno(): bool
    {
        if (! empty($this->dati_extra['nota_storno'] ?? null)) {
            return true;
        }

        if ($this->tipo_documento !== 'nota_credito') {
            return false;
        }

        return static::query()
            ->where('condominio_id', $this->condominio_id)
            ->whereJsonContains('dati_extra->stornata_da_id', $this->id)
            ->exists();
    }
}

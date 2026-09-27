<?php

namespace App\Models\Gestionale;

use App\Traits\RisolveIFigliDelleRotte;
use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Helpers\MoneyHelper;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\Gestionale\NettoNoteCollegate;
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
        return $this->belongsToMany(PianoRate::class, 'piano_rate_fatture')->withPivot('importo_collegato');
    }

    /**
     * Solo sulla nota di credito: la fattura che rettifica (Coda 165, 1.11.0-beta.36). Facoltativa, di qualunque
     * esercizio, dello stesso fornitore e condominio.
     */
    public function fatturaRettificata(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fattura_rettificata_id');
    }

    /** Le note di credito del fornitore collegate a questa fattura (Coda 165). */
    public function noteCollegate(): HasMany
    {
        return $this->hasMany(self::class, 'fattura_rettificata_id')->where('tipo_documento', 'nota_credito');
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

        // Coda 165 (1.11.0-beta.36): una nota del fornitore collegata resterebbe senza la fattura che rettifica — la
        // chiave esterna la scollegherebbe in silenzio.
        if ($note = $this->elencoNoteCollegate()) {
            return $this->noteCollegate->count() === 1
                ? "La fattura ha {$note} del fornitore collegata: eliminandola la nota resterebbe senza la fattura che "
                    . 'rettifica. Scollega o elimina prima la nota.'
                : "La fattura ha {$note} del fornitore collegate: eliminandola le note resterebbero senza la fattura che "
                    . 'rettificano. Scollega o elimina prima le note.';
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

        // Coda 165 (1.11.0-beta.36): lo storno annulla la fattura per intero, e la nota del fornitore resterebbe — il
        // credito verso il fornitore conterebbe due volte.
        if ($note = $this->elencoNoteCollegate()) {
            $una = $this->noteCollegate->count() === 1;

            return "La fattura ha {$note} del fornitore " . ($una ? 'collegata' : 'collegate') . ': stornarla conterebbe '
                 . 'due volte il credito verso il fornitore. Scollega o elimina prima ' . ($una ? 'la nota' : 'le note')
                 . ', poi storna la fattura.';
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

        return $this->viaDalPiano(
            fn (string $grado) => $grado === 'emesso'
                ? 'dopo lo storno quelle rate la chiederebbero ancora'
                : 'dopo lo storno le sue rate la chiederebbero ancora',
            'storna la fattura',
        );
    }

    /**
     * La scala dei piani, una volta sola per storno e nota di credito (Coda 165, 1.11.0-beta.36): il piano più avanzato
     * fra quelli che non hanno incassato — è quello che chiede più passi — e la via per ogni grado. In un piano da fatture
     * una fattura sola non si stacca: la via finisce sempre con l'eliminazione del piano.
     *
     * @param  \Closure(string): string  $conseguenza  che cosa succederebbe, per grado
     * @param  string  $poi  che cosa si fa dopo aver tolto il piano
     */
    private function viaDalPiano(\Closure $conseguenza, string $poi): ?string
    {
        $altre = 'le altre fatture del piano, se ce ne sono, tornano disponibili per un piano nuovo.';

        if ($voce = $this->pianoAlGrado('emesso', 'approvato', 'bozza')) {
            $nome = $voce['piano']->nome;
            $perche = $conseguenza($voce['grado']);

            return match ($voce['grado']) {
                'emesso' => "La fattura è nel piano rate «{$nome}», che ha già rate emesse: {$perche}. "
                    . "Annulla le emissioni, riporta il piano in bozza ed eliminalo, poi {$poi}; {$altre}",
                'approvato' => "La fattura è nel piano rate «{$nome}», approvato: {$perche}. "
                    . "Riporta il piano in bozza ed eliminalo, poi {$poi}; {$altre}",
                'bozza' => "La fattura è nel piano rate «{$nome}», in bozza: {$perche}. "
                    . "Elimina prima il piano, poi {$poi}; {$altre}",
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

    /*
    |--------------------------------------------------------------------------
    | NOTA DI CREDITO DEL FORNITORE E PIANI RATE (Coda 165, 1.11.0-beta.36)
    |--------------------------------------------------------------------------
    | Decisione 26, punto 6: la nota del fornitore collegata a questa fattura segue la stessa scala dello storno. I metodi
    | stanno sulla FATTURA rettificata, perché è lei a stare nei piani; il form, «Collega a una fattura» e il server
    | chiamano gli stessi, come lo storno: una riga di codice sola per decidere e per spiegare.
    */

    /**
     * «la nota di credito n. X», o «le note di credito n. X e n. Y», delle note collegate — o `null` se non ce ne sono. Con
     * una preposizione, articolata: «della nota…», «dalle note…» — «al netto di la nota» si leggeva in due messaggi
     * (testuale della Fase 1-bis). Le contestate si tolgono quando la frase parla del netto, che non le conta.
     */
    public function elencoNoteCollegate(string $preposizione = '', bool $senzaContestate = false): ?string
    {
        $note = $this->noteCollegate;
        if ($senzaContestate) {
            $note = $note->reject(fn ($n) => (is_object($n->stato_approvazione) ? $n->stato_approvazione->value : $n->stato_approvazione) === 'contestata');
        }
        $numeri = $note->pluck('numero_documento')->map(fn ($n) => 'n. ' . $n)->values();
        if ($numeri->isEmpty()) {
            return null;
        }

        $una = $numeri->count() === 1;
        $articolo = match ($preposizione) {
            'di' => $una ? 'della' : 'delle',
            'da' => $una ? 'dalla' : 'dalle',
            default => $una ? 'la' : 'le',
        };

        return $una
            ? "{$articolo} nota di credito " . $numeri->first()
            : "{$articolo} note di credito " . $numeri->slice(0, -1)->implode(', ') . ' e ' . $numeri->last();
    }

    /** La fattura al netto delle note collegate (vedi `NettoNoteCollegate`), o `null` se non ne ha. */
    public function nettoNoteCollegate(): ?array
    {
        return NettoNoteCollegate::perFattura((int) $this->id);
    }

    /**
     * Quanto chiedono per questa fattura i piani che la contengono, in centesimi: l'importo collegato di ogni piano, o —
     * se è zero, cioè «tutto» — ciò che il motore ripartisce: la parte che il piano finanzia al netto delle note su quella
     * parte, e mai più del netto della fattura (`CalcoloQuoteService::calcolaDaFattureStraordinarie`, R2 della Fase 1-bis).
     * Solo i piani con il grado dato, se ne passi.
     */
    public function chiestoDaiPiani(string ...$gradi): int
    {
        $netto = $this->nettoNoteCollegate();
        $naturale = $netto !== null
            ? min($netto['parte_piano'] - $netto['rettificato_piano'], $netto['netto'])
            : $this->partePianoLorda();
        $chiesto = 0;
        foreach ($this->pianiConGrado() as $voce) {
            if ($gradi !== [] && ! in_array($voce['grado'], $gradi, true)) {
                continue;
            }
            $collegato = (int) ($voce['piano']->pivot->importo_collegato ?? 0);
            if ($collegato > 0) {
                $chiesto += $collegato;
            } elseif ($netto !== null && in_array($voce['grado'], ['emesso', 'incassato'], true)) {
                // Un piano «tutto» che non si ricalcola più chiede quello che ha ripartito alla generazione, e da qui
                // non si sa quali note c'erano allora. Si conta la parte intera, senza note: il limite alto, che non fa
                // finanziare due volte; gli avvisi non danno cifre (`chiestoNonCerto`, V4 della verifica).
                $chiesto += max(0, (int) $netto['parte_piano']);
            } else {
                $chiesto += max(0, $naturale);
            }
        }

        return $chiesto;
    }

    /**
     * Se quanto chiedono i piani dei gradi dati **non si può sapere**: un piano «tutto» (importo collegato a zero) già
     * emesso o incassato, su una fattura con note collegate. Allora gli avvisi non danno cifre invece di inventarle (V4).
     */
    private function chiestoNonCerto(string ...$gradi): bool
    {
        if ($this->nettoNoteCollegate() === null) {
            return false;
        }
        foreach ($this->pianiConGrado() as $voce) {
            if (($gradi === [] || in_array($voce['grado'], $gradi, true))
                && in_array($voce['grado'], ['emesso', 'incassato'], true)
                && (int) ($voce['piano']->pivot->importo_collegato ?? 0) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * La parte della fattura che un piano da fatture finanzia, al lordo: i componenti del motore — righe ad personam, righe
     * fuori preventivo con una voce, o le coperture «sopravvenienza» con una voce della pregressa.
     */
    public function partePianoLorda(): int
    {
        if ($this->is_pregresso) {
            return (int) $this->coperture->where('tipo_copertura', 'sopravvenienza')->whereNotNull('conto_id')->sum(fn ($c) => abs((int) $c->importo));
        }

        return (int) $this->righe->filter(fn ($r) => $r->immobile_id !== null || ($r->is_sopravvenienza && $r->conto_id !== null))
            ->sum(fn ($r) => (int) $r->importo_imponibile + (int) $r->importo_iva);
    }

    /**
     * Quanto vale la fattura dopo una nota in più, con le sue righe vere: la stessa regola di `NettoNoteCollegate` —
     * dove la nota non tocca la parte del piano, il netto non scende (R4 della Fase 1-bis: prima si toglieva l'intero
     * importo, e una nota sulla parte a preventivo faceva chiedere di restituire denaro mai chiesto in più). Con righe
     * vuote, il netto di oggi: la modifica di una nota lo legge dopo aver scritto le righe nuove.
     *
     * @param  list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>  $righeNota
     * @return array{netto: int, rettificato_piano: int}
     */
    private function statoDopoNota(array $righeNota): array
    {
        $dopo = NettoNoteCollegate::perFattura((int) $this->id, $righeNota);

        return [
            'netto' => (int) ($dopo['netto'] ?? $this->partePianoLorda()),
            'rettificato_piano' => (int) ($dopo['rettificato_piano'] ?? 0),
        ];
    }

    /** «La fattura è nel piano rate «X», che ha già incassato rate.» per i piani che hanno incassato — o `null`. */
    private function doveIncassato(): ?string
    {
        $nomi = collect($this->pianiConGrado())->where('grado', 'incassato')
            ->map(fn (array $voce) => '«' . $voce['piano']->nome . '»')->values();
        if ($nomi->isEmpty()) {
            return null;
        }

        return $nomi->count() === 1
            ? "La fattura è nel piano rate {$nomi->first()}, che ha già incassato rate."
            : 'La fattura è nei piani rate ' . $nomi->slice(0, -1)->implode(', ') . ' e ' . $nomi->last() . ', che hanno già incassato rate.';
    }

    /**
     * Il testo dell'avviso con incassi, in tre forme: con la cifra quando le rate chiedono più del netto; senza cifra
     * quando il netto scende ma quanto chiedono i piani non si sa (piano «tutto» già incassato, V4 — la frase sulla
     * restituzione non si perde, W2 del terzo giro); sulla ripartizione quando cambia solo di chi è la parte.
     *
     * Chiude sempre con come si sistema di solito: il conguaglio nel consuntivo, verso il condominio. Deciso da Vincenzo
     * il 27/09/2026 dopo la ricerca sulla legge — il credito e il debito di ciascuno sono verso il condominio, e una
     * compensazione diretta fra condòmini non ha base (art. 1241 c.c.).
     */
    private function testoAvvisoIncassato(string $dove, ?int $inPiu, bool $nettoScende = false, bool $modifica = false): string
    {
        $come = ' con una delibera, di solito nel conguaglio del consuntivo: la differenza è fra ciascun condòmino e il '
            . 'condominio, non fra condòmini.';
        // In modifica la nota può solo spostarsi: «riduce il debito» sarebbe falso, e le rate possono essere state
        // calcolate già con la nota (testuale del quarto giro). Le rate «restano quelle già calcolate» vale in tutti i casi.
        $apre = $modifica ? ' La modifica cambia quanto e dove la nota riduce la fattura' : ' La nota riduce il debito verso il fornitore';
        if ($inPiu !== null && $inPiu > 0) {
            return $dove . $apre . ', ma le rate restano e chiedono '
                . MoneyHelper::format($inPiu) . ' più di quanto resta della fattura: quanto i condòmini hanno versato in più '
                . 'va restituito o destinato' . $come;
        }
        if ($nettoScende) {
            return $dove . $apre . ' e la fattura vale di meno, ma le rate restano quelle già calcolate: quanto i condòmini '
                . 'hanno versato oltre quanto resta della fattura va restituito o destinato' . $come;
        }

        return $dove . $apre . ', ma le rate restano quelle già calcolate: se la nota riguarda una parte precisa — '
            . 'un\'unità, una voce — quanto ha versato ciascun condòmino può non corrispondere più alla sua parte, e va '
            . 'sistemato' . $come;
    }

    /**
     * Perché una nota del fornitore non si può collegare a questa fattura (in registrazione, con «Collega a una
     * fattura», o modificandola) — o `null` se si può. Con un piano che ha incassato si può: lo dice
     * `avvisoNotaCollegata()`.
     *
     * @param  int  $importoNotaCents  la magnitudine della nota che si sta registrando o collegando, per il tetto del totale
     * @param  list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>|null  $righeNota  le righe della nota, per il
     *         «di quanto»; `null` se non si conoscono (la richiesta, prima del calcolo dell'IVA): il messaggio non dà cifre
     */
    public function motivoBloccoNotaCollegata(int $importoNotaCents = 0, string $poi = 'registra la nota', ?array $righeNota = null, bool $notaContestata = false): ?string
    {
        if ($this->tipo_documento !== 'fattura') {
            return 'Una nota di credito rettifica una fattura, non un\'altra nota.';
        }

        if ($this->dati_extra['is_stornata'] ?? false) {
            return "La fattura n. {$this->numero_documento} è già stata stornata: la nota del fornitore sarebbe un secondo "
                . 'credito sullo stesso documento. Se lo storno era un errore, elimina la nota nata dallo storno, che '
                . "ripristina la fattura, poi {$poi}.";
        }

        $documento = (int) $this->importo_imponibile + (int) $this->importo_iva;
        $giaRettificato = (int) ($this->nettoNoteCollegate()['rettificato'] ?? 0);
        if ($importoNotaCents > 0 && $giaRettificato + $importoNotaCents > $documento) {
            return "Le note collegate alla fattura n. {$this->numero_documento} arriverebbero a "
                . MoneyHelper::format($giaRettificato + $importoNotaCents) . ', oltre il suo totale di '
                . MoneyHelper::format($documento) . '. Controlla l\'importo della nota, o la fattura che rettifica.';
        }

        // Una nota contestata non conta nel netto (`NettoNoteCollegate`): non cambia niente di quanto i piani chiedono, e
        // la scala non la riguarda. Il tetto qui sopra sì — vale per tutte le note (V3 della verifica delle correzioni).
        if ($notaContestata) {
            return null;
        }

        $inPiu = $righeNota === null || $this->chiestoNonCerto('emesso', 'approvato', 'bozza')
            ? null
            : $this->chiestoDaiPiani('emesso', 'approvato', 'bozza') - $this->statoDopoNota($righeNota)['netto'];

        return $this->viaDalPiano(
            fn (string $grado) => $inPiu !== null && $inPiu > 0
                ? ($grado === 'emesso' ? 'con la nota, quelle rate' : 'con la nota, le sue rate')
                    . ' chiederebbero ' . MoneyHelper::format($inPiu) . ' più di quanto resta della fattura'
                : ($grado === 'emesso' ? 'quelle rate' : 'le sue rate') . ' sono già calcolate e non si aggiornano da sole',
            $poi,
        );
    }

    /**
     * Che cosa resta dopo aver collegato una nota a questa fattura, quando un piano che la contiene ha già incassato — o
     * `null`. Lo mostra la conferma, come per lo storno: le rate non si rettificano da sole.
     *
     * Con le righe della nota si sa dove riduce: se le rate chiedono più del netto, l'avviso dice di quanto; se la nota
     * tocca la parte del piano senza cambiare il totale — la riga di un'unità, su un piano parziale — l'avviso c'è lo
     * stesso, perché la parte di ciascuno non torna (R3 della Fase 1-bis); se la nota sta tutta sulla parte a preventivo,
     * il piano non c'entra e l'avviso non c'è (R4). Senza righe non si sa, e l'avviso c'è, senza cifra.
     *
     * @param  list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>|null  $righeNota
     */
    public function avvisoNotaCollegata(?array $righeNota = null, bool $notaContestata = false): ?string
    {
        if ($notaContestata || ($dove = $this->doveIncassato()) === null) {
            return null;
        }
        if ($righeNota === null) {
            return $this->testoAvvisoIncassato($dove, null);
        }

        $oggi = $this->nettoNoteCollegate();
        $dopo = $this->statoDopoNota($righeNota);
        $toccaIlPiano = $dopo['rettificato_piano'] !== (int) ($oggi['rettificato_piano'] ?? 0);
        if ($this->chiestoNonCerto('incassato')) {
            // Quanto chiede il piano non si sa: l'avviso c'è se la nota cambia qualcosa, senza cifra (V4).
            $scende = $dopo['netto'] < (int) ($oggi['netto'] ?? $this->partePianoLorda());

            return $toccaIlPiano || $scende ? $this->testoAvvisoIncassato($dove, null, $scende) : null;
        }
        $inPiu = $this->chiestoDaiPiani('incassato') - $dopo['netto'];
        if ($inPiu <= 0 && ! $toccaIlPiano) {
            return null;
        }

        return $this->testoAvvisoIncassato($dove, $inPiu);
    }

    /**
     * Se la modifica di una nota collegata cambia che cosa i piani dovrebbero chiedere a qualcuno: il netto scende, o
     * un'unità o una voce della parte del piano viene ridotta **di più** (anche una che prima non lo era — la nota
     * spostata da un'unità a un'altra). «Modifica con la scala» (decisione 26, punto 6): correggere una data o una
     * descrizione, o ridurre la nota, non fa chiedere a nessuno più di prima e resta libero.
     */
    private function modificaNotaCambiaIlPiano(?array $prima, ?array $dopo): bool
    {
        $nettoPrima = (int) ($prima['netto'] ?? $this->partePianoLorda());
        $nettoDopo = (int) ($dopo['netto'] ?? $this->partePianoLorda());
        if ($nettoDopo < $nettoPrima) {
            return true;
        }

        // Le riduzioni per chiave sono negative: «di più» è più negativo.
        $impPrima = NettoNoteCollegate::impronta($prima);
        foreach (NettoNoteCollegate::impronta($dopo) as $chiave => $riduzione) {
            if ($riduzione < ($impPrima[$chiave] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perché la modifica di una nota collegata a questa fattura non si può salvare — o `null`. Si chiama **dentro** la
     * transazione di `FatturaPassivaService::aggiornaFattura`, dopo che le righe nuove della nota sono scritte: il netto
     * «dopo» si legge dal database con gli importi veri (IVA dichiarata compresa), non da un calcolo parallelo.
     *
     * La scala scatta se il netto scende o se un'unità o una voce del piano viene ridotta di più: spostare la nota da
     * un'unità a un'altra a netto invariato cambiava chi deve pagare senza che nessun piano lo sapesse (scartato dalla
     * deduplica della Fase 1-bis come «decisione», ripreso perché la decisione dice «modifica con la scala»).
     *
     * @param  array<string, mixed>|null  $prima  `nettoNoteCollegate()` letto prima della modifica
     */
    public function motivoBloccoModificaNota(?array $prima, string $poi = 'modifica la nota', int $importoNotaContestata = 0, int $importoNotaContestataPrima = 0): ?string
    {
        $dopo = $this->nettoNoteCollegate();
        $documento = (int) $this->importo_imponibile + (int) $this->importo_iva;
        // La nota contestata modificata: fuori dal netto, e quindi dalla scala, ma dentro il tetto del totale come in
        // registrazione — portata da € 100,00 a € 1.500,00 su una fattura da € 1.000,00 passava (V3).
        if ($importoNotaContestata > 0) {
            $totale = (int) ($dopo['rettificato'] ?? 0) + $importoNotaContestata;
            $totalePrima = (int) ($prima['rettificato'] ?? 0) + $importoNotaContestataPrima;

            // Solo se cresce, come per le altre note: correggere la descrizione di una contestata non si blocca (W3).
            return $totale > $documento && $totale > $totalePrima
                ? "Le note collegate alla fattura n. {$this->numero_documento} arriverebbero a " . MoneyHelper::format($totale)
                    . ', oltre il suo totale di ' . MoneyHelper::format($documento) . '. Controlla l\'importo della nota; per '
                    . 'collegarla a un\'altra fattura, prima «Scollega» dall\'elenco.'
                : null;
        }
        $rettificatoDopo = (int) ($dopo['rettificato'] ?? 0);
        if ($rettificatoDopo > $documento && $rettificatoDopo > (int) ($prima['rettificato'] ?? 0)) {
            return "Le note collegate alla fattura n. {$this->numero_documento} arriverebbero a "
                . MoneyHelper::format($rettificatoDopo) . ', oltre il suo totale di '
                . MoneyHelper::format($documento) . '. Controlla l\'importo della nota; per collegarla a un\'altra fattura, '
                . 'prima «Scollega» dall\'elenco.';
        }

        if (! $this->modificaNotaCambiaIlPiano($prima, $dopo)) {
            return null;
        }

        // Le righe nuove sono già scritte: il «dopo» è lo stato di oggi (righe in più vuote).
        return $this->motivoBloccoNotaCollegata(0, $poi, []);
    }

    /**
     * L'avviso della modifica di una nota collegata quando un piano che contiene la fattura ha incassato — o `null`.
     * Come `motivoBloccoModificaNota`, dentro la transazione e a righe scritte; il servizio lo fa confermare con un
     * secondo invio (R5 della Fase 1-bis: prima la modifica passava senza avviso).
     */
    public function avvisoModificaNota(?array $prima): ?string
    {
        if (($dove = $this->doveIncassato()) === null) {
            return null;
        }
        $dopo = $this->nettoNoteCollegate();
        if (! $this->modificaNotaCambiaIlPiano($prima, $dopo)) {
            return null;
        }

        if ($this->chiestoNonCerto('incassato')) {
            $scende = (int) ($dopo['netto'] ?? $this->partePianoLorda()) < (int) ($prima['netto'] ?? $this->partePianoLorda());

            return $this->testoAvvisoIncassato($dove, null, $scende, true);
        }

        return $this->testoAvvisoIncassato($dove, $this->chiestoDaiPiani('incassato') - (int) ($dopo['netto'] ?? $this->partePianoLorda()), false, true);
    }

    /**
     * Perché la modifica di questa **fattura** non si può salvare per le note collegate — o `null`: il suo totale non può
     * scendere sotto quanto le note già rettificano (la stessa regola del collegamento, «mai oltre il totale»). Dentro la
     * transazione di `aggiornaFattura`, a righe scritte (domanda aperta della Fase 1-bis).
     */
    public function motivoBloccoModificaFatturaRettificata(): ?string
    {
        $netto = $this->nettoNoteCollegate();
        if ($netto === null) {
            return null;
        }
        if ($netto['rettificato'] <= $netto['documento']) {
            return null;
        }

        // Le stesse note della cifra: le contestate non contano, e non si nominano (testuale della verifica).
        $una = count($netto['note']) === 1;

        return "Con questa modifica la fattura n. {$this->numero_documento} varrebbe " . MoneyHelper::format($netto['documento'])
            . ', meno di quanto ' . ($una ? 'rettifica ' : 'rettificano ') . $this->elencoNoteCollegate('', true) . ' ('
            . MoneyHelper::format($netto['rettificato']) . '). Controlla gli importi, o scollega prima una nota dall\'elenco.';
    }

    /**
     * Perché questa nota di credito non si può collegare a una fattura (lato NOTA) — o `null` se si può. Il parametro
     * serve all'elenco, che calcola in una query sola quali note sono nate da uno storno.
     */
    public function motivoBloccoCollegamento(?bool $eNataDaStorno = null): ?string
    {
        if ($this->tipo_documento !== 'nota_credito') {
            return 'Solo una nota di credito si collega a una fattura.';
        }

        if ($eNataDaStorno ?? $this->eNataDaStorno()) {
            return 'Questa nota è nata da uno storno: è già legata alla fattura che annulla, e non si collega a un\'altra.';
        }

        if ($this->fattura_rettificata_id !== null) {
            $numero = $this->fatturaRettificata?->numero_documento ?? ('#' . $this->fattura_rettificata_id);

            return "La nota rettifica già la fattura n. {$numero}: per cambiarla, prima «Scollega».";
        }

        return null;
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

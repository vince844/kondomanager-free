<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Traits\OrdinaElenco;

use App\Enums\TipoMovimentoContabile;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\StatoPatrimonialeService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\PaginaElenco;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller per la visualizzazione del Libro Giornale — elenco e dettaglio
 * delle Scritture Contabili.
 *
 * v1.9.1-beta.7: vista read-only di dettaglio.
 * v1.10.0-beta.29: elenco/registro sfogliabile, annidato per esercizio.
 * Lo storno si gestisce sempre dal documento genitore (pagamento/fattura).
 *
 * Responsabilità:
 *  - Caricare una scrittura con tutte le relazioni (righe, conti, documenti collegati)
 *  - Calcolare i totali DARE/AVERE e la quadratura
 *  - Preparare i dati per la pagina Inertia/Vue
 */
class ScritturaContabileController extends Controller
{
    use OrdinaElenco;

    /**
     * L'elenco scritture ha **tutte** le intestazioni già non ordinabili in `columns.ts`: qui non
     * c'è nulla da consentire, e la lista vuota lo dichiara invece di lasciarlo dedurre.
     */
    public static function colonneOrdinabili(): array
    {
        return [];
    }

    use HandleFlashMessages, HasEsercizio, HasCondomini, PaginaElenco, PdfRigheStampabili;

    /** Valori ammessi per il filtro stato — colonna DB enum, nessun PHP enum dietro. */
    private const STATI = ['bozza', 'registrata', 'riconciliata', 'annullata'];

    /**
     * Elenco paginato delle scritture contabili di un esercizio (Libro Giornale).
     */
    public function index(Request $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        $query = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->with(['righe.contoContabile', 'righe.cassa', 'righe.voceSpesa', 'gestione']);

        $this->applyFiltri($query, $request);

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        // ⚠️ **Venti e non dieci**, ed è una scelta di questo elenco. Le scritture del libro
        // giornale sono dense — protocollo, data, gestione, causale, tipo, importo, stato — e una
        // giornata di lavoro ne produce facilmente più di dieci: vederne dieci significa non
        // vedere la giornata. Resta comunque un valore di partenza: chi ne sceglie un altro qui
        // sopra se lo ritrova al rientro, e da lì in poi comanda la sua scelta.
        $perPage = $this->righePerPagina($request, predefinito: 20);

        // Il "page" in query string può arrivare da un altro esercizio (lo porta con sé lo
        // switcher di PageHeaderGuide, che riscrive solo il segmento URL): senza clamp, un
        // esercizio con meno pagine risulterebbe vuoto senza alcun modo di uscirne.
        $totale = (clone $query)->count();
        $ultimaPagina = max(1, (int) ceil($totale / $perPage));
        $paginaRichiesta = min(max((int) $request->input('page', 1), 1), $ultimaPagina);

        $scritture = (clone $query)
            ->orderByDesc('data_registrazione')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $paginaRichiesta)
            ->withQueryString();

        $rows = $scritture->getCollection()->map(function (ScritturaContabile $s) {
            $totaleDare  = $s->righe->where('tipo_riga', 'dare')->sum('importo');
            $totaleAvere = $s->righe->where('tipo_riga', 'avere')->sum('importo');

            return [
                'id'                   => $s->id,
                'numero_protocollo'    => $s->numero_protocollo,
                'data_registrazione'   => $s->data_registrazione?->format('d/m/Y'),
                'causale'              => $s->causale,
                'descrizione'          => $s->descrizione,
                'tipo_movimento'       => $s->tipo_movimento?->value,
                'tipo_movimento_label' => $s->tipo_movimento?->label(),
                'stato'                => $s->stato,
                'gestione'             => $s->gestione ? [
                    'id'   => $s->gestione->id,
                    'nome' => $s->gestione->nome,
                ] : null,
                'importo'              => (int) $totaleDare,
                'is_quadrata'          => $totaleDare === $totaleAvere,
                // ⚠️ **Coda 145 dei registri contabili, §10.1.1**: senza queste righe, la riga
                // espandibile non ha niente da mostrare. Stessa forma di `show()` — vedi
                // `self::serializzaRighe()` — perché elenco e dettaglio devono restare identici:
                // un conto, una nota o una voce di spesa che appaiono diversi a seconda di dove li
                // si guarda sarebbero un secondo malinteso sopra quello che questa beta chiude.
                'righe'                => self::serializzaRighe($s->righe),
            ];
        });

        // Riepilogo Dare/Avere sull'intero risultato filtrato, non sulla sola pagina corrente.
        $righeFiltrate = (clone $query)->with('righe')->get()->pluck('righe')->flatten();
        $riepilogoDareAvere = [
            'totale_dare'  => (int) $righeFiltrate->where('tipo_riga', 'dare')->sum('importo'),
            'totale_avere' => (int) $righeFiltrate->where('tipo_riga', 'avere')->sum('importo'),
        ];

        $statoPatrimoniale = app(StatoPatrimonialeService::class)->calcola($condominio, $esercizio);

        // Le query di diagnosi girano solo quando serve: sul percorso sano (quadra) non
        // aggiungono alcun costo.
        $diagnosi = $statoPatrimoniale['quadra'] ? null : $this->diagnosiSbilancio($condominio, $esercizio);

        return Inertia::render('gestionale/movimenti/scritture/List', [
            'condominio' => $condominio,
            'condomini'  => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio'  => $esercizio,
            'esercizi'   => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'scritture'  => [
                'data' => $rows,
                'meta' => [
                    'current_page' => $scritture->currentPage(),
                    'last_page'    => $scritture->lastPage(),
                    'total'        => $scritture->total(),
                    'per_page'     => $scritture->perPage(),
                ],
            ],
            'tipiMovimento' => collect(TipoMovimentoContabile::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values(),
            'stati' => self::STATI,
            'riepilogoDareAvere' => $riepilogoDareAvere,
            'quadratura' => [
                'quadra'  => $statoPatrimoniale['quadra'],
                'sbilancio' => $statoPatrimoniale['sbilancio'],
                'totale_attivo'  => $statoPatrimoniale['attivo']['totale'],
                'totale_passivo' => $statoPatrimoniale['passivo']['totale'],
                'costi'   => $statoPatrimoniale['costi'],
                'ricavi'  => $statoPatrimoniale['ricavi'],
                'risultato_esercizio' => $statoPatrimoniale['risultato_esercizio'],
                'liquidita_non_contabilizzata' => $statoPatrimoniale['liquidita_non_contabilizzata'],
            ],
            'diagnosi' => $diagnosi,
            'filters' => $request->only(['search', 'tipo_movimento', 'stato', 'data_da', 'data_a']),
        ]);
    }

    /**
     * Cerca le due cause di sbilancio note e diagnosticabili automaticamente:
     *
     * 1. Scritture non bilanciate al loro interno (Dare ≠ Avere sulla singola
     *    scrittura) — quasi certamente la causa se ce n'è anche solo una.
     * 2. Casse con `saldo_iniziale != 0`: per costruzione (vedi
     *    RegistraAperturaCassaAction, che azzera la colonna nella stessa
     *    transazione in cui registra l'apertura) un valore residuo — positivo
     *    o negativo — significa che l'apertura non è mai stata portata a
     *    giornale ed è esattamente la liquidità che StatoPatrimonialeService
     *    somma "a mano" come liquidita_non_contabilizzata. Sul perché non basti
     *    guardare i positivi, vedi il commento accanto alla query.
     *
     * Oltre questi due casi la diagnosi automatica si ferma: un importo
     * digitato male ma comunque bilanciato non lascia traccia distinguibile.
     */
    /**
     * Serializza le righe di una scrittura in partita doppia — un conto, una nota, una voce di
     * spesa — nella stessa forma sia per l'elenco (riga espandibile) sia per il dettaglio.
     *
     * Estratto dal codice di `show()`, che lo faceva inline: prima di questa beta l'elenco non
     * mandava le righe affatto, quindi non c'era ancora un secondo posto da tenere allineato.
     */
    /**
     * Appiattisce un insieme di scritture (con `righe.contoContabile/cassa/voceSpesa` già
     * caricate) nell'elenco di righe che la stampa del Libro Giornale mette a video: una voce
     * per riga di partita doppia, in ordine cronologico, dare prima di avere.
     *
     * Estratto a parte — e non lasciato dentro `stampa()` — apposta per poterlo misurare senza
     * generare un PDF: la trasformazione dei dati e la generazione grafica sono due cose diverse
     * da provare in due modi diversi.
     */
    private static function righePerStampa($scritture): array
    {
        $righe = [];
        foreach ($scritture as $scrittura) {
            // Dare prima di avere, stessa regola di lettura di RigheEspanse.vue e Show.vue: il
            // backend non la garantisce da solo, quindi qui — dove non c'è un frontend a
            // riordinare — è questo metodo a doverla imporre.
            $righeOrdinate = $scrittura->righe->sortBy(fn ($r) => $r->tipo_riga === 'avere' ? 1 : 0);

            foreach ($righeOrdinate as $riga) {
                $dettaglio = collect([
                    $riga->cassa?->nome,
                    $riga->voceSpesa?->nome,
                    $riga->note,
                ])->filter()->implode(' · ');

                $righe[] = [
                    'data'         => $scrittura->data_registrazione?->format('d/m/Y'),
                    'protocollo'   => $scrittura->numero_protocollo,
                    'causale'      => $scrittura->causale,
                    'conto_nome'   => $riga->contoContabile->nome ?? 'Conto non specificato',
                    'conto_codice' => $riga->contoContabile->codice ?? null,
                    'dettaglio'    => $dettaglio !== '' ? $dettaglio : null,
                    'dare'         => $riga->tipo_riga === 'dare' ? (int) $riga->importo : null,
                    'avere'        => $riga->tipo_riga === 'avere' ? (int) $riga->importo : null,
                ];
            }
        }

        return $righe;
    }

    /**
     * Trasforma un valore di filtro data in `d/m/Y` leggibile, o null se non è una data vera.
     *
     * Non lancia mai. `$request->data_da` è testo libero in arrivo dalla query string — può
     * essere `"pippo"`, un array (`?data_da[]=...`), o mancante — e `Carbon::parse()` su un
     * valore così manda un'eccezione non catturata. La vista PDF non deve mai vedere quel
     * rischio: qui il valore o diventa una data leggibile, o diventa "nessun filtro".
     */
    private static function dataFiltroLeggibile($valore): ?string
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($valore)->format('d/m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function serializzaRighe($righe): array
    {
        return $righe->map(fn ($r) => [
            'id'        => $r->id,
            'tipo_riga' => $r->tipo_riga,
            'importo'   => $r->importo,
            'note'      => $r->note,
            'conto'     => $r->contoContabile ? [
                'id'     => $r->contoContabile->id,
                'codice' => $r->contoContabile->codice,
                'nome'   => $r->contoContabile->nome,
            ] : null,
            'cassa' => $r->cassa ? [
                'id'   => $r->cassa->id,
                'nome' => $r->cassa->nome,
            ] : null,
            'voce_spesa' => $r->voceSpesa ? [
                'id'   => $r->voceSpesa->id,
                'nome' => $r->voceSpesa->nome,
            ] : null,
        ])->all();
    }

    /**
     * Stampa il Libro Giornale riga per riga, in forma cronologica classica: data, protocollo,
     * conto, dare, avere. È la richiesta con cui è nato `docs/registri_contabili.md` (§10.1.2) —
     * "richiesta da un utente, non un'iniziativa nostra" — ed è l'«Allegato 1» analitico, non il
     * fascicolo di rendiconto (§10.5): niente firma, niente riepilogo per capitolo.
     *
     * ⚠️ Rispetta **gli stessi filtri dell'elenco a schermo** (`applyFiltri`, condiviso): stampare
     * "quello che vedo" e non "tutto l'esercizio" è la lettura naturale di un pulsante messo sulla
     * stessa pagina filtrata, ed evita la sorpresa opposta — un PDF più lungo di quanto la pagina
     * lasciasse immaginare.
     */
    public function stampa(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdfService)
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        $query = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->with(['righe.contoContabile', 'righe.cassa', 'righe.voceSpesa']);

        $this->applyFiltri($query, $request);

        // Cronologico in avanti: un giornale si legge dal primo movimento all'ultimo, il
        // contrario dell'elenco a schermo (che apre sul più recente perché è quello che si
        // cerca appena entrati). Dentro la stessa data, l'id tiene l'ordine di registrazione.
        $scritture = $query->orderBy('data_registrazione')->orderBy('id')->get();

        $righe = self::righePerStampa($scritture);

        // ⚠️ **Oltre il tetto si dice, non si muore.** Anche a blocchi la stampa ha un limite:
        // superato, PHP muore con un fatal error DENTRO mPDF — pagina bianca, nessuna eccezione
        // applicativa, niente nei log. Meglio un rifiuto che spiega cosa fare.
        if (count($righe) > self::righeStampabili()) {
            return back()->with($this->flashError(
                'Il registro filtrato ha '.number_format(count($righe), 0, ',', '.').' righe: '
                .'troppe per generare un PDF su questo server senza rischiare di interrompersi a metà. '
                .'Restringi il periodo con i filtri di data e stampa il giornale in più parti.'
            ));
        }

        $totaleDare  = (int) array_sum(array_column($righe, 'dare'));
        $totaleAvere = (int) array_sum(array_column($righe, 'avere'));

        $mpdf = $pdfService->generate('pdf.gestionale.libro_giornale', [
            'condominio'   => $condominio,
            'esercizio'    => $esercizio,
            'righe'        => $righe,
            'totale_dare'  => $totaleDare,
            'totale_avere' => $totaleAvere,
            // ⚠️ **Tutti e cinque i filtri, non due.** `applyFiltri()` ne applica cinque; la
            // prima stesura ne passava alla vista solo due, e un PDF filtrato per stato o per
            // testo usciva intitolato «LIBRO GIORNALE» con un TOTALE parziale e **nessuna
            // parola** su cosa mancasse — intestazione identica, MD5 compreso, a quella della
            // stampa completa. Su un documento che può finire in assemblea o davanti a un CTU
            // il perimetro va dichiarato: tacerlo è la stessa classe di malinteso che questa
            // beta esiste per chiudere. Trovato dalla Fase 1-bis, tre lenti indipendenti.
            // ⚠️ **Le date arrivano già formattate, mai grezze.** La prima stesura passava
            // `$request->data_da` così com'è e lasciava alla vista `Carbon::parse(...)`: un
            // valore non interpretabile (`?data_da=pippo`, o un array da `?data_da[]=...`)
            // mandava in 500 la stampa mentre lo stesso URL sull'elenco rispondeva 200 — la
            // pagina tollera un filtro sporco, il PDF esplodeva. Misurato dalla Fase 1-bis.
            // `self::dataFiltroLeggibile()` non lancia mai: un valore che non si interpreta
            // diventa null, cioè "filtro assente", non un errore.
            'filtri'       => [
                'data_da'        => self::dataFiltroLeggibile($request->data_da),
                'data_a'         => self::dataFiltroLeggibile($request->data_a),
                'search'         => is_string($request->search) ? $request->search : null,
                // L'etichetta, non il valore grezzo dell'enum: nel PDF «pagamento_fornitore»
                // con l'underscore è gergo di database davanti a un condòmino.
                'tipo_movimento' => $request->tipo_movimento
                    ? (TipoMovimentoContabile::tryFrom($request->tipo_movimento)?->label() ?? $request->tipo_movimento)
                    : null,
                'stato'          => is_string($request->stato) ? $request->stato : null,
            ],
            // §10.5 di docs/registri_contabili.md: è un registro, non un atto da sottoscrivere.
            'senza_firma'  => true,
            // Compare in testa a OGNI pagina (pdf.base.blade.php): senza, un registro di più
            // pagine si identifica solo nella prima — dalla seconda in poi resta solo il nome
            // del condominio, e staccata o fotocopiata quella pagina non dice più di che
            // documento si tratti né di quale esercizio.
            'titolo_stampa' => 'Libro Giornale – '.$esercizio->nome,
        ], [
            'orientation' => 'L',
            // 38 e non 32: la riga «Libro Giornale – <esercizio>» aggiunta all'intestazione di
            // pagina la fa più alta, e col margine di prima il suo bordo inferiore cadeva
            // esattamente sul titolo del contenuto — «LIBRO GIORNALE» usciva barrato. Visto
            // guardando il PDF, non leggendo il codice.
            'margin_top'  => 38,
        ]);

        // ⚠️ **`Output(..., 'I')` non torna byte: scrive sull'output buffer ed esce vuota.**
        // `response('')` funzionava per un browser — l'echo di mPDF precede comunque l'invio —
        // ma il corpo della risposta era vuoto per costruzione: nessun test, e nessun middleware
        // a valle, può ispezionare un PDF generato così. `Destination::STRING_RETURN` restituisce
        // i byte veri; il `Content-Type` fa lo stesso lavoro che faceva prima nel browser.
        return response($mpdf->Output('libro_giornale.pdf', \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf');
    }

    private function diagnosiSbilancio(Condominio $condominio, Esercizio $esercizio): array
    {
        $scrittureNonQuadrate = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->with('righe')
            ->get()
            ->filter(fn (ScritturaContabile $s) => $s->righe->where('tipo_riga', 'dare')->sum('importo')
                !== $s->righe->where('tipo_riga', 'avere')->sum('importo'))
            ->map(fn (ScritturaContabile $s) => [
                'id'                => $s->id,
                'numero_protocollo' => $s->numero_protocollo,
                'causale'           => $s->causale,
            ])
            ->values();

        // `!= 0` e non `> 0`: `StatoPatrimonialeService` somma TUTTI i saldi non ancora a
        // giornale, negativi compresi, quindi un conto scoperto sbilancia esattamente come
        // uno positivo. Cercando i soli positivi la diagnosi era cieca su metà dei casi, e
        // quello sbilancio finiva nel ramo «causa non nota» — l'unico che non offre niente
        // da fare. Il saldo negativo non è un caso di frontiera: `RegistraAperturaCassaAction`
        // lo gestisce esplicitamente invertendo i versi.
        $casseSenzaApertura = Cassa::where('condominio_id', $condominio->id)
            ->where('saldo_iniziale', '!=', 0)
            ->get(['id', 'nome', 'saldo_iniziale'])
            ->map(fn (Cassa $c) => [
                'id'             => $c->id,
                'nome'           => $c->nome,
                'saldo_iniziale' => $c->saldo_iniziale,
            ])
            ->values();

        return [
            'scritture_non_quadrate' => $scrittureNonQuadrate,
            'casse_senza_apertura'   => $casseSenzaApertura,
        ];
    }

    /**
     * Applica i filtri della toolbar alla query (condivisa fra elenco e riepilogo).
     */
    private function applyFiltri($query, Request $request): void
    {
        $query
            ->when($request->search, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('causale', 'like', "%{$v}%")
                        ->orWhere('descrizione', 'like', "%{$v}%")
                        ->orWhere('numero_protocollo', 'like', "%{$v}%");
                });
            })
            ->when($request->tipo_movimento, fn ($q, $v) => $q->where('tipo_movimento', $v))
            ->when($request->stato, fn ($q, $v) => $q->where('stato', $v))
            ->when($request->data_da, fn ($q, $v) => $q->whereDate('data_registrazione', '>=', $v))
            ->when($request->data_a, fn ($q, $v) => $q->whereDate('data_registrazione', '<=', $v));
    }

    /**
     * Mostra il dettaglio di una singola scrittura contabile.
     *
     * Carica le righe in partita doppia, i documenti collegati (pagamento fornitore,
     * fatture passive), e le scritture correlate (padre/figlie per storni).
     */
    public function show(Condominio $condominio, ScritturaContabile $scrittura): Response
    {
        // ── Guard: appartenenza al condominio ─────────────────────────────
        if ($scrittura->condominio_id !== $condominio->id) {
            abort(403, 'La scrittura non appartiene a questo condominio.');
        }

        // ── Eager loading di tutte le relazioni necessarie ────────────────
        $scrittura->load([
            'righe.contoContabile',
            'righe.cassa',
            'righe.voceSpesa',
            'padre',
            'figlie',
            'esercizio',
            'gestione',
            'pagamentoFornitore.fornitore',
            'pagamentoFornitore.contoCorrente',
            'fatture',
        ]);

        // ── Calcolo quadratura partita doppia ─────────────────────────────
        $totaleDare  = $scrittura->righe->where('tipo_riga', 'dare')->sum('importo');
        $totaleAvere = $scrittura->righe->where('tipo_riga', 'avere')->sum('importo');

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/scritture/Show', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'scrittura'  => [
                'id'                   => $scrittura->id,
                'data_registrazione'   => $scrittura->data_registrazione?->format('d/m/Y'),
                'data_competenza'      => $scrittura->data_competenza?->format('d/m/Y'),
                'numero_protocollo'    => $scrittura->numero_protocollo,
                'causale'              => $scrittura->causale,
                'descrizione'          => $scrittura->descrizione,
                'tipo_movimento'       => $scrittura->tipo_movimento?->value,
                'tipo_movimento_label' => $scrittura->tipo_movimento?->label(),
                'stato'                => $scrittura->stato,
                'note'                 => $scrittura->note,
                'created_at'           => $scrittura->created_at?->format('d/m/Y H:i'),

                // Contesto
                'esercizio' => $scrittura->esercizio ? [
                    'id'   => $scrittura->esercizio->id,
                    'nome' => $scrittura->esercizio->nome,
                ] : null,
                'gestione' => $scrittura->gestione ? [
                    'id'   => $scrittura->gestione->id,
                    'nome' => $scrittura->gestione->nome,
                ] : null,

                // Righe in partita doppia — stessa forma dell'elenco, vedi self::serializzaRighe()
                'righe' => self::serializzaRighe($scrittura->righe),

                // Totali e quadratura
                'totale_dare'  => $totaleDare,
                'totale_avere' => $totaleAvere,
                'is_quadrata'  => $totaleDare === $totaleAvere,

                // Scritture collegate (storni)
                'padre' => $scrittura->padre ? [
                    'id'      => $scrittura->padre->id,
                    'causale' => $scrittura->padre->causale,
                    'tipo_movimento_label' => $scrittura->padre->tipo_movimento?->label(),
                ] : null,
                'figlie' => $scrittura->figlie->map(fn ($f) => [
                    'id'      => $f->id,
                    'causale' => $f->causale,
                    'tipo_movimento_label' => $f->tipo_movimento?->label(),
                    'created_at' => $f->created_at?->format('d/m/Y H:i'),
                ]),

                // Pagamento fornitore collegato (1:1)
                'pagamento_fornitore' => $scrittura->pagamentoFornitore ? [
                    'id'                => $scrittura->pagamentoFornitore->id,
                    'importo_lordo'     => $scrittura->pagamentoFornitore->importo_lordo,
                    'importo_netto'     => $scrittura->pagamentoFornitore->importo_netto,
                    'importo_ritenuta'  => $scrittura->pagamentoFornitore->importo_ritenuta,
                    'importo_commissione' => $scrittura->pagamentoFornitore->importo_commissione,
                    'metodo_pagamento'  => $scrittura->pagamentoFornitore->metodo_pagamento?->value,
                    'data_pagamento'    => $scrittura->pagamentoFornitore->data_pagamento?->format('d/m/Y'),
                    'iban_beneficiario' => $scrittura->pagamentoFornitore->iban_beneficiario,
                    'causale_bonifico'  => $scrittura->pagamentoFornitore->causale_bonifico,
                    'stato'             => $scrittura->pagamentoFornitore->stato?->value,
                    'stato_label'       => $scrittura->pagamentoFornitore->stato?->label(),
                    'bonifico_parlante' => $scrittura->pagamentoFornitore->bonifico_parlante,
                    'fornitore'         => $scrittura->pagamentoFornitore->fornitore ? [
                        'id'              => $scrittura->pagamentoFornitore->fornitore->id,
                        'ragione_sociale' => $scrittura->pagamentoFornitore->fornitore->ragione_sociale,
                    ] : null,
                    'conto_corrente' => $scrittura->pagamentoFornitore->contoCorrente ? [
                        'id'   => $scrittura->pagamentoFornitore->contoCorrente->id,
                        'nome' => $scrittura->pagamentoFornitore->contoCorrente->nome,
                    ] : null,
                ] : null,

                // Fatture passive collegate (via pivot fattura_scrittura)
                'fatture' => $scrittura->fatture->map(fn ($f) => [
                    'id'                 => $f->id,
                    'numero_documento'   => $f->numero_documento,
                    'data_documento'     => $f->data_documento?->format('d/m/Y'),
                    'tipo_documento'     => $f->tipo_documento,
                    'importo_allocato'   => $f->pivot->importo_allocato,
                    'tipo_allocazione'   => $f->pivot->tipo?->value ?? $f->pivot->tipo,
                    'dati_extra'         => $f->dati_extra,
                    'stato_approvazione' => $f->stato_approvazione,
                ]),
            ],
        ]);
    }
}

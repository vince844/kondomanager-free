<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\StatoPatrimonialeService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
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
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    /** Valori ammessi per il filtro stato — colonna DB enum, nessun PHP enum dietro. */
    private const STATI = ['bozza', 'registrata', 'riconciliata', 'annullata'];

    /** Opzioni esposte dal selettore righe-per-pagina in Datatable.vue. */
    private const PER_PAGE_AMMESSI = [15, 20, 30, 40, 50];
    private const PER_PAGE_DEFAULT = 20;

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
            ->with(['righe', 'gestione']);

        $this->applyFiltri($query, $request);

        // per_page: solo i valori esposti dal selettore in Datatable.vue.
        $perPage = in_array((int) $request->input('per_page'), self::PER_PAGE_AMMESSI, true)
            ? (int) $request->input('per_page')
            : self::PER_PAGE_DEFAULT;

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
     * 2. Casse con `saldo_iniziale > 0`: per costruzione (vedi
     *    RegistraAperturaCassaAction, che azzera la colonna nella stessa
     *    transazione in cui registra l'apertura) un valore residuo positivo
     *    significa che l'apertura non è mai stata portata a giornale — è
     *    esattamente la liquidità che StatoPatrimonialeService somma "a mano"
     *    come liquidita_non_contabilizzata.
     *
     * Oltre questi due casi la diagnosi automatica si ferma: un importo
     * digitato male ma comunque bilanciato non lascia traccia distinguibile.
     */
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

        $casseSenzaApertura = Cassa::where('condominio_id', $condominio->id)
            ->where('saldo_iniziale', '>', 0)
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

                // Righe in partita doppia
                'righe' => $scrittura->righe->map(fn ($r) => [
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
                ]),

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

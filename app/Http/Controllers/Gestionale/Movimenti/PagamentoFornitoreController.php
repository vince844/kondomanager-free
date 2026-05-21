<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Exceptions\Pagamenti\IbanDiscrepanzaException;
use App\Exceptions\Pagamenti\PossibilePagamentoDuplicatoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StorePagamentoFornitoreRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Http\Resources\Gestionale\Movimenti\PagamentoFornitoreResource;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller per la registrazione dei pagamenti ai fornitori.
 *
 * Responsabilità:
 *  - Preparare i dati per il form Vue (create)
 *  - Validare e delegare al PagamentoFornitoreService (store)
 *  - Fornire endpoint API per pendenze fornitore e treasury forecast
 *
 * Tutta la logica contabile (partita doppia, netting, storno) vive nel service.
 */
class PagamentoFornitoreController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    public function __construct(private PagamentoFornitoreService $service) {}

    /**
     * Mostra l'elenco dei pagamenti registrati per il condominio selezionato.
     *
     * @param Request $request
     * @param Condominio $condominio
     */
    public function index(Request $request, Condominio $condominio): Response
    {
        $pagamenti = PagamentoFornitore::where('condominio_id', $condominio->id)
            ->with(['fornitore', 'contoCorrente', 'scrittura'])
            ->when($request->search, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->whereHas('fornitore', fn($qf) => $qf->where('ragione_sociale', 'like', "%{$v}%"))
                        ->orWhereHas('scrittura', fn($qs) => $qs->where('descrizione', 'like', "%{$v}%"));
                });
            })
            ->when($request->metodo_pagamento, fn($q, $v) => $q->where('metodo_pagamento', $v))
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        $stats = [
            'totale_pagato_mese' => PagamentoFornitore::where('condominio_id', $condominio->id)
                ->where('stato', 'confermato')
                ->whereMonth('data_pagamento', now()->month)
                ->whereYear('data_pagamento', now()->year)
                ->sum('importo_lordo'),
            'totale_stornati' => PagamentoFornitore::where('condominio_id', $condominio->id)
                ->where('stato', 'stornato')
                ->count(),
            'totale_ritenute' => PagamentoFornitore::where('condominio_id', $condominio->id)
                ->where('stato', 'confermato')
                ->where('importo_ritenuta', '>', 0)
                ->count(),
        ];

        return Inertia::render('gestionale/movimenti/pagamenti/PagamentiList', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'pagamenti'  => PagamentoFornitoreResource::collection($pagamenti),
            'stats'      => $stats,
            'filters'    => $request->only(['search', 'metodo_pagamento']),
        ]);
    }

    /**
     * Mostra il form per la registrazione di un nuovo pagamento fornitore.
     *
     * Prepara: fornitori, banche con saldo, esercizio, gestioni.
     * Layout: 2 colonne (Panel + Ledger) stile FatturaRegisterNew.
     */
    public function create(Condominio $condominio): Response
    {
        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        // Banche e casse con saldo calcolato dal giornale
        $banche = Cassa::where('condominio_id', $condominio->id)
            ->where('attiva', true)
            ->where('tipo', '!=', 'fondo')
            ->withSum(['movimenti as totale_entrate' => function ($q) {
                $q->where('tipo_riga', 'dare');
            }], 'importo')
            ->withSum(['movimenti as totale_uscite' => function ($q) {
                $q->where('tipo_riga', 'avere');
            }], 'importo')
            ->get()
            ->map(function ($cassa) {
                $entrate = $cassa->totale_entrate ?? 0;
                $uscite  = $cassa->totale_uscite ?? 0;
                $saldoIniziale = $cassa->saldo_iniziale ?? 0;
                $saldoAttuale = $saldoIniziale + $entrate - $uscite;

                return [
                    'id'            => $cassa->conto_contabile_id,
                    'cassa_id'      => $cassa->id,
                    'nome'          => $cassa->nome,
                    'tipo'          => $cassa->tipo,
                    'iban'          => $cassa->contoCorrente?->iban ?? null,
                    'saldo_attuale' => $saldoAttuale,
                ];
            });

        // Gestioni attive dell'esercizio corrente
        $gestioni = $esercizio
            ? $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(fn ($g) => [
                    'id'            => $g->id,
                    'nome'          => $g->nome,
                    'tipo'          => $g->tipo,
                    'esercizio_ids' => $g->esercizi->pluck('id')->toArray(),
                ])
            : collect();

        return Inertia::render('gestionale/movimenti/pagamenti/PagamentoNew', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'esercizi'   => $condominio->esercizi()->where('stato', 'aperto')->get(),
            'fornitori'  => Fornitore::all(),
            'banche'     => $banche,
            'gestioni'   => $gestioni,
        ]);
    }

    /**
     * Registra un pagamento fornitore.
     *
     * Delega interamente al PagamentoFornitoreService.
     * Gestisce le eccezioni speciali (IBAN, duplicati) con codici flash
     * che il frontend intercetta per mostrare modali di conferma.
     */
    public function store(StorePagamentoFornitoreRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            $data = $request->validated();
            $data['condominio_id'] = $condominio->id;

            $this->service->registraPagamento($data);

            return back()->with($this->flashSuccess('Pagamento registrato con successo.'));

        } catch (IbanDiscrepanzaException $e) {
            return back()->withErrors([
                'iban_discrepanza' => $e->getMessage(),
            ]);

        } catch (PossibilePagamentoDuplicatoException $e) {
            return back()->withErrors([
                'possibile_duplicato' => $e->getMessage(),
            ]);

        } catch (\Exception $e) {
            Log::error("Errore registrazione pagamento fornitore: " . $e->getMessage());
            Log::error("Traccia: " . $e->getTraceAsString());

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * API: Restituisce le fatture pendenti di un fornitore.
     *
     * Filtra per: fornitore_id, stato_pagamento IN [aperta, parziale], approvata.
     * Include note di credito aperte per il pannello auto-netting.
     * Calcola il residuo per ogni fattura (via accessor).
     */
    public function pendenze(Request $request, Condominio $condominio): JsonResponse
    {
        $request->validate([
            'fornitore_id' => 'required|integer|exists:fornitori,id',
        ]);

        $fornitoreId = (int) $request->input('fornitore_id');

        // Fatture aperte/parziali del fornitore
        // Fatture aperte/parziali del fornitore (incluse non approvate per mostrarle disabilitate)
        $pendenze = FatturaPassiva::where('condominio_id', $condominio->id)
            ->where('fornitore_id', $fornitoreId)
            ->whereIn('stato_pagamento', [
                StatoPagamentoFattura::APERTA->value,
                StatoPagamentoFattura::PARZIALE->value,
            ])
            ->with('righe')
            ->orderBy('data_scadenza')
            ->get()
            ->map(function (FatturaPassiva $f) {
                $lordo = $f->importo_imponibile + $f->importo_iva;
                $residuoCents = $f->residuo;

                // Gestione derivata dalla prima scrittura di competenza
                $gestioneId = $f->scritture()->first()?->gestione_id;

                return [
                    'id'                => $f->id,
                    'tipo_documento'    => $f->tipo_documento,
                    'numero_documento'  => $f->numero_documento ?? "FT#{$f->id}",
                    'data_documento'    => $f->data_documento?->format('d/m/Y'),
                    'data_scadenza'     => $f->data_scadenza?->format('Y-m-d'),
                    'data_scadenza_fmt' => $f->data_scadenza?->format('d/m/Y'),
                    'importo_lordo'     => $lordo,
                    'netto_a_pagare'    => $f->netto_a_pagare,
                    'residuo'           => $residuoCents,
                    'stato_pagamento'   => $f->stato_pagamento->value,
                    'stato_approvazione'=> $f->stato_approvazione,
                    'is_scaduta'        => $f->data_scadenza && $f->data_scadenza->isPast(),
                    'is_nota_credito'   => $f->tipo_documento === 'nota_credito',
                    'gestione_id'       => $gestioneId,
                    'descrizione_righe' => $f->righe->pluck('descrizione')->filter()->implode(', '),
                ];
            });

        // Note di credito compensabili (per Smart Router netting)
        $noteCredito = $this->service->trovaNoteCreditoCompensabili($fornitoreId, $condominio->id)
            ->map(function (FatturaPassiva $nc) {
                return [
                    'id'                => $nc->id,
                    'tipo_documento'    => 'nota_credito',
                    'numero_documento'  => $nc->numero_documento ?? "NC#{$nc->id}",
                    'data_documento'    => $nc->data_documento?->format('d/m/Y'),
                    'netto_a_pagare'    => abs($nc->netto_a_pagare),
                    'residuo'           => abs($nc->residuo),
                    'is_nota_credito'   => true,
                    'gestione_id'       => $nc->scritture()->first()?->gestione_id,
                    // Necessario per il controllo di selezione nel frontend:
                    // togglePendenza() controlla p.stato_approvazione === 'approvata'
                    // Se mancante (undefined) il click viene sempre bloccato.
                    'stato_approvazione' => $nc->stato_approvazione,
                    'is_scaduta'         => false, // Le NC non hanno scadenza di pagamento
                    'stato_pagamento'    => $nc->stato_pagamento->value,
                ];
            });

        // Merge: le NC aperte possono essere già incluse nelle pendenze
        $ncIds = $noteCredito->pluck('id');
        $risultato = $pendenze->reject(fn ($p) => $p['is_nota_credito'] && $ncIds->contains($p['id']))
            ->merge($noteCredito)
            ->values();

        return response()->json([
            'pendenze'      => $risultato,
            'has_netting'   => $noteCredito->isNotEmpty() && $pendenze->where('is_nota_credito', false)->isNotEmpty(),
            'totale_nc'     => $noteCredito->sum('residuo'),
            'totale_ft'     => $pendenze->where('is_nota_credito', false)->sum('residuo'),
        ]);
    }
}

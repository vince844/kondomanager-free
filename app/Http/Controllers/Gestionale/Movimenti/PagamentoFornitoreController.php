<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Exceptions\Pagamenti\AllocazioniInconsistentiException;
use App\Exceptions\Pagamenti\FatturaNonApprovataException;
use App\Exceptions\Pagamenti\FiscalYearClosedException;
use App\Exceptions\Pagamenti\IbanDiscrepanzaException;
use App\Exceptions\Pagamenti\IllegalCashAmountException;
use App\Exceptions\Pagamenti\InsufficientFundsException;
use App\Exceptions\Pagamenti\OverpaymentException;
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
use App\Services\PDF\PdfService;
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
            ->when($request->has('has_ritenuta'), fn($q) => $q->where('importo_ritenuta', '>', 0))
            ->when($request->stato, fn($q, $v) => $q->where('stato', $v))
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        $stats = [
            'totale_pagato_mese' => PagamentoFornitore::where('condominio_id', $condominio->id)
                ->where('stato', 'confermato')
                ->whereNull('pagamento_padre_id')
                ->whereMonth('data_pagamento', now()->month)
                ->whereYear('data_pagamento', now()->year)
                ->sum('importo_lordo'),
            'totale_stornati' => PagamentoFornitore::where('condominio_id', $condominio->id)
                ->where('stato', 'stornato')
                ->count(),
            'totale_ritenute' => PagamentoFornitore::where('condominio_id', $condominio->id)
                ->where('stato', 'confermato')
                ->whereNull('pagamento_padre_id')
                ->where('importo_ritenuta', '>', 0)
                ->count(),
        ];

        return Inertia::render('gestionale/movimenti/pagamenti/PagamentiList', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'pagamenti'  => PagamentoFornitoreResource::collection($pagamenti),
            'stats'      => $stats,
            'filters'    => $request->only(['search', 'metodo_pagamento', 'has_ritenuta', 'stato']),
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

        $preselectedFatturaId = request('fattura_id') ? (int)request('fattura_id') : null;
        $preselectedFornitoreId = request('fornitore_id') ? (int)request('fornitore_id') : null;

        if ($preselectedFatturaId && !$preselectedFornitoreId) {
            $fattura = FatturaPassiva::find($preselectedFatturaId);
            if ($fattura) {
                $preselectedFornitoreId = $fattura->fornitore_id;
            }
        }

        return Inertia::render('gestionale/movimenti/pagamenti/PagamentoNew', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'esercizi'   => $condominio->esercizi()->where('stato', 'aperto')->get(),
            'fornitori'  => Fornitore::all(),
            'banche'     => $banche,
            'gestioni'   => $gestioni,
            'preselected_fornitore_id' => $preselectedFornitoreId,
            'preselected_fattura_id'   => $preselectedFatturaId,
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

        // ── BLOCCHI BYPASSABILI CON CONFERMA ESPLICITA (modali con override) ──────────

        } catch (IbanDiscrepanzaException $e) {
            // Sentinella Anti-Frode: IBAN differisce dall'anagrafica.
            // Bypassabile con iban_confermato_manualmente=true dopo conferma esplicita.
            return back()->withErrors([
                'iban_discrepanza' => $e->getMessage(),
            ]);

        } catch (PossibilePagamentoDuplicatoException $e) {
            // Warning duplicato: stesso importo+fattura nelle ultime 24h.
            // Bypassabile con conferma_duplicato_verificato=true.
            return back()->withErrors([
                'possibile_duplicato' => $e->getMessage(),
            ]);

        } catch (InsufficientFundsException $e) {
            // Saldo conto insufficiente. Bypassabile con allow_overdraft=true
            // + nota_override obbligatoria (art. 1129 c.c. — responsabilità admin).
            // I dati strutturati viaggiano come JSON nella stessa chiave errors
            // perché Inertia garantisce errors al frontend; ->with() va in sessione
            // e il middleware condivide solo 'flash.message', non chiavi custom.
            return back()->withErrors([
                'insufficient_funds'      => $e->getMessage(),
                'insufficient_funds_data' => json_encode([
                    'saldo_cents'      => $e->saldoCents,
                    'necessario_cents' => $e->necessarioCents,
                    'scopertura_cents' => $e->scoperturaCents,
                ]),
            ]);

        } catch (OverpaymentException $e) {
            // Importo allocato supera il residuo fattura.
            // Bypassabile con allow_overpayment=true + nota_override obbligatoria.
            // Stessa strategia: dati strutturati come JSON in errors.
            return back()->withErrors([
                'overpayment'      => $e->getMessage(),
                'overpayment_data' => json_encode([
                    'allocato_cents' => $e->allocatoCents,
                    'residuo_cents'  => $e->residuoCents,
                    'num_fattura'    => $e->numFattura,
                ]),
            ]);

        // ── BLOCCHI NON BYPASSABILI (modali informative, no override) ─────────────

        } catch (FiscalYearClosedException $e) {
            // Esercizio chiuso: nessuna scrittura possibile.
            // Non bypassabile — l'admin deve selezionare un esercizio aperto.
            return back()->withErrors(['fiscal_year_closed' => $e->getMessage()]);

        } catch (IllegalCashAmountException $e) {
            // Pagamento contanti ≥ 5.000€ — violazione D.Lgs. 231/2007 antiriciclaggio.
            // Non bypassabile per proteggere l'admin da sanzioni penali/amministrative.
            return back()->withErrors(['illegal_cash' => $e->getMessage()]);

        } catch (FatturaNonApprovataException $e) {
            // Fattura non approvata: art. 1135 c.c. richiede delibera assembleare.
            // Non bypassabile — l'admin deve prima ottenere l'approvazione.
            return back()->withErrors(['fattura_non_approvata' => $e->getMessage()]);

        } catch (AllocazioniInconsistentiException $e) {
            // Errore dati: fatture di fornitori/condomini diversi nello stesso pagamento.
            // Non bypassabile — errore operativo da correggere.
            return back()->withErrors(['allocazioni_inconsistenti' => $e->getMessage()]);

        } catch (\Exception $e) {
            // Errore tecnico non previsto. La transazione atomica garantisce
            // che nessun dato parziale sia stato scritto nel DB.
            Log::error('Errore registrazione pagamento fornitore: ' . $e->getMessage());
            Log::error('Traccia: ' . $e->getTraceAsString());

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

    /**
     * Mostra il dettaglio di un singolo pagamento fornitore.
     *
     * Carica il pagamento con tutte le relazioni (scrittura, fatture allocate,
     * fornitore, conto) e lo passa alla pagina Inertia.
     *
     * Guard: il pagamento deve appartenere al condominio della route.
     */
    public function show(Condominio $condominio, PagamentoFornitore $pagamento): Response
    {
        abort_if($pagamento->condominio_id !== $condominio->id, 403, 'Il pagamento non appartiene a questo condominio.');

        $pagamento->load([
            'fornitore',
            'contoCorrente',
            'scrittura.righe.contoContabile',
            'scrittura.fatture',
        ]);

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio      = $this->getEsercizioCorrente($condominio);

        $scritturaFatture = $pagamento->scrittura?->fatture?->map(fn ($f) => [
            'id'               => $f->id,
            'numero_documento' => $f->numero_documento,
            'data_documento'   => $f->data_documento?->format('d/m/Y'),
            'tipo_documento'   => $f->tipo_documento,
            'importo_allocato' => $f->pivot->importo_allocato,
            'tipo_allocazione' => $f->pivot->tipo?->value ?? $f->pivot->tipo,
        ]) ?? collect();

        return Inertia::render('gestionale/movimenti/pagamenti/PagamentoShow', [
            'condominio' => $condominio,
            'condomini'  => $listaCondomini,
            'esercizio'  => $esercizio,
            'pagamento'  => (new PagamentoFornitoreResource($pagamento))->resolve(),
            'scrittura'  => $pagamento->scrittura ? [
                'id'                 => $pagamento->scrittura->id,
                'numero_protocollo'  => $pagamento->scrittura->numero_protocollo,
                'causale'            => $pagamento->scrittura->causale,
                'tipo_movimento'     => $pagamento->scrittura->tipo_movimento?->value,
                'data_registrazione' => $pagamento->scrittura->data_registrazione?->format('d/m/Y'),
                'righe'              => $pagamento->scrittura->righe->map(fn ($r) => [
                    'id'        => $r->id,
                    'tipo_riga' => $r->tipo_riga,
                    'importo'   => $r->importo,
                    'note'      => $r->note,
                    'conto'     => $r->contoContabile ? [
                        'id'     => $r->contoContabile->id,
                        'codice' => $r->contoContabile->codice,
                        'nome'   => $r->contoContabile->nome,
                    ] : null,
                ]),
                'fatture' => $scritturaFatture,
            ] : null,
        ]);
    }

    /**
     * Generates and downloads the Supplier Payment Slip in PDF format.
     *
     * @param Condominio $condominio
     * @param PagamentoFornitore $pagamento
     * @param PdfService $pdfService
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function distinta(Condominio $condominio, PagamentoFornitore $pagamento, PdfService $pdfService)
    {
        abort_if($pagamento->condominio_id !== $condominio->id, 403, 'Unauthorized action.');
        
        $pagamento->load(['fornitore', 'contoCorrente', 'scrittura.fatture']);
        
        $data = [
            'condominio' => $condominio,
            'pagamento'  => $pagamento,
            'fornitore'  => $pagamento->fornitore,
            'scrittura'  => $pagamento->scrittura,
            'fatture'    => $pagamento->scrittura->fatture ?? collect(),
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.distinta', $data);

        $filename = sprintf('Distinta_PAG-%s_%s.pdf', 
            $pagamento->scrittura->numero_protocollo ?? $pagamento->id,
            $pagamento->data_pagamento?->format('Ymd') ?? date('Ymd')
        );

        return response()->streamDownload(function () use ($mpdf) {
            echo $mpdf->Output('', 'S');
        }, $filename, ['Content-Type' => 'application/pdf']);
    }
}

<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreFatturaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Immobile;
use App\Services\Gestionale\FatturaPassivaService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FatturaPassivaController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    public function __construct(private FatturaPassivaService $service) {}

    public function index(Request $request, Condominio $condominio)
    {
        $fatture = FatturaPassiva::where('condominio_id', $condominio->id)
            ->with(['fornitore', 'righe'])
            ->when($request->stato_pagamento, fn($q, $v) => $q->where('stato_pagamento', $v))
            ->when($request->stato_approvazione, fn($q, $v) => $q->where('stato_approvazione', $v))
            ->when($request->search, fn($q, $v) =>
                $q->where('numero_documento', 'like', "%{$v}%")
                  ->orWhereHas('fornitore', fn($qf) => $qf->where('ragione_sociale', 'like', "%{$v}%"))
            )
            ->orderByDesc('data_documento')
            ->paginate(20)
            ->withQueryString();
        
        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        $stats = [
            'totale_aperte'       => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_pagamento', 'aperta')->count(),
            'totale_sfori'        => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_approvazione', 'sforo_motivato')->count(),
            'importo_da_pagare'   => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_pagamento', 'aperta')->sum('netto_a_pagare'),
        ];

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterList', [
            'condominio' => $condominio,
            'fatture'    => $fatture,
            'stats'      => $stats,
            'esercizio'  => $esercizio,
            'condomini'  => $listaCondomini, 
            'filters'    => $request->only(['stato_pagamento', 'stato_approvazione', 'search']),
        ]);
    }

    public function create(Condominio $condominio)
    {
        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        // --- AUTOGENERAZIONE NUMERO PROTOCOLLO ---
        $annoInCorso = date('Y');
        $ultimoProtocollo = FatturaPassiva::where('condominio_id', $condominio->id)
            ->whereYear('created_at', $annoInCorso)
            ->whereNotNull('numero_protocollo')
            ->orderBy('id', 'desc')
            ->value('numero_protocollo');

        if ($ultimoProtocollo && preg_match('/-(\d+)$/', $ultimoProtocollo, $matches)) {
            $nextNum = str_pad((int)$matches[1] + 1, 4, '0', STR_PAD_LEFT);
            $protocolloSuggerito = "PR-{$annoInCorso}-{$nextNum}";
        } else {
            $protocolloSuggerito = "PR-{$annoInCorso}-0001";
        }
        // ------------------------------------------

        // --- ESTRAZIONE ULTIME SPESE E CALCOLO REALE BUDGET ---
        $ultimeSpese = collect();
        $spesePerConto = collect(); // La nostra mappa dei costi reali

        if ($esercizio) {
            $ultimeSpese = \Illuminate\Support\Facades\DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->select(
                    'righe_fattura.conto_id',
                    'fatture_passive.data_documento',
                    'fatture_passive.numero_documento',
                    'fatture_passive.is_pregresso',
                    'fornitori.ragione_sociale',
                    'righe_fattura.importo_imponibile',
                    'righe_fattura.importo_iva'
                )
                ->orderByDesc('fatture_passive.data_documento')
                ->get()
                ->groupBy('conto_id');

            // --- CALCOLO SPESA REALE (CONSUNTIVATO) DALLE FATTURE ---
            // Interroghiamo direttamente le righe delle fatture per la massima precisione
            $spesePerConto = \Illuminate\Support\Facades\DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.is_pregresso', false) // MAGIA: Ignoriamo i debiti pregressi!
                ->groupBy('righe_fattura.conto_id')
                ->selectRaw('righe_fattura.conto_id, SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale_spesa')
                ->pluck('totale_spesa', 'righe_fattura.conto_id');
        }

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterNew', [
            'condominio' => $condominio,
            'fornitori'  => Fornitore::all(),
            'esercizi'   => $condominio->esercizi()->where('stato', 'aperto')->get(),

            'esercizio'  => $esercizio,
            'condomini'  => $listaCondomini, 

            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(function ($gestione) {
                    return [
                        'id'   => $gestione->id,
                        'nome' => $gestione->nome,
                        'tipo' => $gestione->tipo,
                        'esercizio_ids' => $gestione->esercizi->pluck('id')->toArray(),
                    ];
                }),

            // --- CARICAMENTO CONTI CON BUDGET DINAMICO E STORICO ANTIDUPLICAZIONE ---
            'conti' => Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
                ->with('parent')
                ->whereDoesntHave('sottoconti')
                ->get()
                // Aggiungiamo $spesePerConto all'uso della closure
                ->map(function ($conto) use ($ultimeSpese, $spesePerConto) {
                    
                    $budgetApprovato = $conto->importo ?? 0; 
                    
                    // Ora la spesa attuale è reale: se non c'è, è 0
                    $spesaAttuale    = $spesePerConto->get($conto->id, 0); 
                    $residuo         = $budgetApprovato - $spesaAttuale;

                    $storicoRecente = [];
                    if ($ultimeSpese->has($conto->id)) {
                        $storicoRecente = $ultimeSpese->get($conto->id)->take(3)->map(function($spesa) {
                            return [
                                'data'         => \Carbon\Carbon::parse($spesa->data_documento)->format('d/m/Y'),
                                'fornitore'    => $spesa->ragione_sociale,
                                'documento'    => $spesa->numero_documento,
                                'is_pregresso' => (bool) $spesa->is_pregresso, 
                                'importo'      => $spesa->importo_imponibile + $spesa->importo_iva,
                            ];
                        })->values()->toArray();
                    }

                    return [
                        'id'               => $conto->id,
                        'nome'             => $conto->nome, 
                        'parent_nome'      => $conto->parent ? $conto->parent->nome : null, 
                        '_sort_key'        => $conto->parent ? $conto->parent->nome . ' ' . $conto->nome : $conto->nome,
                        'codice'           => null,
                        'residuo_budget'   => $residuo, // Questo finalmente scenderà!
                        'is_capiente'      => $residuo >= 0,
                        'ultimi_movimenti' => $storicoRecente 
                    ];
                })
                ->sortBy('_sort_key')
                ->values(),

            // --- CARICAMENTO CASSE E SALDO DINAMICO ---
            'banche' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
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
                        'saldo_attuale' => $saldoAttuale, 
                    ];
                }),

            'immobili' => Immobile::where('condominio_id', $condominio->id)
                ->where('attivo', true)
                ->select('id', 'interno', 'nome')
                ->orderBy('interno')
                ->get()
                ->map(function ($imm) {
                    return [
                        'id'    => $imm->id,
                        'label' => 'Int. ' . $imm->interno . ' — ' . $imm->nome, 
                    ];
                }),
        ]);
    }

    public function store(StoreFatturaRequest $request, Condominio $condominio)
    {
        try {
            $this->service->registraFattura(
                $request->validated(),
                $condominio->id,
                $request->file('file')
            );

            return redirect()
                ->route('admin.gestionale.fatture.index', $condominio->id) 
                ->with('success', 'Fattura registrata con successo.');

        } catch (ModelNotFoundException $e) {
            return back()->withErrors([
                'error' => 'Risorsa non trovata. Verifica fornitore, conto e gestione selezionati.',
            ]);

        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
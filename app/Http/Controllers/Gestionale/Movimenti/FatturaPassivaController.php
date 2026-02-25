<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreFatturaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
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
        // Cerchiamo l'ultimo protocollo dell'anno in corso per questo condominio
        $annoInCorso = date('Y');
        $ultimoProtocollo = FatturaPassiva::where('condominio_id', $condominio->id)
            ->whereYear('created_at', $annoInCorso)
            ->whereNotNull('numero_protocollo')
            ->orderBy('id', 'desc')
            ->value('numero_protocollo');

        // Formato desiderato: PR-2026-0001
        if ($ultimoProtocollo && preg_match('/-(\d+)$/', $ultimoProtocollo, $matches)) {
            $nextNum = str_pad((int)$matches[1] + 1, 4, '0', STR_PAD_LEFT);
            $protocolloSuggerito = "PR-{$annoInCorso}-{$nextNum}";
        } else {
            $protocolloSuggerito = "PR-{$annoInCorso}-0001";
        }
        // ------------------------------------------

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterNew', [
            'condominio' => $condominio,
            'fornitori'  => Fornitore::all(),
            'esercizi'   => $condominio->esercizi()->where('stato', 'aperto')->get(),

            'esercizio'  => $esercizio,
            'condomini'  => $listaCondomini, 

            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true) // Specifico la tabella per evitare ambiguità
                ->with('esercizi:id') // Carichiamo solo l'ID degli esercizi collegati per non appesantire
                ->get()
                ->map(function ($gestione) {
                    return [
                        'id'   => $gestione->id,
                        'nome' => $gestione->nome,
                        'tipo' => $gestione->tipo,
                        // Estraiamo un array semplice di ID: es. [1, 2]
                        'esercizio_ids' => $gestione->esercizi->pluck('id')->toArray(),
                    ];
                }),

            'conti' => Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
                ->get()
                ->map(function ($conto) {
                    $budgetApprovato = $conto->importo ?? 0; 
                    $spesaAttuale    = $conto->spesa_attuale ?? 0; 
                    $residuo         = $budgetApprovato - $spesaAttuale;

                    return [
                        'id'             => $conto->id,
                        'nome'           => $conto->nome,
                        'codice'         => null,
                        'residuo_budget' => $residuo,
                        'is_capiente'    => $residuo > 0,
                    ];
                }),

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
                        // PASSALA COME ID DEL CONTO CONTABILE, COSÌ IL SERVICE NON ESPLODE!
                        'id' => $cassa->conto_contabile_id, 
                        'cassa_id' => $cassa->id, // Lo teniamo per reference
                        'nome' => $cassa->nome,
                        'saldo_attuale' => $saldoAttuale, 
                    ];
                }),

            'immobili' => Immobile::where('condominio_id', $condominio->id)
                ->with('anagrafiche') 
                ->select('id', 'interno')
                ->get()
                ->map(fn($imm) => [
                    'id'    => $imm->id,
                    'label' => 'Int. ' . $imm->interno . ' - '. ($imm->anagrafiche->first()->nome_completo ?? $imm->anagrafiche->first()->nome ?? 'N/A'),
                ]),
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
<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreFatturaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Fornitore;
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

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterNew', [
            'condominio' => $condominio,
            'fornitori'  => Fornitore::all(),
            'esercizi'   => $condominio->esercizi()->where('stato', 'aperto')->get(),

            'esercizio'  => $esercizio,
            'condomini'  => $listaCondomini, 

            // FIX: Rimossa la colonna 'esercizio_id' inesistente
            'gestioni' => $condominio->gestioni()
                ->where('attiva', true)
                ->select('id', 'nome', 'tipo')
                ->get(),

            // Conti recuperati tramite i Piani dei Conti del condominio
            'conti' => Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
                ->get()
                ->map(function ($conto) {
                    // Dalla tua migration, usiamo il campo 'importo' come budget assegnato
                    $budgetApprovato = $conto->importo ?? 0; 
                    
                    // La spesa attuale la ricaverai poi dalle scritture contabili.
                    // Per ora usiamo 0 (o l'attributo se l'hai già creato) per non rompere nulla.
                    $spesaAttuale    = $conto->spesa_attuale ?? 0; 
                    
                    $residuo         = $budgetApprovato - $spesaAttuale;

                    return [
                        'id'             => $conto->id,
                        'nome'           => $conto->nome,
                        'codice'         => null, // Non c'è nella tua migration, evitiamo errori
                        'residuo_budget' => $residuo,
                        'is_capiente'    => $residuo > 0,
                    ];
                }),

            'banche' => ContoContabile::where('condominio_id', $condominio->id)
                ->where('categoria', 'liquidita')
                ->get(),

            // Immobili formattati per la select "Spesa Personale"
            'immobili' => Immobile::where('condominio_id', $condominio->id)
                ->with('anagrafiche') // <--- Modificato da anagrafichePrincipali ad anagrafiche
                ->select('id', 'interno')
                ->get()
                ->map(fn($imm) => [
                    'id'    => $imm->id,
                    'label' => 'Int. ' . $imm->interno . ' - '
                               // Usa nome_completo, o nome, oppure 'N/A' se non ci sono anagrafiche collegate
                               . ($imm->anagrafiche->first()->nome_completo ?? $imm->anagrafiche->first()->nome ?? 'N/A'),
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
                ->route('gestionale.fatture.index', $condominio->id) // Assicurati che il nome rotta sia corretto qui (es: gestionale.fatture.index)
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
<?php

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Enums\StatoPianoRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\CreatePianoContoRequest;
use App\Http\Requests\Gestionale\PianoConto\PianoContoIndexRequest;
use App\Http\Requests\Gestionale\PianoConto\UpdatePianoContoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiDeiConti\Conti\ContoResource;
use App\Http\Resources\Gestionale\PianiDeiConti\PianoDeiContiResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Tabella;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Services\Gestionale\BudgetCoverageService; 
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB; // <-- Aggiunto
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class PianoContiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public function index(PianoContoIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();

        $pianiDeiConti = $condominio->pianiDeiConti()
            ->whereHas('gestione.esercizi', function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            })
            ->with(['gestione.esercizi' => function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            }])
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiList', [
            'condominio'    => $condominio,
            'esercizio'     => $esercizio,
            'esercizi'      => $esercizi,
            'condomini'     => CondominioResource::collection($this->getCondomini()),
            'pianiDeiConti' => PianoDeiContiResource::collection($pianiDeiConti)->resolve(),
            'meta' => [
                'current_page' => $pianiDeiConti->currentPage(),
                'last_page'    => $pianiDeiConti->lastPage(),
                'per_page'     => $pianiDeiConti->perPage(),
                'total'        => $pianiDeiConti->total(),
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    public function create(Condominio $condominio, Esercizio $esercizio)
    {
        $condomini = $this->getCondomini();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->whereDoesntHave('pianoConto')
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        if ($gestioni->isEmpty()) {
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
            ])->with($this->flashWarning(__('gestionale.warning_all_gestioni_have_piano_conti')));
        }

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(CreatePianoContoRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {
            $data       = $request->validated();
            $pianoConto = PianoConto::create($data);

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoConto' => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_create_piano_conto')));

        } catch (\Throwable $e) {
            Log::error('Error creating piano conti', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_create_piano_conto')));
        }
    }

    public function show(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): Response
    {
        $conti = Conto::with([
            'sottoconti' => function ($query) {
                $query->with([
                    'tabelleMillesimali.tabella',
                    'tabelleMillesimali.ripartizioni' => fn($q) => $q->orderBy('soggetto'),
                    'pianiRate' 
                ]);
            },
            'tabelleMillesimali.tabella',
            'tabelleMillesimali.ripartizioni' => fn($q) => $q->orderBy('soggetto'),
            'pianiRate', 
        ])->where('piano_conto_id', $pianoConto->id)
        ->whereNull('parent_id')
        ->orderBy('nome')
        ->get();

        // --- INIZIO FIX SFORO BUDGET ---
        $rawFatturato = DB::table('righe_fattura')
            ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
            ->where('fatture_passive.esercizio_id', $esercizio->id)
            ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            ->select('righe_fattura.conto_id', DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale'))
            ->groupBy('righe_fattura.conto_id')
            ->get();

        $fatturatoMap = [];
        foreach ($rawFatturato as $row) {
            $fatturatoMap[(int)$row->conto_id] = (int)$row->totale;
        }

        // Iniettiamo chirurgicamente il Fabbisogno Reale nel modello
        // così la Resource e il Vue lavoreranno sui valori reali senza modifiche al front-end
        foreach ($conti as $conto) {
            $speso = $fatturatoMap[$conto->id] ?? 0;
            if ($speso > $conto->importo) {
                $conto->importo = $speso;
            }
            foreach ($conto->sottoconti as $sottoconto) {
                $spesoSotto = $fatturatoMap[$sottoconto->id] ?? 0;
                if ($spesoSotto > $sottoconto->importo) {
                    $sottoconto->importo = $spesoSotto;
                }
            }
        }
        // --- FINE FIX ---

        $coverageMap = [];
        $gestione = $pianoConto->gestione;

        if ($gestione) {
            $service = new BudgetCoverageService();
            // Passiamo la mappa al service per far agire l'algoritmo di push-down sui deficit reali
            $report  = $service->analyze($gestione, $fatturatoMap); 

            foreach ($report['items'] ?? [] as $item) {
                $coverageMap[(int) $item['id']] = (int) $item['pianificato'];
            }
        }

        ContoResource::$coverageMap = $coverageMap;

        $fornitori = Fornitore::attivi()
            ->orderBy('ragione_sociale')
            ->get(['id', 'ragione_sociale']);
        
        $totalePreventivo = (int) Conto::where('piano_conto_id', $pianoConto->id)->sum('importo');

        return Inertia::render('gestionale/pianiDeiConti/conti/ContiNew', [
            'condominio' => [
                'id'   => $condominio->id,
                'nome' => $condominio->nome,
            ],
            'esercizio' => [
                'id'   => $esercizio->id,
                'nome' => $esercizio->nome,
            ],
            'pianoConti' => new PianoDeiContiResource($pianoConto),
            'conti'      => ContoResource::collection($conti),
            'fornitori'  => $fornitori,
            'tabelle'    => Tabella::query()
                ->where('condominio_id', $condominio->id)
                ->orderBy('nome')
                ->get(['id', 'nome']),
            'totalePreventivo' => $totalePreventivo, 
        ]);
    }

    public function edit(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): Response
    {
        $pianoConto->loadMissing(['gestione']);

        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiEdit', [
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'gestioni'    => $gestioni,
            'pianoConti'  => new PianoDeiContiResource($pianoConto),
        ]);
    }

    public function update(UpdatePianoContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {
            $data = $request->validated();
            $pianoConto->update($data);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoConto' => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_update_piano_conto')));

        } catch (\Throwable $e) {
            Log::error('Error updating piano conti', [
                'condominio_id'  => $condominio->id,
                'piano_conto_id' => $pianoConto->id,
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoConto' => $pianoConto->id,
            ])->with($this->flashError(__('gestionale.error_update_piano_conto')));
        }
    }

    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {
            $pianoConto->delete();

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoConto' => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_delete_piano_conto')));

        } catch (\Throwable $e) {
            Log::error('Error deleting piano conto', [
                'condominio_id'  => $condominio->id,
                'esercizio_id'   => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoConto' => $pianoConto->id,
            ])->with($this->flashError(__('gestionale.error_delete_piano_conto')));
        }
    }
}
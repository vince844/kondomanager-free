<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoRate\PianoRateIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the resource.
     */
    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
         /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();
        
        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        // Tutti gli esercizi del condominio, ordinati
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);
        
         return Inertia::render('gestionale/pianiRate/PianiRateList', [
            'condominio'      => $condominio,
            'esercizio'       => $esercizio,
            'esercizi'        => $esercizi,
            'condomini'       => CondominioResource::collection($this->getCondomini()),
            'pianiRate'       => $pianiRate,
            'meta' => [
                'current_page' => $pianiRate->currentPage(),
                'last_page'    => $pianiRate->lastPage(),
                'per_page'     => $pianiRate->perPage(),
                'total'        => $pianiRate->total(),
            ],
            'filters' => $request->only(['nome']),
        ]);

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

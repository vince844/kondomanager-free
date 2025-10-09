<?php

namespace App\Http\Controllers\Gestionale\Conti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Conto\ContoIndexRequest;
use App\Http\Resources\Gestionale\PianiDeiConti\PianoDeiContiResource;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PianoContiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Display a listing of the resource.
     */
    public function index(ContoIndexRequest $request, Condominio $condominio): Response
    {
         /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Get a list of all the esercizi create to show in the datatable
        $PianiDeiConti = $condominio->pianiDeiConti()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        // Get a list of all the registered condomini this is important to populate dropdown condomini in the dropdown breadcrumb
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);
            
        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiList', [
            'condominio'      => $condominio,
            'esercizio'       => $esercizio,
            'condomini'       => $condomini,
            'pianiDeiConti'   => PianoDeiContiResource::collection($PianiDeiConti)->resolve(),
            'meta'       => [
                'current_page' => $PianiDeiConti->currentPage(),
                'last_page'    => $PianiDeiConti->lastPage(),
                'per_page'     => $PianiDeiConti->perPage(),
                'total'        => $PianiDeiConti->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        $gestioni = Gestione::with(['esercizi' => function($query) use ($condominio) {
                $query->where('condominio_id', $condominio->id)
                    ->where('stato', 'aperto');
            }])
            ->whereHas('esercizi', function($query) use ($condominio) {
                $query->where('condominio_id', $condominio->id)
                    ->where('stato', 'aperto');
            })
            ->get();

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni
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

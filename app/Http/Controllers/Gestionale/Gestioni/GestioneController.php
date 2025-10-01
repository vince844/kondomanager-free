<?php

namespace App\Http\Controllers\Gestionale\Gestioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Gestione\GestioneIndexRequest;
use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GestioneController extends Controller
{
     use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the resource.
     */
    public function index(GestioneIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Recupero esercizio aperto
        $esercizio = $condominio->esercizi()
            ->where('stato', 'aperto')
            ->firstOrFail();

        $gestioni = $esercizio->gestioni()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));
        
        $condomini = $this->getCondomini();
            
        return Inertia::render('gestionale/gestioni/GestioniList', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'gestioni'   => GestioneResource::collection($gestioni)->resolve(),
            'meta'       => [
                'current_page' => $gestioni->currentPage(),
                'last_page'    => $gestioni->lastPage(),
                'per_page'     => $gestioni->perPage(),
                'total'        => $gestioni->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
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
    public function show(Gestione $gestione)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Gestione $gestione)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Gestione $gestione)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Gestione $gestione)
    {
        //
    }
}

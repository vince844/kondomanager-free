<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\CreateAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Models\Anagrafica;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AnagraficaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('anagrafiche/AnagraficheList', [
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('anagrafiche/AnagraficheNew');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateAnagraficaRequest $request): RedirectResponse
    {

        Anagrafica::create($request->validated());

        return to_route('anagrafiche.index')->with(['message' => [ 'type'    => 'success',
                                                                   'message' => "La nuova anagrafica é stata creata con successo!"]]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Anagrafica $anagrafica)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Anagrafica $anagrafica)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Anagrafica $anagrafica)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Anagrafica $anagrafiche)
    {
        $anagrafiche->delete();

        return back()->with(['message' => [ 'type'    => 'success',
                                            'message' => "L'anagrafica è stata eliminata con successo"]]);
    }
}

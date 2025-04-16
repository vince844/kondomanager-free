<?php

namespace App\Http\Controllers\Condomini;

use App\Http\Controllers\Controller;
use App\Http\Requests\Condominio\CreateCondominioRequest;
use App\Http\Requests\Condominio\UpdateCondominioRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;

class CondominioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('view', Condominio::class);

        return Inertia::render('buildings/BuildingsList', [
            'buildings' => CondominioResource::collection(Condominio::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('create', Condominio::class);

        return Inertia::render('buildings/BuildingsNew');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateCondominioRequest $request): RedirectResponse
    {
        Gate::authorize('create', Condominio::class);

        Condominio::create($request->validated());

        return to_route('condomini.index')->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "Il nuovo condominio è stato creato con successo!"
            ]
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Condominio $condominio)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Condominio $condomini)
    {
        Gate::authorize('update', Condominio::class);

        return Inertia::render('buildings/BuildingsEdit', [
            'building' => new CondominioResource($condomini),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCondominioRequest $request, Condominio $condomini): RedirectResponse
    {
        Gate::authorize('update', Condominio::class);

        $condominio = Condominio::find($condomini->id);
        $condominio->update($request->validated());

        return to_route('condomini.index')->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "Il profilo del condominio è stato modificato con successo"
            ]
        ]);
    }

    public function options()
    {
        return CondominioOptionsResource::collection(Condominio::all());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condomini)
    {
        Gate::authorize('delete', Condominio::class);

        $condomini->delete();

        return back()->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "Il codominio è stato eliminato con successo"
            ]
        ]);
    }
}

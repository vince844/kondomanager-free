<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Building\CreateBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Models\Building;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuildingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('buildings/BuildingsList', [
            'buildings' => BuildingResource::collection(Building::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('buildings/BuildingsNew');

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateBuildingRequest $request): RedirectResponse
    {

        Building::create($request->validated());

        return to_route('condomini.index')->with(['message' => [ 'type'    => 'success',
                                                                 'message' => "Il nuovo condominio è stato creato con successo!"]]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Building $building)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Building $building)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Building $building)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Building $condomini)
    {
        $condomini->delete();

        return back()->with(['message' => [ 'type'    => 'success',
                                            'message' => "Il codominio è stato eliminato con successo"]]);
    }
}

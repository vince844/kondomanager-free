<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\CreateUserAnagraficaRequest;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Invito;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class UserAnagraficaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {  
        return Inertia::render('anagrafiche/UserAnagraficaNew');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateUserAnagraficaRequest $request)
    {
        $validated = $request->validated(); 

        // Get the logged user
        $user = Auth::user();

        //Get buildings codes from invito
        $buildingCodes = Invito::where('email', $user->email)->first();

        // Get building IDs
        $buildingIds = Condominio::whereIn('codice_identificativo', $buildingCodes->building_codes)->pluck('id');

        // Create the anagrafica and attach the user id
        $anagrafica = Anagrafica::create([
            'user_id' => $user->id, 
            ...$validated           
        ]);

        // Associate buildings to the anagrafica
        $anagrafica->condomini()->attach($buildingIds);
        
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
    public function destroy(Anagrafica $anagrafica)
    {
        //
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Anagrafiche\Utenti;

use App\Services\RegistroModifiche\RegistroModifiche;
use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\CreateUserAnagraficaRequest;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Invito;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnagraficaController extends Controller
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

        // I condomìni vengono solo da un invito accettato con il suo link. Fino alla 1.11.0-beta.44
        // bastava un invito qualunque con la stessa email, anche scaduto o mai accettato: chi si
        // registrava per primo con l'email di un invitato se li ritrovava, una volta verificata
        // l'email (Fase 1-bis della 1.11.0-beta.45, rilievo X2).
        $buildingCodes = Invito::where('email', $user->email)->whereNotNull('accepted_at')->first();

        // L'anagrafica e i condomini dell'invito in una transazione: una riga sola «Creata», con i condomini, nel registro di ognuno (decisione 554).
        DB::transaction(function () use ($user, $validated, $buildingCodes) {
            $anagrafica = Anagrafica::create([
                'user_id' => $user->id,
                ...$validated,
            ]);

            // Con un invito accettato, i suoi condomini; senza (l'utente creato a mano), l'anagrafica nasce senza condomini.
            if (! empty($buildingCodes)) {
                $buildingIds = Condominio::whereIn('codice_identificativo', $buildingCodes->building_codes)->pluck('id');
                RegistroModifiche::legame($anagrafica, 'condomini', 'Condomini', fn () => $anagrafica->condomini()->attach($buildingIds));
            }
        });

        return redirect()->intended(RedirectHelper::userHomeRoute());
        
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

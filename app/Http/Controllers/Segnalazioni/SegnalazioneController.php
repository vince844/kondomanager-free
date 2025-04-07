<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Models\Segnalazione;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SegnalazioneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
       
        return Inertia::render('segnalazioni/SegnalazioniList', [
            'segnalazioni' => Segnalazione::all()
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
    public function show(Segnalazione $segnalazione)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Segnalazione $segnalazione)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Segnalazione $segnalazione)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Segnalazione $segnalazione)
    {
        //
    }
}

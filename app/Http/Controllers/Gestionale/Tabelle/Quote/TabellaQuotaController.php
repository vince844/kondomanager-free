<?php

namespace App\Http\Controllers\Gestionale\Tabelle\Quote;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\QuotaTabella;
use App\Models\Tabella;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TabellaQuotaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio, Tabella $tabella): Response
    {

        $millesimi = $tabella->quote()->with('immobile')->get();

        return Inertia::render('gestionale/tabelle/quote/QuoteList', [
            'condominio' => $condominio,
            'tabella'    => $tabella,
            'millesimi'  => $millesimi
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

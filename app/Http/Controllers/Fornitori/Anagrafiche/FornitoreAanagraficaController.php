<?php

namespace App\Http\Controllers\Fornitori\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Models\Fornitore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FornitoreAanagraficaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Fornitore $fornitore): Response
    {
        $fornitore->loadMissing(['referenti' => function($query) use ($fornitore) {
            $query->where('fornitore_id', $fornitore->id);
        }]);

        return Inertia::render('fornitori/anagrafiche/AnagraficheList', [
            'fornitore' => $fornitore,
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

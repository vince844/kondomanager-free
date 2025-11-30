<?php

namespace App\Http\Controllers\Fornitori;

use App\Http\Controllers\Controller;
use App\Models\Fornitore;
use Illuminate\Http\Request;

class FornitoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        dd('FornitoreController index');
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
    public function show(Fornitore $fornitore)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Fornitore $fornitore)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Fornitore $fornitore)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fornitore $fornitore)
    {
        //
    }
}

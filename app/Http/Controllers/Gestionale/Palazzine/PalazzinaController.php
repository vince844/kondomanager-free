<?php

namespace App\Http\Controllers\Gestionale\Palazzine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Palazzina\CreatePalazzinaRequest;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class PalazzinaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio): Response
    {

        $palazzine = $condominio->palazzine()->paginate(10);

        return Inertia::render('gestionale/palazzine/PalazzineList', [
            'condominio' => $condominio,
            'palazzine'  => PalazzinaResource::collection($palazzine)->resolve(), 
            'meta' => [
                'current_page' => $palazzine->currentPage(),
                'last_page'    => $palazzine->lastPage(),
                'per_page'     => $palazzine->perPage(),
                'total'        => $palazzine->total(),
            ]
       
        ]);
    
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        return Inertia::render('gestionale/palazzine/PalazzineNew', [
            'condominio' => $condominio,
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreatePalazzinaRequest $request, Condominio $condominio): RedirectResponse
    {
        $data = $request->validated();

        Palazzina::create($data);

        return to_route('admin.gestionale.palazzine.index', $condominio)->with(
            $this->flashSuccess(__('gestionale.success_create_palazzina'))
        );

    }

    /**
     * Display the specified resource.
     */
    public function show(Palazzina $palazzina)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Palazzina $palazzina)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Palazzina $palazzina)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Palazzina $palazzina): RedirectResponse
    {
      
        $palazzina->delete();

        return to_route('admin.gestionale.palazzine.index', $condominio)->with(
            $this->flashSuccess(__('gestionale.success_delete_palazzina'))
        );
    }

}

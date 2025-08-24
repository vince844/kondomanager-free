<?php

namespace App\Http\Controllers\Gestionale\Immobili;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\CreateImmobileRequest;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class ImmobileController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio): Response
    {
        $immobili = $condominio->immobili()->paginate(10);

        return Inertia::render('gestionale/immobili/ImmobiliList', [

            'condominio' => $condominio,
            'immobili'  => ImmobileResource::collection($immobili)->resolve(), 
            'meta' => [
                'current_page' => $immobili->currentPage(),
                'last_page'    => $immobili->lastPage(),
                'per_page'     => $immobili->perPage(),
                'total'        => $immobili->total(),
            ]

        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        // Eager load relationships
        $condominio->load(['palazzine', 'scale']);
    
        return Inertia::render('gestionale/immobili/ImmobiliNew', [
            'condominio' => $condominio,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
            'tipologie'  => TipologiaImmobile::all(),
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateImmobileRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            $data = $request->validated();

            Immobile::create($data);

            return to_route('admin.gestionale.immobili.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_create_immobile'))
            );

        } catch (\Throwable $e) {
            Log::error('Error creating immobile', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.index', $condominio)->with(
                $this->flashError(__('gestionale.error_create_immobile'))
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Immobile $immobile)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Immobile $immobile)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Immobile $immobile)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        try {

            $immobile->delete();

            return to_route('admin.gestionale.immobili.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_delete_immobile'))
            );

        } catch (\Throwable $e) {
            Log::error('Error deleting immobile', [
                'immobile_id'   => $immobile->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.index', $condominio)->with(
                $this->flashError(__('gestionale.error_delete_immobile'))
            );
        }
    }
}

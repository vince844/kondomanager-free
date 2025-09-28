<?php

namespace App\Http\Controllers\Gestionale\Esercizi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Esercizio\CreateEsercizioRequest;
use App\Http\Requests\Gestionale\Esercizio\EsercizioIndexRequest;
use App\Http\Requests\Gestionale\Esercizio\UpdateEsercizioRequest;
use App\Http\Resources\Gestionale\Esercizi\EsercizioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class EsercizioController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the resource.
     */
    public function index(EsercizioIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $esercizi = $condominio->esercizi()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));
        
        $condomini = $this->getCondomini();
            
        return Inertia::render('gestionale/esercizi/EserciziList', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'esercizi'   => EsercizioResource::collection($esercizi)->resolve(),
            'meta'       => [
                'current_page' => $esercizi->currentPage(),
                'last_page'    => $esercizi->lastPage(),
                'per_page'     => $esercizi->perPage(),
                'total'        => $esercizi->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/esercizi/EserciziNew', [
            'condominio' => $condominio,
             'condomini' => $condomini,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateEsercizioRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            Esercizio::create($data);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_create_esercizio')));

        } catch (\Throwable $e) {
            Log::error('Error creating esercizio', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_esercizio')));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Esercizio $esercizio)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Condominio $condominio, Esercizio $esercizio): Response
    {
        return Inertia::render('gestionale/esercizi/EserciziEdit', [
            'condominio' => $condominio,
            'esercizio'  => new EsercizioResource($esercizio),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEsercizioRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {

            $data = $request->validated();
            $esercizio->update($data);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_update_esercizio')));

        } catch (\Throwable $e) {

            Log::error('Error updating esercizio', [
                'esercizio_id'  => $esercizio->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_update_esercizio')));
            
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {
            $esercizio->delete();

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_esercizio')));
                
        } catch (\Throwable $e) {
            Log::error('Error deleting esercizio', [
                'esercizio_id'   => $esercizio->id,
                'condominio_id'  => $condominio->id,
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_esercizio')));
        }
    }
}

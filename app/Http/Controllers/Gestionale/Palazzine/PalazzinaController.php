<?php

namespace App\Http\Controllers\Gestionale\Palazzine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Palazzina\CreatePalazzinaRequest;
use App\Http\Requests\Gestionale\Palazzina\PalazzinaIndexRequest;
use App\Http\Requests\Gestionale\Palazzina\UpdatePalazzinaRequest;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing Palazzine inside a Condominio.
 * 
 * Handles CRUD operations, validation, and error handling
 * with flash messages and Inertia responses.
 */

class PalazzinaController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the palazzine with optional name filter.
     *
     * @param \App\Http\Requests\Gestionale\Palazzina\PalazzinaIndexRequest $request The incoming validated request containing filter parameters
     * @param \App\Models\Condominio $condominio
     * @return \Inertia\Response
     */
    public function index(PalazzinaIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $palazzine = $condominio->palazzine()
            ->when($validated['name'] ?? false, function ($query, $name) {
                $query->where('name', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->appends($request->all());
        
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/palazzine/PalazzineList', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'palazzine'  => PalazzinaResource::collection($palazzine)->resolve(), 
            'meta' => [
                'current_page' => $palazzine->currentPage(),
                'last_page'    => $palazzine->lastPage(),
                'per_page'     => $palazzine->perPage(),
                'total'        => $palazzine->total(),
            ],
            'filters' => $request->only(['name']), 
        ]);
    }

    /**
     * Show the form for creating a new palazzina in a condominio.
     *
     * @param  \App\Models\Condominio  $condominio
     * @return \Inertia\Response
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/palazzine/PalazzineNew', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
        ]); 
    }

    /**
     * Store a newly created palazzina in storage.
     *
     * @param  \App\Http\Requests\Gestionale\Palazzina\CreatePalazzinaRequest  $request
     * @param  \App\Models\Condominio  $condominio
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(CreatePalazzinaRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            $data = $request->validated();

            Palazzina::create($data);

            return to_route('admin.gestionale.palazzine.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_create_palazzina'))
            );

        } catch (\Throwable $e) {
            Log::error('Error creating palazzina', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.palazzine.index', $condominio)->with(
                $this->flashError(__('gestionale.error_create_palazzina'))
            );
        }
    }

    /**
     * Display the specified palazzina.
     *
     * @param  \App\Models\Palazzina  $palazzina
     * @return void
     */
    public function show(Palazzina $palazzina)
    {
        // Not implemented. Could return a detailed view.
    }

    /**
     * Show the form for editing the specified palazzina.
     *
     * @param  \App\Models\Condominio  $condominio
     * @param  \App\Models\Palazzina   $palazzina
     * @return \Inertia\Response
     */
    public function edit(Condominio $condominio, Palazzina $palazzina): Response
    {
        return Inertia::render('gestionale/palazzine/PalazzineEdit', [
            'condominio' => $condominio,
            'palazzina'  => new PalazzinaResource($palazzina),
        ]); 
    }

    /**
     * Update the specified palazzina in storage.
     *
     * @param  \App\Http\Requests\Gestionale\Palazzina\UpdatePalazzinaRequest  $request
     * @param  \App\Models\Condominio  $condominio
     * @param  \App\Models\Palazzina   $palazzina
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdatePalazzinaRequest $request, Condominio $condominio, Palazzina $palazzina): RedirectResponse
    {
        try {
            $data = $request->validated();

            $palazzina->update($data);

            return to_route('admin.gestionale.palazzine.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_update_palazzina'))
            );

        } catch (\Throwable $e) {
            Log::error('Error updating palazzina', [
                'palazzina_id'  => $palazzina->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.palazzine.index', $condominio)->with(
                $this->flashError(__('gestionale.error_update_palazzina'))
            );
        }
    }

    /**
     * Remove the specified palazzina from storage.
     *
     * @param  \App\Models\Condominio  $condominio
     * @param  \App\Models\Palazzina   $palazzina
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Condominio $condominio, Palazzina $palazzina): RedirectResponse
    {
        try {
            $palazzina->delete();

            return to_route('admin.gestionale.palazzine.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_delete_palazzina'))
            );

        } catch (\Throwable $e) {
            Log::error('Error deleting palazzina', [
                'palazzina_id'  => $palazzina->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.palazzine.index', $condominio)->with(
                $this->flashError(__('gestionale.error_delete_palazzina'))
            );
        }
    }
}

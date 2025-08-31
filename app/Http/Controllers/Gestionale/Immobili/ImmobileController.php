<?php

namespace App\Http\Controllers\Gestionale\Immobili;

use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\CreateImmobileRequest;
use App\Http\Requests\Gestionale\Immobile\ImmobileIndexRequest;
use App\Http\Requests\Gestionale\Immobile\UpdateImmobileRequest;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Http\Resources\Gestionale\Immobili\TipologiaImmobileResource;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing Immobili (properties) within a Condominio.
 *
 * Handles all CRUD operations, including:
 *  - Listing immobili
 *  - Creating new immobile
 *  - Viewing immobile details
 *  - Editing immobile
 *  - Updating immobile
 *  - Deleting immobile
 *
 * Integrates flash messages via the HandleFlashMessages trait
 * and uses RedirectHelper to manage "back or fallback" redirects cleanly.
 */
class ImmobileController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a paginated listing of immobili for a specific condominio.
     *
     * @param  Condominio  $condominio
     * @return Response
     */
    public function index(ImmobileIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $immobili = $condominio
            ->immobili()
            ->with(['palazzina', 'scala', 'tipologiaImmobile'])
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate(config('pagination.default_per_page'));
            

        return Inertia::render('gestionale/immobili/ImmobiliList', [
            'condominio' => $condominio,
            'immobili'   => ImmobileResource::collection($immobili)->resolve(),
            'meta'       => [
                'current_page' => $immobili->currentPage(),
                'last_page'    => $immobili->lastPage(),
                'per_page'     => $immobili->perPage(),
                'total'        => $immobili->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new immobile.
     *
     * @param  Condominio  $condominio
     * @return Response
     */
    public function create(Condominio $condominio): Response
    {
        $condominio->load(['palazzine', 'scale']);

        return Inertia::render('gestionale/immobili/ImmobiliNew', [
            'condominio' => $condominio,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
            'tipologie'  => TipologiaImmobile::all(),
        ]);
    }

    /**
     * Store a newly created immobile in storage.
     *
     * @param  CreateImmobileRequest  $request
     * @param  Condominio             $condominio
     * @return RedirectResponse
     * @throws \Throwable If an error occurs during creation
     */
    public function store(CreateImmobileRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            Immobile::create($data);

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_create_immobile')));

        } catch (\Throwable $e) {
            Log::error('Error creating immobile', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_immobile')));
        }
    }

    /**
     * Display a specific immobile.
     *
     * @param  Condominio  $condominio
     * @param  Immobile    $immobile
     * @return Response
     */
    public function show(Condominio $condominio, Immobile $immobile): Response
    {
        $immobile->loadMissing(['palazzina', 'scala', 'tipologiaImmobile']);

        return Inertia::render('gestionale/immobili/ImmobiliView', [
            'condominio' => $condominio,
            'immobile'   => new ImmobileResource($immobile),
        ]);
    }

    /**
     * Show the form for editing a specific immobile.
     *
     * Uses RedirectHelper::rememberUrl() to store the previous URL
     * for redirecting after update.
     *
     * @param  Condominio  $condominio
     * @param  Immobile    $immobile
     * @return Response
     */
    public function edit(Condominio $condominio, Immobile $immobile): Response
    {
        $immobile->loadMissing(['palazzina', 'scala', 'tipologiaImmobile']);

        RedirectHelper::rememberUrl();

        return Inertia::render('gestionale/immobili/ImmobiliEdit', [
            'condominio' => $condominio,
            'immobile'   => new ImmobileResource($immobile),
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
            'tipologie'  => TipologiaImmobileResource::collection(TipologiaImmobile::all()),
        ]);
    }

    /**
     * Update a specific immobile in storage.
     *
     * Redirects to the intended URL or a fallback route.
     *
     * @param  UpdateImmobileRequest  $request
     * @param  Condominio             $condominio
     * @param  Immobile               $immobile
     * @return RedirectResponse
     * @throws \Throwable If an error occurs during update
     */
    public function update(UpdateImmobileRequest $request, Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        try {
            $data = $request->validated();
            $immobile->update($data);

            return RedirectHelper::backOr(
                route('admin.gestionale.immobili.index', $condominio),
                $this->flashSuccess(__('gestionale.success_update_immobile'))
            );
        } catch (\Throwable $e) {
            Log::error('Error updating immobile', [
                'immobile_id'   => $immobile->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return RedirectHelper::backOr(
                route('admin.gestionale.immobili.index', $condominio),
                $this->flashError(__('gestionale.error_update_immobile'))
            );
        }
    }

    /**
     * Remove a specific immobile from storage.
     *
     * @param  Condominio  $condominio
     * @param  Immobile    $immobile
     * @return RedirectResponse
     * @throws \Throwable If an error occurs during deletion
     */
    public function destroy(Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        try {
            $immobile->delete();

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_immobile')));
        } catch (\Throwable $e) {
            Log::error('Error deleting immobile', [
                'immobile_id'   => $immobile->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_immobile')));
        }
    }
}

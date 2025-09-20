<?php

namespace App\Http\Controllers\Gestionale\Scale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Scala\CreateScalaRequest;
use App\Http\Requests\Gestionale\Scala\ScalaIndexRequest;
use App\Http\Requests\Gestionale\Scala\UpdateScalaRequest;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use App\Models\Condominio;
use App\Models\Scala;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller responsible for managing condominium staircases (Scale).
 *
 * Provides CRUD operations:
 * - List scale within a condominium
 * - Create a new scala
 * - Edit an existing scala
 * - Delete a scala
 */
class ScalaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a paginated listing of scale for a given condominium.
     *
     * @param  \App\Http\Requests\Gestionale\Scala\ScalaIndexRequest  $request
     * @param  \App\Models\Condominio  $condominio
     * @return \Inertia\Response
     */
    public function index(ScalaIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $scale = $condominio->scale()
            ->when($validated['name'] ?? false, fn ($query, $name) =>
                $query->where('name', 'like', "%{$name}%")
            )
            ->with(['palazzina']) 
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->appends($request->all());

        return Inertia::render('gestionale/scale/ScaleList', [
            'condominio' => $condominio,
            'scale'      => ScalaResource::collection($scale)->resolve(),
            'meta'       => [
                'current_page' => $scale->currentPage(),
                'last_page'    => $scale->lastPage(),
                'per_page'     => $scale->perPage(),
                'total'        => $scale->total(),
            ],
            'filters'    => $request->only(['name']),
        ]);
    }

    /**
     * Show the form for creating a new scala.
     *
     * @param  \App\Models\Condominio  $condominio
     * @return \Inertia\Response
     */
    public function create(Condominio $condominio): Response
    {
        // Eager load palazzine
        $condominio->load('palazzine');
        
        return Inertia::render('gestionale/scale/ScaleNew', [
            'condominio' => $condominio,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
        ]);
    }

    /**
     * Store a newly created scala in the database.
     *
     * @param  \App\Http\Requests\Gestionale\Scala\CreateScalaRequest  $request
     * @param  \App\Models\Condominio  $condominio
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(CreateScalaRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            $data = $request->validated();
            Scala::create($data);

            return to_route('admin.gestionale.scale.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_create_scala'))
            );
        } catch (\Throwable $e) {
            Log::error('Error creating scala', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.scale.index', $condominio)->with(
                $this->flashError(__('gestionale.error_create_scala'))
            );
        }
    }

    /**
     * Display a specific scala.
     *
     * @param  \App\Models\Scala  $scala
     * @return void
     */
    public function show(Scala $scala)
    {
        // TODO: implement details page if needed
    }

    /**
     * Show the form for editing an existing scala.
     *
     * @param  \App\Models\Condominio  $condominio
     * @param  \App\Models\Scala  $scala
     * @return \Inertia\Response
     */
    public function edit(Condominio $condominio, Scala $scala): Response
    {
        $scala->loadMissing(['palazzina']);

        return Inertia::render('gestionale/scale/ScaleEdit', [
            'condominio' => $condominio,
            'scala'      => new ScalaResource($scala),
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
        ]);
    }

    /**
     * Update the specified scala in the database.
     *
     * @param  \App\Http\Requests\Gestionale\Scala\UpdateScalaRequest  $request
     * @param  \App\Models\Condominio  $condominio
     * @param  \App\Models\Scala  $scala
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateScalaRequest $request, Condominio $condominio, Scala $scala): RedirectResponse
    {
        try {

            $data = $request->validated();
            $scala->update($data);

            return to_route('admin.gestionale.scale.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_update_scala'))
            );

        } catch (\Throwable $e) {

            Log::error('Error updating scala', [
                'scala_id'      => $scala->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.scale.index', $condominio)->with(
                $this->flashError(__('gestionale.error_update_scala'))
            );
            
        }
    }

    /**
     * Remove the specified scala from the database.
     *
     * @param  \App\Models\Condominio  $condominio
     * @param  \App\Models\Scala  $scala
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Condominio $condominio, Scala $scala): RedirectResponse
    {
        try {
            $scala->delete();

            return to_route('admin.gestionale.scale.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_delete_scala'))
            );
        } catch (\Throwable $e) {
            Log::error('Error deleting scala', [
                'scala_id'      => $scala->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.scale.index', $condominio)->with(
                $this->flashError(__('gestionale.error_delete_scala'))
            );
        }
    }
}

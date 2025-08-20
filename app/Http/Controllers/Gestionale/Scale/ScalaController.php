<?php

namespace App\Http\Controllers\Gestionale\Scale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Scala\CreateScalaRequest;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use App\Models\Condominio;
use App\Models\Scala;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class ScalaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio)
    {
        $scale = $condominio->scale()->paginate(10);

        return Inertia::render('gestionale/scale/ScaleList', [
            'condominio' => $condominio,
            'scale'  => ScalaResource::collection($scale)->resolve(), 
            'meta' => [
                'current_page' => $scale->currentPage(),
                'last_page'    => $scale->lastPage(),
                'per_page'     => $scale->perPage(),
                'total'        => $scale->total(),
            ]
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        return Inertia::render('gestionale/scale/ScaleNew', [
            'condominio' => $condominio,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine)->resolve(), 
        ]); 
    }

    /**
     * Store a newly created resource in storage.
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
     * Display the specified resource.
     */
    public function show(Scala $scala)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Scala $scala)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Scala $scala)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Scala $scala)
    {
        //
    }
}

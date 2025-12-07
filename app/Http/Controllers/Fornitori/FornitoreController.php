<?php

namespace App\Http\Controllers\Fornitori;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\CreateFornitoreRequest;
use App\Http\Requests\Fornitore\FornitoreIndexRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Fornitore\Categorie\CategoriaFornitoreResource;
use App\Http\Resources\Fornitore\FornitoreResource;
use App\Models\Anagrafica;
use App\Models\CategoriaFornitore;
use App\Models\Fornitore;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FornitoreController extends Controller
{
    use HandleFlashMessages;
    
    /**
     * Display a listing of the resource.
     */
    public function index(FornitoreIndexRequest $request): Response
    {
        $validated = $request->validated();

        $fornitori = Fornitore::with(['referenti:id,nome'])
            ->when($validated['ragione_sociale'] ?? false, function ($query, $ragioneSociale) {
                $query->where('ragione_sociale', 'like', "%{$ragioneSociale}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->withQueryString();
    
        return Inertia::render('fornitori/FornitoriList', [
            'fornitori' => FornitoreResource::collection($fornitori)->resolve(),
            'meta' => [
                'current_page' => $fornitori->currentPage(),
                'last_page'    => $fornitori->lastPage(),
                'per_page'     => $fornitori->perPage(),
                'total'        => $fornitori->total(),
            ],
            'filters' => $request->only(['ragione_sociale']) 
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('fornitori/FornitoriNew', [
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
            'categorie'   => CategoriaFornitoreResource::collection(CategoriaFornitore::all()),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateFornitoreRequest $request, Fornitore $fornitore)
    {

        $data = $request->validated();

        try {

            DB::beginTransaction();

            $fornitore = Fornitore::create($data);

            $fornitore->referenti()->attach($data['anagrafica_id']);

            DB::commit();

            return to_route('admin.fornitori.index')->with(
                $this->flashSuccess(__('fornitori.success_create_fornitore'))
            );

        } catch (\Throwable $e) {

            DB::rollback();
            
            Log::error('Error creating fornitore', [
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.fornitori.index')->with(
                $this->flashError(__('fornitori.error_create_fornitore'))
            );

        }
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

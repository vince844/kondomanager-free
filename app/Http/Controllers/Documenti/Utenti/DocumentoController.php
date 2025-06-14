<?php

namespace App\Http\Controllers\Documenti\Utenti;

use App\Http\Controllers\Controller;
use App\Models\Documento;
use App\Services\DocumentoService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DocumentoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\DocumentoService 
     */
    public function __construct(
        private DocumentoService $documentoService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function show(Documento $documento)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Documento $documento)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Documento $documento)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Documento $documento)
    {
        //
    }

    /**
     * Download the specified document file.
     *
     * @param  \App\Models\Documento  $documento
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\RedirectResponse
     */
    public function download(Documento $documento)
    {

        try {
            
            if (!Storage::exists($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found'))
                );
            }

            return Storage::download($documento->path, $documento->name);

        } catch (\Exception $e) {

            Log::error('Error downloading documento archivio', [
                'document_id' => $documento->id,
                'message'     => $e->getMessage(),
            ]);

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_downloading_document'))
            );

        }
    }
}

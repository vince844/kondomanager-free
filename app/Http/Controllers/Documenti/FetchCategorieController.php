<?php

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Models\CategoriaDocumento;
use Illuminate\Http\JsonResponse;

class FetchCategorieController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        
        $categorie = CategoriaDocumento::select('id', 'name')->orderBy('name')->get();

        return response()->json($categorie);
    }
}

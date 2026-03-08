<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Models\CategoriaEvento;
use Illuminate\Http\JsonResponse;

class FetchCategorieController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        $categorie = CategoriaEvento::query()->orderBy('name')->get();

        return response()->json(CategoriaEventoResource::collection($categorie));
    }
}

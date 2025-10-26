<?php

namespace App\Http\Controllers\Gestionale\Tabelle;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class FetchTabelleController extends Controller
{
    /**
     * Retrieve all millesimal tables associated with a condominium.
     * 
     * This endpoint returns a list of millesimal tables (id and name)
     * associated with the specified condominium, ordered by name.
     * 
     * @param Condominio $condominio The condominium to retrieve tables for
     * 
     * @return JsonResponse
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \Exception
     * 
     */
    public function __invoke(Condominio $condominio): JsonResponse
    {
        try {

            $tabelle = $condominio->tabelle()
                ->select('id', 'nome')
                ->orderBy('nome')
                ->get();

            return response()->json($tabelle);

        } catch (ModelNotFoundException $e) {

            Log::warning('Condominium not found in FetchTabelleController', [
                'condominium_id' => $condominium->id ?? 'null',
            ]);

            return response()->json([
                'error'   => 'Condominio non trovato',
                'message' => 'Il condominio specificato non esiste'
            ], 404);

        } catch (\Exception $e) {

            Log::error('Error in FetchTabelleController: ' . $e->getMessage(), [
                'condominium_id' => $condominium->id ?? 'null',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error'   => 'Errore interno del server',
                'message' => 'Si è verificato un errore durante il recupero delle tabelle'
            ], 500);
        }
    }
}
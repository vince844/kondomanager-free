<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FetchCapitoliContiController extends Controller
{
    /**
     * Retrieve all expense chapters (capitoli) for a specific condominium and exercise.
     * 
     * This endpoint returns a list of expense chapters (id and name)
     * that can be used as parent accounts for sub-accounts.
     * 
     * @param Condominio $condominio The condominium
     * @param Request $request The request containing esercizio_id and piano_conto_id
     * 
     * @return JsonResponse
     * 
     */
    public function __invoke(Condominio $condominio, Request $request): JsonResponse
    {
        try {
            
            $request->validate([
                'piano_conto_id' => 'required|integer|exists:piani_conti,id'
            ]);

            $pianoContoId = $request->input('piano_conto_id');

            // Get all chapters (capitoli) for this condominium, exercise and piano conto
            $capitoli = Conto::where('piano_conto_id', $pianoContoId)
                ->where('importo', 0) 
                ->select('id', 'nome')
                ->orderBy('nome')
                ->get();

            return response()->json($capitoli);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error'   => 'Validation error',
                'message' => 'piano_conto_id are required and must be valid',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            Log::warning('Condominium not found in FetchCapitoliContiController', [
                'condominio_id' => $condominio->id ?? 'null',
            ]);

            return response()->json([
                'error'   => 'Condominium not found',
                'message' => 'The specified condominium does not exist'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error in FetchCapitoliContiController: ' . $e->getMessage(), [
                'condominio_id' => $condominio->id ?? 'null',
                'esercizio_id' => $esercizioId ?? 'null',
                'piano_conto_id' => $pianoContoId ?? 'null',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error'   => 'Internal server error',
                'message' => 'An error occurred while loading the chapters'
            ], 500);
        }
    }
}
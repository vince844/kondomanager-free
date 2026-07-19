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
                'piano_conto_id' => 'required|integer|exists:piani_conti,id',
                'conto_id'       => 'nullable|integer|exists:conti,id',
            ]);

            $pianoContoId = $request->input('piano_conto_id');
            $contoId      = $request->input('conto_id');

            // Un "capitolo padre" valido è un conto di PRIMO LIVELLO (parent_id NULL)
            // e vuoto (importo 0). Un sotto-conto (parent_id valorizzato) non può mai
            // fare da padre, nemmeno se lasciato a 0: prima la lista si basava solo su
            // importo == 0, così un sotto-conto a zero veniva scambiato per un capitolo.
            $query = Conto::where('piano_conto_id', $pianoContoId)
                ->whereNull('parent_id')
                ->where('importo', 0)
                ->visibili();  // ← scope: where('is_tecnico', false)

            // In modifica (conto_id presente): escludi il conto stesso — così non può
            // essere scelto come padre di sé stesso — e i suoi discendenti, per non
            // creare cicli nell'albero dei conti.
            if ($contoId) {
                $conto       = Conto::find($contoId);
                $daEscludere = array_merge([(int) $contoId], $conto ? $conto->getAllChildrenIds() : []);
                $query->whereNotIn('id', $daEscludere);
            }

            $capitoli = $query->select('id', 'nome')
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
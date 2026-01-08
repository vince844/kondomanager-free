<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FetchCapitoliPerGestioneController extends Controller
{
    /**
     * Restituisce i conti "padre" (capitoli) per una specifica gestione.
     */
    public function __invoke(Condominio $condominio, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'gestione_id' => 'required|integer|exists:gestioni,id'
            ]);

            $gestioneId = $request->input('gestione_id');

            // 1. Recuperiamo la gestione con il suo piano conti
            $gestione = Gestione::with('pianoConto')->findOrFail($gestioneId);

            // 2. Controllo di sicurezza: la gestione appartiene al condominio?
            if ($gestione->condominio_id !== $condominio->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // 3. Se non c'è piano conti, lista vuota
            if (!$gestione->pianoConto) {
                return response()->json([]);
            }

            // 4. Recuperiamo i capitoli (Conti senza parent_id)
            $capitoli = $gestione->pianoConto->conti()
                ->whereNull('parent_id') // Solo i padri
                ->select('id', 'nome')
                ->orderBy('nome')
                ->get();

            return response()->json($capitoli);

        } catch (\Exception $e) {
            Log::error('Errore fetch capitoli per gestione', [
                'condominio_id' => $condominio->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Errore interno', 
                'message' => 'Impossibile recuperare i capitoli'
            ], 500);
        }
    }
}
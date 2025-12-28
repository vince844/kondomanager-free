<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\RataQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SituazioneDebitoriaController extends Controller
{
    public function __invoke(Request $request, Condominio $condominio): JsonResponse
    {
        $query = RataQuote::query()
            ->whereHas('rata', function($q) use ($condominio) {
                $q->whereHas('pianoRate', fn($p) => $p->where('condominio_id', $condominio->id));
            });

        // 🔥 MODIFICA: Logica di filtro migliorata
        // Includiamo:
        // 1. Rate normali dove c'è ancora debito (importo > pagato)
        // 2. Rate a credito (importo < 0) che fungono da 'bonus' per l'utente
        $query->where(function($q) {
            $q->whereRaw('importo > importo_pagato') // Debiti non saldati
              ->orWhere('importo', '<', 0);          // Crediti (sempre visibili)
        });

        if ($request->has('immobile_id') && $request->immobile_id) {
            $query->where('immobile_id', $request->immobile_id);
        } 
        elseif ($request->has('anagrafica_id') && $request->anagrafica_id) {
            $query->where('anagrafica_id', $request->anagrafica_id);
        } 
        else {
            return response()->json(['rate' => []]);
        }

        $quote = $query->with(['rata.pianoRate.gestione', 'immobile', 'rata', 'anagrafica'])
            ->orderBy('immobile_id')
            ->orderBy('data_scadenza', 'asc') // Ordine cronologico importante per applicare i crediti
            ->get()
            ->map(function ($quota) {
                
                $tipologia = null;
                if ($quota->anagrafica_id && $quota->immobile_id) {
                    $tipologia = DB::table('anagrafica_immobile')
                        ->where('anagrafica_id', $quota->anagrafica_id)
                        ->where('immobile_id', $quota->immobile_id)
                        ->value('tipologia');
                }

                // Calcolo residuo (può essere negativo se è un credito)
                $residuo = ($quota->importo - $quota->importo_pagato) / 100;

                return [
                    'id'              => $quota->id,
                    'rata_padre_id'   => $quota->rata_id,
                    'descrizione'     => ($quota->rata->descrizione ?? 'Rata') . ' (n.' . ($quota->rata->numero_rata ?? '-') . ')',
                    'scadenza_human'  => $quota->data_scadenza ? Carbon::parse($quota->data_scadenza)->format('d/m/Y') : 'N/D',
                    'residuo'         => $residuo,
                    'gestione'        => $quota->rata->pianoRate->gestione->nome ?? 'Generica',
                    'gestione_id'     => $quota->rata->pianoRate->gestione_id,
                    'unita'           => $quota->immobile ? "Int. {$quota->immobile->interno} ({$quota->immobile->nome})" : '-',
                    'intestatario'    => $quota->anagrafica ? $quota->anagrafica->nome : 'N/D',
                    'tipologia'       => $tipologia ? ucfirst($tipologia) : '',
                    'da_pagare'       => 0,
                    'selezionata'     => false,
                    'scaduta'         => $quota->data_scadenza && Carbon::parse($quota->data_scadenza)->isPast(),
                    'is_credito'      => $residuo < 0 // Flag utile per il frontend
                ];
            });

        return response()->json(['rate' => $quote]);
    }
}
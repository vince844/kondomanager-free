<?php

namespace App\Http\Controllers\Gestionale\Movimenti; // O Api, in base a dove l'hai messo realmente

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SituazioneDebitoriaController extends Controller
{
    /**
     * Recupera le rate non pagate.
     */
    public function __invoke(Request $request, Condominio $condominio): JsonResponse
    {
        $query = RataQuote::query()
            ->whereHas('rata', function($q) use ($condominio) {
                $q->whereHas('pianoRate', fn($p) => $p->where('condominio_id', $condominio->id));
            })
            ->whereRaw('importo > importo_pagato');

        // 1. FILTRO PER IMMOBILE (Modalità "Paga per la casa")
        if ($request->has('immobile_id') && $request->immobile_id) {
            // Qui mostriamo TUTTI i proprietari di quell'immobile
            $query->where('immobile_id', $request->immobile_id);
        } 
        // 2. FILTRO PER PERSONA (Modalità "Paga per sé")
        elseif ($request->has('anagrafica_id') && $request->anagrafica_id) {
            // 🔥 MODIFICA QUI: Torniamo alla ricerca specifica
            // Invece di cercare l'immobile, cerchiamo direttamente le quote intestate a LEI.
            $query->where('anagrafica_id', $request->anagrafica_id);
        } 
        else {
            return response()->json(['rate' => []]);
        }

        $quote = $query->with(['rata.pianoRate.gestione', 'immobile', 'rata', 'anagrafica'])
            ->orderBy('immobile_id')
            ->orderBy('data_scadenza', 'asc')
            ->get()
            ->map(function ($quota) {
                return [
                    'id'              => $quota->id,
                    'rata_padre_id'   => $quota->rata_id,
                    'descrizione'     => ($quota->rata->descrizione ?? 'Rata') . ' (n.' . ($quota->rata->numero_rata ?? '-') . ')',
                    'scadenza_human'  => $quota->data_scadenza ? Carbon::parse($quota->data_scadenza)->format('d/m/Y') : 'N/D',
                    'residuo'         => ($quota->importo - $quota->importo_pagato) / 100,
                    'gestione'        => $quota->rata->pianoRate->gestione->nome ?? 'Generica',
                    'unita'           => $quota->immobile ? "Int. {$quota->immobile->interno} ({$quota->immobile->nome})" : '-',
                    'intestatario'    => $quota->anagrafica ? $quota->anagrafica->nome : 'N/D', 
                    'da_pagare'       => 0,
                    'selezionata'     => false,
                    'scaduta'         => $quota->data_scadenza && Carbon::parse($quota->data_scadenza)->isPast()
                ];
            });

        return response()->json(['rate' => $quote]);
    }
}
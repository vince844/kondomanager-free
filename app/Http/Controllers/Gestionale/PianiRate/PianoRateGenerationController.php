<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PianoRateGenerationController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Request $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, GeneratePianoRateAction $generateAction): RedirectResponse
    {
        // 1. Check Pagamenti (Esistente)
        $haPagamenti = $pianoRate->rate()
            ->whereHas('rateQuote', fn($q) => $q->where('importo_pagato', '>', 0))
            ->exists();

        if ($haPagamenti) {
            return back()->with($this->flashError(
                "Impossibile ricalcolare: ci sono rate con incassi registrati. Annulla prima gli incassi."
            ));
        }

        // 2. NUOVO CHECK: Rate Emesse (Sicurezza Contabile)
        $haEmissioni = $pianoRate->rate()
            ->whereHas('rateQuote', fn($q) => $q->whereNotNull('scrittura_contabile_id'))
            ->exists();

        if ($haEmissioni) {
            return back()->with($this->flashError(
                "Impossibile ricalcolare: ci sono rate già emesse in contabilità. Annulla prima le emissioni usando il tasto 'Annulla' nella tabella."
            ));
        }

        try {
            DB::beginTransaction();

            // Ora è sicuro cancellare, perché sappiamo che sono tutte in bozza
            $pianoRate->rate()->delete();

            $stats = $generateAction->execute($pianoRate);

            DB::commit();

            return back()->with($this->flashSuccess(
                "Piano rate ricalcolato con successo! Rate generate: {$stats['rate_create']}"
            ));

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore rigenerazione piano rate", ['error' => $e->getMessage()]);
            return back()->with($this->flashError("Errore durante il ricalcolo: " . $e->getMessage()));
        }
    }
}
<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Http\Controllers\Controller;
use App\Models\Gestionale\PianoRate;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PianoRateGenerationController extends Controller
{
    use HandleFlashMessages;

    /**
     * Rigenera le rate per un piano esistente.
     * Cancella le rate attuali (e le quote a cascata) e rilancia il calcolo.
     */
    public function __invoke(PianoRate $pianoRate, GeneratePianoRateAction $generateAction): RedirectResponse
    {
        // 1. Controllo di sicurezza: Ci sono pagamenti registrati?
        // Se ci sono rate con importo_pagato > 0, rigenerare è pericoloso perché
        // si perderebbero i riferimenti contabili.
        $haPagamenti = $pianoRate->rate()
            ->whereHas('rateQuote', fn($q) => $q->where('importo_pagato', '>', 0))
            ->exists();

        if ($haPagamenti) {
            return back()->with($this->flashError(
                "Impossibile ricalcolare: ci sono già pagamenti registrati su questo piano. Annulla prima gli incassi."
            ));
        }

        try {
            DB::beginTransaction();

            // 2. Cancelliamo le vecchie rate (il Cascade del DB eliminerà le rate_quote)
            $pianoRate->rate()->delete();

            // 3. Eseguiamo la Action di generazione (che ora userà i segni corretti dai saldi!)
            $stats = $generateAction->execute($pianoRate);

            DB::commit();

            return back()->with($this->flashSuccess(
                "Piano rate ricalcolato con successo! Rate generate: {$stats['rate_create']}"
            ));

        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error("Errore rigenerazione piano rate", [
                'id' => $pianoRate->id,
                'error' => $e->getMessage()
            ]);

            return back()->with($this->flashError("Errore durante il ricalcolo: " . $e->getMessage()));
        }
    }
}
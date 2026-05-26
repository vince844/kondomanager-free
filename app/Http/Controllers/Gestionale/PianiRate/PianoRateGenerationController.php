<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\SyncOrphanChaptersAction;
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

    public function __invoke(
        Request $request, 
        Condominio $condominio, 
        Esercizio $esercizio, 
        PianoRate $pianoRate, 
        GeneratePianoRateAction $generateAction,
        SyncOrphanChaptersAction $syncAction
    ): RedirectResponse
    {
        // Validazione Input (Array di ID opzionale)
        $validated = $request->validate([
            'orphan_ids' => 'nullable|array',
            'orphan_ids.*' => 'integer|exists:conti,id',
        ]);

        // 1. Check Pagamenti (Blocco Totale)
        $haPagamenti = $pianoRate->rate()
            ->whereHas('rateQuote', fn($q) => $q->where('importo_pagato', '>', 0))
            ->exists();

        if ($haPagamenti) {
            return back()->with($this->flashError(
                "Impossibile ricalcolare: ci sono rate con incassi registrati. Annulla prima gli incassi."
            ));
        }

        // 2. Check Emissioni (Blocco Soft)
        $haEmissioni = $pianoRate->rate()
            ->whereHas('rateQuote', fn($q) => $q->whereNotNull('scrittura_contabile_id'))
            ->exists();

        if ($haEmissioni) {
            return back()->with($this->flashError(
                "Impossibile ricalcolare: ci sono rate già emesse in contabilità. Annulla prima le emissioni."
            ));
        }

        try {
            DB::beginTransaction();

            // 3. Sincronizzazione Granulare
            $orphanIds = $validated['orphan_ids'] ?? [];
            $nuoviCapitoli = 0;
            
            // Se l'array è vuoto, significa che l'utente ha scelto "Ricalcola (Senza aggiunte)"
            if (!empty($orphanIds)) {
                $nuoviCapitoli = $syncAction->execute($pianoRate, $orphanIds);
            }

            // 4. Reset e Rigenerazione Inbox
            $vecchioStato = $pianoRate->stato;
            
            // Svuotiamo l'inbox dai vecchi eventi orfani
            if ($vecchioStato === \App\Enums\StatoPianoRate::APPROVATO) {
                \App\Events\Gestionale\PianoRateStatusUpdated::dispatch(
                    $condominio, $esercizio, $pianoRate, \Illuminate\Support\Facades\Auth::user(), 
                    $vecchioStato, \App\Enums\StatoPianoRate::BOZZA
                );
            }

            $pianoRate->rate()->delete();
            $stats = $generateAction->execute($pianoRate);

            // Ricreiamo l'inbox con i nuovi ID delle rate
            if ($vecchioStato === \App\Enums\StatoPianoRate::APPROVATO) {
                \App\Events\Gestionale\PianoRateStatusUpdated::dispatch(
                    $condominio, $esercizio, $pianoRate, \Illuminate\Support\Facades\Auth::user(), 
                    \App\Enums\StatoPianoRate::BOZZA, $vecchioStato
                );
            }

            DB::commit();

            $msg = "Piano rate ricalcolato con successo!";
            if ($nuoviCapitoli > 0) {
                $msg = "Sincronizzazione completata: inclusi {$nuoviCapitoli} nuovi capitoli di spesa.";
            }

            return back()->with($this->flashSuccess($msg));

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore rigenerazione piano rate", ['error' => $e->getMessage()]);
            return back()->with($this->flashError("Errore durante il ricalcolo: " . $e->getMessage()));
        }
    }
}
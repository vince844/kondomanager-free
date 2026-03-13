<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Rata;
use App\Models\Evento;
use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;

class StornoIncassoController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Request $request, Condominio $condominio, ScritturaContabile $scrittura, StornoIncassoRateAction $action) 
    {
        // 1. Sicurezza: verifichiamo che la scrittura appartenga al condominio corrente
        if ($scrittura->condominio_id !== $condominio->id) {
            abort(403, 'Azione non autorizzata su questo condominio.');
        }

        if ($scrittura->stato === 'annullata') {
            return back();
        }

        // 2. EAGER LOAD COMPLETO: Il controller prepara tutti i dati necessari per la vista e per l'Action
        $scrittura->load(['figlie.righe', 'figlie.quotePagate', 'righe', 'quotePagate']);

        // 3. RACCOLTA QUOTE GLOBALE: Estraiamo TUTTE le quote (padre + figlie)
        $tutteLeQuote = collect();
        $tutteLeQuote = $tutteLeQuote->merge($scrittura->quotePagate);
        
        foreach ($scrittura->figlie as $figlia) {
            $tutteLeQuote = $tutteLeQuote->merge($figlia->quotePagate);
        }

        // 4. Estraiamo ID unici per l'aggiornamento degli eventi
        $rateIds = $tutteLeQuote
            ->where('importo', '>', 0)
            ->pluck('rata_id')
            ->unique()
            ->toArray();

        $anagraficheIds = $tutteLeQuote
            ->where('importo', '>', 0)
            ->pluck('anagrafica_id')
            ->unique()
            ->toArray();

        // 5. ESEGUIAMO L'AZIONE DI STORNO (Che inverte sia padre che figlie)
        $action->execute($scrittura, $condominio);

        // 6. AGGIORNIAMO LO SCADENZIARIO UTENTI
        if (!empty($rateIds) && !empty($anagraficheIds)) {
            
            $eventiDaRipristinare = Evento::where('meta->type', 'scadenza_rata_condomino')
                ->where(function ($q) use ($rateIds) {
                    foreach ($rateIds as $rId) {
                        $q->orWhere('meta->context->rata_id', (int)$rId)
                          ->orWhere('meta->context->rata_id', (string)$rId);
                    }
                })
                ->whereHas('anagrafiche', fn($q) => $q->whereIn('anagrafica_id', $anagraficheIds))
                ->get();

            foreach ($eventiDaRipristinare as $evento) {
                $rataId    = $evento->meta['context']['rata_id'] ?? null;
                $paganteId = $evento->anagrafiche->first()->id ?? null;

                $rataFresca = Rata::with('rateQuote')->find($rataId);
                
                if ($rataFresca && $paganteId) {
                    $quoteUtente = $rataFresca->rateQuote
                        ->where('anagrafica_id', $paganteId)
                        ->where('importo', '>', 0);
                    
                    $totalePagato = $quoteUtente->sum('importo_pagato');
                    $totaleDovuto = $quoteUtente->sum('importo');
                    $restante     = max(0, $totaleDovuto - $totalePagato);

                    $meta = $evento->meta;
                    $meta['importo_pagato']   = $totalePagato;
                    $meta['importo_restante'] = $restante;
                    
                    if ($restante <= 0.01) {
                        $meta['status'] = 'paid';
                    } elseif ($totalePagato > 0.01) {
                        $meta['status'] = 'partial';
                    } else {
                        $meta['status'] = 'pending'; // Tornerà "DA PAGARE"
                    }

                    $evento->update(['meta' => $meta]);
                }
            }
        }

        return back()->with($this->flashSuccess('Storno completato e scadenziario utenti aggiornato.'));
    }
}
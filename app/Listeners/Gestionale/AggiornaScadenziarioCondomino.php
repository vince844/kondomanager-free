<?php

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\IncassoRegistrato;
use App\Models\Evento;
use App\Services\Gestionale\InboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Number;

/**
 * Listener per l'aggiornamento dello scadenziario del condòmino.
 * Intercetta la registrazione di un incasso e ricalcola il debito residuo
 * per la specifica anagrafica. Aggiorna lo stato visivo (es. "Pagato", "Parziale")
 * e gestisce la pulizia automatica dei task amministrativi (Inbox Zero).
 */
class AggiornaScadenziarioCondomino implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Gestisce l'evento di incasso registrato.
     *
     * @param IncassoRegistrato $event L'evento scatenato dal salvataggio di un incasso.
     * @return void
     */
    public function handle(IncassoRegistrato $event): void
    {
        // 1. Ricalcolo Stato Reale
        $rata = $event->rata->fresh(['rateQuote.pagamenti']); 
        
        $quoteAnagrafica = $rata->rateQuote->where('anagrafica_id', $event->anagrafica->id);
        $totaleDovuto = $quoteAnagrafica->sum('importo');
        $totalePagato = $quoteAnagrafica->pluck('pagamenti')->flatten()->sum('importo'); 

        $restante = $totaleDovuto - $totalePagato;

        // 2. Determina Flag e Titoli
        $requiresAction = false; // Default: se pago o pago parziale, l'admin ha fatto la sua parte.

        if ($restante <= 0.05) { 
            $stato = 'paid';
            $titolo = "PAGATO - Rata {$rata->numero_rata}";
            
            // --- INIZIO KILLER DEI TASK (INBOX ZERO) ---
            
            // A. Uccide il task di verifica pagamento per QUESTO specifico condòmino
            Evento::whereJsonContains('meta->type', 'verifica_pagamento')
                ->whereJsonContains('meta->context->rata_id', $rata->id)
                ->whereJsonContains('meta->context->anagrafica_id', $event->anagrafica->id)
                ->delete();

            // B. Controllo Globale: Se l'intera rata di tutti i condòmini è stata saldata, 
            // uccidiamo anche il task generico di "controllo_incassi" per questa rata.
            $totaleRataGlobale = $rata->rateQuote->sum('importo');
            $totalePagatoGlobale = $rata->rateQuote->pluck('pagamenti')->flatten()->sum('importo');
            
            if (($totaleRataGlobale - $totalePagatoGlobale) <= 0.05) {
                Evento::whereJsonContains('meta->type', 'controllo_incassi')
                    ->whereJsonContains('meta->context->rata_id', $rata->id)
                    ->delete();
            }

            // Pulisce la cache per far sparire il numeretto rosso dal menu dell'admin in tempo reale
            InboxService::clearAdminCache();
            
            // --- FINE KILLER DEI TASK ---

        } elseif ($totalePagato > 0) {
            $stato = 'partial';
            $titolo = "PARZIALE - Rata {$rata->numero_rata}";
        } else {
            // Caso: Storno totale (torna a zero)
            $stato = 'pending';
            $titolo = "Scadenza Rata {$rata->numero_rata} - {$rata->pianoRate->nome}";
        }

        // 3. Aggiorna DB (Modifica l'evento lato condòmino)
        Evento::whereJsonContains('meta->context->rata_id', $rata->id)
            ->whereJsonContains('meta->type', 'scadenza_rata_condomino')
            ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $event->anagrafica->id))
            ->update([
                'title' => $titolo,
                'meta->status' => $stato,
                'meta->requires_action' => $requiresAction, 
                'meta->importo_pagato' => $totalePagato,
                'meta->importo_restante' => $restante,
                'description' => $this->buildDescription($rata, $totaleDovuto, $totalePagato, $stato)
            ]);
    }

    /**
     * Costruisce la descrizione testuale per l'evento del condòmino
     * in base allo stato attuale del pagamento.
     *
     * @param mixed $rata L'oggetto rata.
     * @param float|int $dovuto L'importo totale dovuto (in centesimi).
     * @param float|int $pagato L'importo totale pagato (in centesimi).
     * @param string $stato Lo stato testuale ('paid', 'partial', 'pending').
     * @return string
     */
    private function buildDescription($rata, $dovuto, $pagato, $stato): string
    {
        $fmt = fn($n) => Number::currency($n / 100, 'EUR');
        $desc = "Rata n.{$rata->numero_rata} del piano '{$rata->pianoRate->nome}'.\n";
        
        if ($stato === 'paid') {
            $desc .= "\nSALDATA COMPLETAMENTE\nPagato: {$fmt($pagato)} il " . now()->format('d/m/Y');
        } elseif ($stato === 'partial') {
            $desc .= "\nPAGAMENTO PARZIALE\nDovuto: {$fmt($dovuto)}\nVersato: {$fmt($pagato)}\nRestante: {$fmt($dovuto - $pagato)}";
        } else {
            $desc .= "Importo: {$fmt($dovuto)}.\nNote: {$rata->note}";
        }
        return $desc;
    }
}
<?php

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\IncassoRegistrato;
use App\Models\Evento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Number;

class AggiornaScadenziarioCondomino implements ShouldQueue
{
    use InteractsWithQueue;

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
            $titolo = "✅ PAGATO - Rata {$rata->numero_rata}";
            // RequiresAction resta false: problema risolto.
        } elseif ($totalePagato > 0) {
            $stato = 'partial';
            $titolo = "⚠️ PARZIALE - Rata {$rata->numero_rata}";
            // RequiresAction resta false: l'admin ha appena registrato l'incasso, non deve fare altro ORA.
        } else {
            // Caso: Storno totale (torna a zero)
            $stato = 'pending';
            $titolo = "Scadenza Rata {$rata->numero_rata} - {$rata->pianoRate->nome}";
            // Qui RequiresAction potrebbe tornare TRUE solo se l'utente avesse ri-segnalato, 
            // ma per ora lo lasciamo false perché è tornato allo stato base.
        }

        // 3. Aggiorna DB
        Evento::whereJsonContains('meta->context->rata_id', $rata->id)
            ->whereJsonContains('meta->type', 'scadenza_rata_condomino')
            ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $event->anagrafica->id))
            ->update([
                'title' => $titolo,
                'meta->status' => $stato,
                'meta->requires_action' => $requiresAction, // Aggiorna il flag Inbox
                'meta->importo_pagato' => $totalePagato,
                'meta->importo_restante' => $restante,
                'description' => $this->buildDescription($rata, $totaleDovuto, $totalePagato, $stato)
            ]);
    }

    private function buildDescription($rata, $dovuto, $pagato, $stato)
    {
        $fmt = fn($n) => Number::currency($n / 100, 'EUR');
        $desc = "Rata n.{$rata->numero_rata} del piano '{$rata->pianoRate->nome}'.\n";
        
        if ($stato === 'paid') {
            $desc .= "\n✅ SALDATA COMPLETAMENTE\nPagato: {$fmt($pagato)} il " . now()->format('d/m/Y');
        } elseif ($stato === 'partial') {
            $desc .= "\n⚠️ PAGAMENTO PARZIALE\nDovuto: {$fmt($dovuto)}\nVersato: {$fmt($pagato)}\nRestante: {$fmt($dovuto - $pagato)}";
        } else {
            $desc .= "Importo: {$fmt($dovuto)}.\nNote: {$rata->note}";
        }
        return $desc;
    }
}
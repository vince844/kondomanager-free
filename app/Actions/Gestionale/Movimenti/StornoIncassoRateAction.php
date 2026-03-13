<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Facades\DB;

class StornoIncassoRateAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio): void
    {
        // 1. GUARD CLAUSE: Preveniamo lo "Storno dello Storno" (Inception contabile)
        if ($scrittura->tipo_movimento === 'rettifica') {
            throw new \RuntimeException('Non è possibile stornare una scrittura di rettifica.');
        }

        DB::transaction(function () use ($scrittura, $condominio) {
            
            // Raccogliamo la scrittura padre e tutte le sue figlie precaricate
            $scrittureDaStornare = collect([$scrittura])->merge($scrittura->figlie);

            foreach ($scrittureDaStornare as $scr) {
                
                // Protezione contro i doppi click
                if ($scr->stato === 'annullata') continue;

                // 3. Creiamo la Scrittura speculare di Rettifica
                $rettifica = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $scr->esercizio_id,
                    'gestione_id'        => $scr->gestione_id,
                    'scrittura_padre_id' => $scr->id, // 🟢 LINK DIRETTO ALLA SCRITTURA ORIGINALE
                    'data_registrazione' => now(),
                    'data_competenza'    => $scr->data_competenza,
                    'causale'            => 'Storno: ' . $scr->causale,
                    'tipo_movimento'     => 'rettifica',
                    'stato'              => 'registrata',
                    'note'               => 'Annullamento prot. ' . $scr->numero_protocollo,
                ]);

                // 4. Invertiamo le righe (Partita Doppia)
                foreach ($scr->righe as $riga) {
                    $rettifica->righe()->create([
                        'conto_contabile_id' => $riga->conto_contabile_id,
                        'cassa_id'           => $riga->cassa_id,
                        'anagrafica_id'      => $riga->anagrafica_id,
                        'rata_id'            => $riga->rata_id,
                        'immobile_id'        => $riga->immobile_id,
                        'tipo_riga'          => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                        'importo'            => $riga->importo,
                    ]);
                }

                // 5. Ripristiniamo il debito/credito sulle quote (Metodo Audit-Safe)
                foreach ($scr->quotePagate as $quota) {
                    
                    // Sfruttiamo il caricamento Eager per leggere il dato storico dalla pivot
                    $importoStorico = $quota->pivot->importo_pagato;
                        
                    // Attach() con importo negativo per stornare
                    $quota->pagamenti()->attach($rettifica->id, [
                        'importo_pagato' => -$importoStorico,
                        'data_pagamento' => $scr->data_competenza,
                    ]);
                    
                    // Ricalcolo dello stato leggendo la nuova pivot aggiornata
                    $quota->ricalcolaStato();
                }

                // 6. Sigilliamo la scrittura originale
                $scr->update(['stato' => 'annullata']);
            }
        });
    }
}
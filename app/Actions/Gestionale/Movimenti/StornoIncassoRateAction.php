<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Facades\DB;

class StornoIncassoRateAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio): void
    {
        DB::transaction(function () use ($scrittura, $condominio) {

            $storno = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $scrittura->esercizio_id,
                'gestione_id' => $scrittura->gestione_id,
                'data_registrazione' => now(),
                'data_competenza' => $scrittura->data_competenza,
                'causale' => 'STORNO: ' . $scrittura->causale,
                'tipo_movimento' => 'rettifica',
                'stato' => 'registrata',
                'note' => 'Annullamento prot. ' . $scrittura->numero_protocollo,
            ]);

            foreach ($scrittura->righe as $riga) {
                $storno->righe()->create([
                    'conto_contabile_id' => $riga->conto_contabile_id,
                    'cassa_id' => $riga->cassa_id,
                    'anagrafica_id' => $riga->anagrafica_id,
                    'rata_id' => $riga->rata_id,
                    'immobile_id' => $riga->immobile_id,
                    'tipo_riga' => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                    'importo' => $riga->importo,
                ]);
            }

            $quote = $scrittura->quotePagate;

            $scrittura->quotePagate()->detach();

            foreach ($quote as $quota) {
                $quota->ricalcolaStato();
            }

            $scrittura->update(['stato' => 'annullata']);
        });
    }
}

<?php

namespace App\Traits;

use App\Helpers\DateHelper;
use App\Services\Subentro\GuardieTitolarita;

/**
 * Le due guardie di «Associa soggetto» e «Modifica associazione», dalla 1.11.0-beta.31 (B2, S5)
 * riscritte per il tempo e delegate a `GuardieTitolarita` — le stesse funzioni che attraversa
 * `RegistraSubentroAction`, così una riga della pivot nasce con le stesse regole da qualunque porta.
 *
 * - Guardia 1 (inv. 12): non «la persona è già collegata», ma «i due periodi della stessa persona con lo
 *   stesso ruolo si sovrappongono». Vende e ricompra: ammesso.
 * - Guardia 2 (inv. 11): la somma delle quote per ruolo ≤ 100 **in ogni giorno**, non su tutte le righe.
 */
trait ValidatesImmobileAnagraficaPivot
{
    protected function withPivotValidator($validator, $tipologiaField = 'tipologia', $quotaField = 'quota')
    {
        $validator->after(function ($validator) use ($tipologiaField, $quotaField) {
            $immobile = $this->route('immobile');
            // Dalla 1.11.0-beta.31 la rotta di modifica porta la **riga** (`{titolarita}`,
            // `TitolaritaImmobile`), non la persona: la riga in modifica si esclude per `id`.
            $currentRigaId = $this->route('titolarita')?->id ?? null;

            $nuova = [
                'anagrafica_id' => (int) $this->input('anagrafica_id'),
                'tipologia'     => (string) $this->input($tipologiaField),
                'quota'         => (float) $this->input($quotaField),
                'data_inizio'   => $this->input('data_inizio') ?: DateHelper::oggiUtente(),
                'data_fine'     => $this->input('data_fine') ?: null,
            ];
            $righe = $immobile->titolarita()->get();

            if ($sovrapposta = GuardieTitolarita::sovrapposizioneStessaPersona($righe, $nuova, $currentRigaId)) {
                $validator->errors()->add('anagrafica_id', GuardieTitolarita::messaggioSovrapposizione($sovrapposta));
            }

            if ($sforo = GuardieTitolarita::sforoQuotePerGiorno($righe, $nuova, $currentRigaId)) {
                $validator->errors()->add($quotaField, GuardieTitolarita::messaggioSforo($nuova['tipologia'], $sforo));
            }
        });
    }
}

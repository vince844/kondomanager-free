<?php

namespace App\Http\Requests\Gestionale\Movimenti;

/**
 * La richiesta della rotta di sola lettura che dice al modulo dell'incasso, prima di salvare, se la distribuzione segue l'ordine delle
 * scadenze (decisioni 217 e 218). Riceve le righe come le riceve il salvataggio, con le stesse regole sulla persona, sulle quote e
 * sulla gestione; non le servono la cassa, la data, la causale, l'attività da chiudere né la rata della parte in più, che non cambiano
 * l'ordine. La scelta sul credito della posizione (decisione 30.7) può mancare ancora: il modulo chiede l'esito mentre la si fa.
 */
class ControllaImputazioneRequest extends StoreIncassoRateRequest
{
    public function rules(): array
    {
        $regole = parent::rules();
        unset($regole['cassa_id'], $regole['data_pagamento'], $regole['descrizione'], $regole['related_task_id'], $regole['quota_parte_in_piu_id'],
            $regole['imputazione_scelta'], $regole['imputazione_nota']);
        $regole['credito_prima'] = ['nullable', 'boolean'];

        return $regole;
    }
}

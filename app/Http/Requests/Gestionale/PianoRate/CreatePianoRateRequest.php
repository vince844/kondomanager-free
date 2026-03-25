<?php

namespace App\Http\Requests\Gestionale\PianoRate;

use Illuminate\Foundation\Http\FormRequest;

class CreatePianoRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gestione_id'          => ['required', 'exists:gestioni,id'],
            'nome'                 => ['required', 'string', 'max:255'],
            'descrizione'          => ['nullable', 'string'],
            'metodo_distribuzione' => ['required', 'in:prima_rata,tutte_rate,rata_zero'],
            'numero_rate'          => ['required', 'integer'],
            'giorno_scadenza'      => ['nullable', 'integer'],
            'note'                 => ['nullable', 'string'],
            
            // Ricorrenza
            'recurrence_enabled'   => ['sometimes', 'boolean'],
            'recurrence_frequency' => ['required_if:recurrence_enabled,1', 'in:WEEKLY,MONTHLY,DAILY,YEARLY'],
            'recurrence_interval'  => ['required_if:recurrence_enabled,1', 'integer', 'min:1'],
            'recurrence_by_day'    => ['nullable', 'array'],
            'recurrence_until'     => ['nullable', 'date'],
            'genera_subito'        => ['sometimes', 'boolean'],
            
            // Assicurati che siano conti validi
            'capitoli_ids'         => 'nullable|array',
            'capitoli_ids.*'       => 'exists:conti,id', 
            
            // Validazione della configurazione dettagliata
            'capitoli_config'      => 'nullable|array',
            'capitoli_config.*.id' => 'required|exists:conti,id',
            'capitoli_config.*.importo' => 'nullable|string', 
            'capitoli_config.*.note' => 'nullable|string|max:255',
            
            // Configurazione personalizzata dei saldi (Riparto manuale Art. 63)
            'saldi_config'                             => ['nullable', 'array'],
            'saldi_config.*.saldo_id'                  => ['required', 'exists:saldi,id'],
            'saldi_config.*.ripartizioni'              => ['required', 'array'],
            'saldi_config.*.ripartizioni.*.anagrafica_id' => ['required', 'exists:anagrafiche,id'],
            'saldi_config.*.ripartizioni.*.importo'    => ['required', 'string'],
        ];
    }
}
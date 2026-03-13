<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncassoRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pagante_id' => 'required|exists:anagrafiche,id',
            'cassa_id' => 'required|exists:casse,id',
            'gestione_id' => 'nullable|exists:gestioni,id',
            'data_pagamento' => 'required|date|before_or_equal:today',
            
            // MODIFICA 1: Permettiamo importo_totale a 0. 
            // Se una rata è coperta al 100% dal credito, in cassa entrano 0 contanti!
            'importo_totale' => 'required|numeric|min:0', 
            
            'descrizione' => 'nullable|string|max:255',
            'eccedenza' => 'nullable|numeric|min:0',
            'dettaglio_pagamenti' => 'required|array',
            'dettaglio_pagamenti.*.rata_id' => 'required|exists:rate_quote,id',
            
            // MODIFICA 2: Accettiamo numeri positivi (pagamenti) e negativi (prelievo credito)
            // L'unico valore che non ha senso è lo zero netto.
            'dettaglio_pagamenti.*.importo' => 'required|numeric|not_in:0', 
        ];
    }
}
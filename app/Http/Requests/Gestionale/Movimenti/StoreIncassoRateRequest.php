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
            'importo_totale' => 'required|numeric|min:0.01',
            'descrizione' => 'nullable|string|max:255',
            'eccedenza' => 'nullable|numeric|min:0',
            'dettaglio_pagamenti' => 'required|array',
            'dettaglio_pagamenti.*.rata_id' => 'required|exists:rate_quote,id',
            'dettaglio_pagamenti.*.importo' => 'required|numeric|min:0.01',
        ];
    }
}

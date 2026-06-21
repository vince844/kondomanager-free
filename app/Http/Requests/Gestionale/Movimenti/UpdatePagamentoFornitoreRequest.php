<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida i dati per la modifica di un pagamento fornitore confermato.
 *
 * Campi immutabili (non validati): fornitore_id, allocazioni fatture,
 * uuid, idempotency_key, numero_protocollo scrittura.
 */
class UpdatePagamentoFornitoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data_pagamento'          => 'required|date',
            'metodo_pagamento'        => 'required|string',
            'conto_corrente_id'       => 'required|exists:conti_contabili,id',
            'importo_lordo_cents'     => 'required|integer|min:1',
            'importo_ritenuta_cents'  => 'nullable|integer|min:0',
            'importo_netto_cents'     => 'required|integer|min:1',
            'causale_bonifico'        => 'nullable|string|max:500',
            'riferimento_bancario'    => 'nullable|string|max:100',
            'iban_beneficiario'       => 'nullable|string|max:50',
            'note_override'           => 'nullable|string|max:2000',

            // --- FLAG SENTINELLA ---
            'allow_overdraft'               => 'boolean',
            'allow_overpayment'             => 'boolean',
            'iban_confermato_manualmente'    => 'boolean',
            'conferma_duplicato_verificato' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'data_pagamento.required'      => 'La data di pagamento è obbligatoria.',
            'metodo_pagamento.required'    => 'Il metodo di pagamento è obbligatorio.',
            'conto_corrente_id.required'   => 'Il conto bancario è obbligatorio.',
            'importo_lordo_cents.required' => "L'importo lordo è obbligatorio.",
            'importo_netto_cents.required' => "L'importo netto è obbligatorio.",
        ];
    }

    /**
     * Normalizza i campi: importo_ritenuta_cents è 0 se non passato.
     */
    protected function prepareForValidation(): void
    {
        if (is_null($this->input('importo_ritenuta_cents'))) {
            $this->merge(['importo_ritenuta_cents' => 0]);
        }
    }
}

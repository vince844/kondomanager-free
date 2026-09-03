<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Carica un XML (o busta `.p7m`) per precompilare il modulo di registrazione — beta.14,
 * decisione 1 di apertura («due porte, una stanza»): questo endpoint non crea nessuna
 * fattura, restituisce solo i dati letti perché il form li usi come prefill.
 */
class ImportaFatturaXmlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ⚠️ `extensions:`, non `mimes:` — stessa ragione di `StoreFatturaDocumentoRequest`
            // (beta.12): una busta CAdES è ASN.1 generico, `finfo` la vede
            // `application/octet-stream` e non riconosce mai `.p7m` dal contenuto.
            'file' => ['required', 'file', 'extensions:xml,p7m', 'max:'.LimiteCaricamento::regolaMax(10.0)],
        ];
    }
}

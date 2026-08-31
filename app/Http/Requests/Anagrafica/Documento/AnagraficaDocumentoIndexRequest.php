<?php

namespace App\Http\Requests\Anagrafica\Documento;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'elenco dei documenti di una singola anagrafica.
 *
 * Gemello di `Fornitore\Documento\FornitoreDocumentoIndexRequest`: stesse colonne ordinabili,
 * stesso filtro sul nome. Il legame è `anagrafica_documento`, cioè i documenti di cui questa
 * persona è **destinataria** — non quelli che ha caricato.
 */
class AnagraficaDocumentoIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /** Tutte e quattro corrispondono a un campo vero. */
    public static function colonneOrdinabili(): array
    {
        return [
            'name'         => 'name',
            'size'         => 'file_size',
            'created_at'   => 'created_at',
            'is_published' => 'is_published',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}

<?php

namespace App\Http\Requests\Anagrafica\Documento;

use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La modifica di un documento sulla scheda di un'anagrafica.
 *
 * ⚠️ **Il file è facoltativo**, e la distinzione conta: assente significa «tieni quello che c'è»,
 * presente significa «sostituiscilo». Renderlo obbligatorio costringerebbe a ricaricare lo stesso
 * file per correggere un nome scritto male.
 */
class UpdateAnagraficaDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'is_published' => ['required', 'boolean'],
            'is_approved'  => ['required', 'boolean'],
            'file'         => ['nullable', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_published' => $this->boolean('is_published'),
            'is_approved'  => $this->boolean('is_approved'),
        ]);
    }

    public function attributes(): array
    {
        return [
            'name'        => 'nome del documento',
            'description' => 'descrizione',
            'file'        => 'file',
        ];
    }
}

<?php

namespace App\Http\Requests\Anagrafica\Documento;

use App\Support\LimiteCaricamento;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * La modifica di un documento sulla scheda di un'anagrafica.
 *
 * ⚠️ **Il file è facoltativo**, e la distinzione conta: assente significa «tieni quello che c'è»,
 * presente significa «sostituiscilo». Renderlo obbligatorio costringerebbe a ricaricare lo stesso
 * file per correggere un nome scritto male.
 */
class UpdateAnagraficaDocumentoRequest extends FormRequest
{
    /**
     * Il permesso prima delle regole (Coda 402): la modifica di un documento della persona chiede la `update` di
     * `AnagraficaPolicy` sulla persona della rotta, come il suo modulo di modifica. Chi non la ha riceve il rifiuto
     * con la sua frase e nessun esito delle regole. `AnagraficaDocumentoController` la chiede di nuovo.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('anagrafica'));
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

<?php

namespace App\Http\Requests\Fornitore;

use App\Helpers\MoneyHelper;
use App\Models\Fornitore;
use App\Rules\UniqueEmailAcrossTables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Support\Str;

class UpdateFornitoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ragione_sociale'          => ['required','string','max:255',Rule::unique(Fornitore::class)->ignore($this->fornitore->id)],
            'partita_iva'              => ['nullable','string','max:20',Rule::unique(Fornitore::class)->ignore($this->fornitore->id)],
            'codice_fiscale'           => ['nullable','string','max:255',Rule::unique(Fornitore::class)->ignore($this->fornitore->id)],
            'nazione'                  => 'nullable|string|max:100',
            'indirizzo'                => 'nullable|string|max:255',
            'comune'                   => 'nullable|string|max:100', 
            'cap'                      => 'nullable|string|max:10',
            'provincia'                => 'nullable|string|max:10',
            'telefono'                 => 'nullable|string|max:20',
            'cellulare'                => 'nullable|string|max:20',
            'fax'                      => 'nullable|string|max:20',
            'email'                    => ['nullable','email','max:255',new UniqueEmailAcrossTables($this->fornitore->id, 'fornitori')],
            'pec'                      => ['nullable','email','max:255','different:email',new UniqueEmailAcrossTables($this->fornitore->id, 'fornitori')],
            'sito_web'                 => 'nullable|string|max:255',
            'note'                     => 'nullable|string',
            'iscrizione_cciaa'         => 'nullable|string|max:100',
            'data_iscrizione_cciaa'    => 'nullable|date',
            'capitale_sociale'         => 'nullable',
            'categoria_id'             => 'nullable|integer|exists:categorie_fornitore,id',
            'codice_ateco'             => 'nullable|string|max:20',
            'numero_iscrizione_ordine' => 'nullable|string|max:100',
            'certificazione_iso'       => 'nullable|boolean',
            'anagrafica_id'            => ['nullable','integer',Rule::exists('anagrafiche', 'id')],
        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'capitale_sociale'      => MoneyHelper::toCents($this->capitale_sociale) ?? 0,
            'data_iscrizione_cciaa' => $this->data_iscrizione_cciaa ? Carbon::parse($this->input('data_iscrizione_cciaa'))->toDateString() : null,
            'email'                 => $this->email ? Str::lower($this->input('email')) : null,
            'pec'                   => $this->pec ? Str::lower($this->input('pec')) : null,
        ]);
    }
}

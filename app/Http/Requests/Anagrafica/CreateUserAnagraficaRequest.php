<?php

namespace App\Http\Requests\Anagrafica;

use App\Models\Anagrafica;
use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * @method bool filled(string $key)
 * @method bool merge(string $key)
 * @method bool input(string $key)
 */
class CreateUserAnagraficaRequest extends FormRequest
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
            'nome'                => 'required|string|max:255',
            'indirizzo'           => 'required|string|max:255',
            'email'               => 'nullable|string|lowercase|email|max:255|unique:'.Anagrafica::class.',email',
            'email_secondaria'    => 'nullable|string|lowercase|email|max:255|different:email',
            'pec'                 => 'nullable|string|lowercase|email|max:255',
            'tipologia_documento' => 'nullable|string|max:255',
            'numero_documento'    => 'nullable|string|max:255',
            'codice_fiscale'      => 'nullable|string|max:255|unique:'.Anagrafica::class.',codice_fiscale',
            'telefono'            => 'nullable|string|max:255',
            'cellulare'           => 'nullable|string|max:255',
            'luogo_nascita'       => 'nullable|string|max:255',
            'scadenza_documento'  => 'nullable|date|after:today',
            'data_nascita'        => 'nullable|date|before:today',
            'note'                => 'nullable|string',
        ];
    }

    // Manipulate the date before validation
    protected function prepareForValidation()
    {

        $this->merge([
            'scadenza_documento' => $this->scadenza_documento 
                ? Carbon::parse($this->input('scadenza_documento'))->toDateString() 
                : null,
            'data_nascita' => $this->data_nascita 
                ? Carbon::parse($this->input('data_nascita'))->toDateString() 
                : null,
            'email' => $this->email
                ? Str::lower($this->input('email')) 
                : null,
            'email_secondaria' => $this->email_secondaria 
                ? Str::lower($this->input('email_secondaria')) 
                : null,
            'pec' => $this->pec 
                ? Str::lower($this->input('pec')) 
                : null,
        ]);

    }
    
    public function messages()
    {
        return [
            'scadenza_documento.after' => __('validation.custom.anagrafica.after:today'),
            'data_nascita.before'      => __('validation.custom.anagrafica.before:today'),
            'codice_fiscale.unique'    => __('validation.custom.anagrafica.codice_fiscale.unique')
        ];
    }

    /**
    * Get custom attributes for validator errors.
    *
    * @return array<string, string>
    */
    public function attributes()
    {
        return [
            'nome'               => __('validation.attributes.anagrafica.nome'),
            'indirizzo'          => __('validation.attributes.anagrafica.indirizzo'),
            'scadenza_documento' => __('validation.attributes.anagrafica.scadenza_documento'),
            'data_nascita'       => __('validation.attributes.anagrafica.data_nascita'),
            'codice_fiscale'     => __('validation.attributes.anagrafica.codice_fiscale')
        ];
    }
}

<?php

namespace App\Http\Requests\Anagrafica;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Anagrafica;

class UpdateAnagraficaRequest extends FormRequest
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
            'email'               => 'nullable|string|lowercase|email|max:255',
            'email_secondaria'    => 'nullable|string|lowercase|email|max:255|different:email',
            'pec'                 => 'nullable|string|lowercase|email|max:255',
            'tipologia_documento' => 'nullable|string|max:255',
            'numero_documento'    => 'nullable|string|max:255',
            'codice_fiscale'      => 'nullable|string|max:255',
            'telefono'            => 'nullable|string|max:255',
            'cellulare'           => 'nullable|string|max:255',
            'luogo_nascita'       => 'nullable|string|max:255',
            'scadenza_documento'  => 'nullable|date|after:today',
            'data_nascita'        => 'nullable|date|before:today',
            'note'                => 'nullable|string',
            'condomini'           => 'nullable|array',
            'condomini.*.id'      => 'exists:condomini,id'
        ];
    }

    // Manipulate the date before validation
    protected function prepareForValidation()
    {
        // Check if 'scadenza_documento' exists and is not empty
        if ($this->filled('scadenza_documento')) {
            $this->merge([
                'scadenza_documento' => Carbon::parse($this->scadenza_documento)->toDateString(),
            ]);
        } else {
            // Convert empty strings to null
            $this->merge([
                'scadenza_documento' => null,
            ]);
        }

        if ($this->filled('data_nascita')) {
            $this->merge([
                'data_nascita' => Carbon::parse($this->data_nascita)->toDateString(),
            ]);
        } else {
            // Convert empty strings to null
            $this->merge([
                'scadenza_documento' => null,
            ]);
        }

        if ($this->has('condomini') && !is_array($this->condomini)) {
            $this->merge(['condomini' => (array)$this->condomini]);
        }
    }
    
    public function messages()
    {
        return [
            'scadenza_documento.after' => __('validation.custom.anagrafica.after:today'),
            'data_nascita.before'      => __('validation.custom.anagrafica.before:today'),
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
        ];
    }
}

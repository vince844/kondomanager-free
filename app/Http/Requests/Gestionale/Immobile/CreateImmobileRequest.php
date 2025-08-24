<?php

namespace App\Http\Requests\Gestionale\Immobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class CreateImmobileRequest extends FormRequest
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
            'descrizione'         => 'required|string|max:255',
            'comune_catasto'      => 'sometimes|nullable|string|max:255',
            'codice_catasto'      => 'sometimes|nullable|string|max:255',
            'sezione_catasto'     => 'sometimes|nullable|string|max:255',
            'foglio_catasto'      => 'sometimes|nullable|string|max:255',
            'particella_catasto'  => 'sometimes|nullable|string|max:255',
            'subalterno_catasto'  => 'sometimes|nullable|string|max:255',
            'interno'             => 'sometimes|nullable|string|max:255',
            'piano'               => 'sometimes|nullable|string|max:255',
            'superficie'          => 'sometimes|nullable|numeric',
            'numero_vani'         => 'sometimes|nullable|integer',
            'note'                => 'sometimes|nullable|string',
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
            'palazzina_id'        => ['sometimes', 'nullable', 'integer', Rule::exists('palazzine', 'id')],
            'scala_id'            => ['sometimes', 'nullable', 'integer', Rule::exists('scale', 'id')],
            'tipologia_id'        => ['required', 'integer', Rule::exists('tipologie_immobili', 'id')]
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id' => $this->route('condominio')->id,
        ]);
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return [
            'tipologia_id'  => __('validation.attributes.immobili.tipologia_id')
        ];
    }
}

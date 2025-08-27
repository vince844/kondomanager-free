<?php

namespace App\Http\Requests\Gestionale\Scala;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScalaRequest extends FormRequest
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
            'name'                => 'required|string|max:255', 
            'description'         => 'required|string|max:255',
            'note'                => 'sometimes|nullable|string',
            'palazzina_id'        => ['nullable', 'integer', Rule::exists('palazzine', 'id')],
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
            'name'          => __('validation.attributes.scale.name'),
            'description'   => __('validation.attributes.scale.description'),
        ];
    }
}

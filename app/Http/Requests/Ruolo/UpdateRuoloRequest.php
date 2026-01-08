<?php

namespace App\Http\Requests\Ruolo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRuoloRequest extends FormRequest
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
            'name'        => ['required','string','max:255', Rule::unique('roles')->ignore($this->ruoli, 'name')],
            'description' => 'required|string|max:255',
            'permissions' => ['sometimes', 'array'],
            'accessAdmin' => 'nullable|boolean'
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
            'name'        => __('validation.attributes.ruoli.name'),
            'description' => __('validation.attributes.ruoli.description'),
        ];
    }
}

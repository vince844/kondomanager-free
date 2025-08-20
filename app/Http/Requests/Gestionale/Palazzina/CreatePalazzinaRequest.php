<?php

namespace App\Http\Requests\Gestionale\Palazzina;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class CreatePalazzinaRequest extends FormRequest
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
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')]
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id' => $this->route('condominio')->id,
        ]);
    }
}

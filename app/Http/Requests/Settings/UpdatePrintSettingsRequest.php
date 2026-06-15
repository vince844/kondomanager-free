<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrintSettingsRequest extends FormRequest
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
            'nota_legale_stampe'         => 'nullable|string',
            'firma_stampe'               => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
            'delete_firma_stampe'        => 'nullable|boolean',
        ];
    }
}

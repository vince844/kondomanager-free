<?php

namespace App\Http\Requests\Segnalazione;

use Illuminate\Foundation\Http\FormRequest;

class SegnalazioneIndexRequest extends FormRequest
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
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'subject'    => ['sometimes', 'string', 'max:255'],
            'priority'   => ['sometimes', 'array'],
            'priority.*' => ['string', 'in:bassa,media,alta,urgente'],
            'stato'      => ['sometimes', 'array'],
            'stato.*'    => ['string', 'in:aperta,in lavorazione,chiusa'],
            'search'     => ['nullable', 'string'],
        ];
    }
}

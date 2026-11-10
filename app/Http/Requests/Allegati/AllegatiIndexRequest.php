<?php

namespace App\Http\Requests\Allegati;

use Illuminate\Foundation\Http\FormRequest;

class AllegatiIndexRequest extends FormRequest
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
            'stato'         => ['sometimes', 'nullable', 'string', 'in:in_attesa,pubblicato'],
            'tipo'          => ['sometimes', 'nullable', 'string', 'in:segnalazioni,comunicazioni'],
            'condominio_id' => ['sometimes', 'nullable', 'integer', 'exists:condomini,id'],
            'autore'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'dal'           => ['sometimes', 'nullable', 'date'],
            'al'            => ['sometimes', 'nullable', 'date'],
            'per_page'      => ['sometimes', 'nullable', 'integer', 'in:10,15,25,50,100'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->redirector->getUrlGenerator()->route('admin.allegati.index');
    }
}

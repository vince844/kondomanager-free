<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class LogsIndexRequest extends FormRequest
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
            'search'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'    => ['sometimes', 'nullable', 'string', 'in:tutti,sent,logged,failed'],
            'mailer'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to'   => ['sometimes', 'nullable', 'date'],
            'per_page'  => ['sometimes', 'nullable', 'integer', 'in:10,25,50,100'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->redirector->getUrlGenerator()->route('logs.index');
    }
}

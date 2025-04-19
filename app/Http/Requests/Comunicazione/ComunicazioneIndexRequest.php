<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ComunicazioneIndexRequest extends FormRequest
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
        $user = Auth::user();

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:10,100'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'priority' => ['sometimes', 'array'],
            'priority.*' => ['string', 'in:bassa,media,alta,urgente'],
            'search' => $user && $user->hasAnyRole(['amministratore', 'collaboratore']) 
            ? ['prohibited'] // Disallow `search` for admin roles
            : ['nullable', 'string'], // Allow for regular users
        ];
    }
}

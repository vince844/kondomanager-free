<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupSettingsRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'retention_keep_last' => 'required|integer|min:1|max:50',
            // Opzionale: quando presente (ri)imposta la password di protezione
            // dei backup. 'confirmed' pretende password_confirmation identica:
            // una password sbagliata renderebbe irrecuperabili i backup futuri.
            'password' => 'nullable|string|min:8|max:72|confirmed',
        ];
    }
}

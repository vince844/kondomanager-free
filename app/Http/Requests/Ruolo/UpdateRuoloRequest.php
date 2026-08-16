<?php

namespace App\Http\Requests\Ruolo;

use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRuoloRequest extends FormRequest
{
    use ValidaConcessioneRuoli;

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
            // ⚠️ **La stessa regola dei permessi sull'utente, applicata al ruolo.** Senza,
            // resta aperta la porta laterale: chi non può concedersi «Elimina utenti» si crea un
            // ruolo su misura che lo contiene — i ruoli creati a mano non sono privilegiati — e
            // poi lo indossa. Chiudere due porte su tre non chiude niente.
            'permissions' => ['sometimes', 'array', $this->regolaPermessiConcedibili()],
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

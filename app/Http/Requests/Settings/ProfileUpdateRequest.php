<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // L'email è la chiave dell'account: chi la cambia riceve i link di «password dimenticata».
            // Cambiarla chiede la password attuale, nello stesso modulo (Coda 261); il solo nome no,
            // e il modulo mandato senza toccare l'email non la chiede.
            'current_password' => [
                Rule::excludeIf(fn () => ! $this->cambiaEmail()),
                'required',
                'current_password',
            ],
        ];
    }

    /**
     * L'email mandata è diversa da quella dell'account?
     */
    public function cambiaEmail(): bool
    {
        return $this->input('email') !== $this->user()->email;
    }
}

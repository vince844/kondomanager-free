<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\User;

use App\Models\Anagrafica;
use App\Models\User;
use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CreateUserRequest extends FormRequest
{
    use ValidaConcessioneRuoli;

    /**
     * Il permesso prima dei campi: chi non può creare utenti riceve il rifiuto, non l'errore «email già in uso» che direbbe
     * se un indirizzo c'è già. La stessa abilità che il controller ripete (`UserPolicy::create`).
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('create', User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|email|max:255|unique:'.User::class,
            'roles'       => ['required', $this->regolaRuoloConcedibile()],
            'permissions' => ['sometimes', 'array', $this->regolaPermessiConcedibili()],
            'anagrafica'  => [
                'nullable',
                Rule::exists('anagrafiche', 'id'), 
                function ($attribute, $value, $fail) {

                    $anagrafica = Anagrafica::with('user')->find($value);
                    
                    if ($anagrafica && $anagrafica->user) {
                        $fail("Questa anagrafica è già associata all'utente: " . $anagrafica->user->name);
                    }
                    
                }
            ],

        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'email'  => Str::lower($this->input('email')),
        ]);
    }

    /**
    * Get custom attributes for validator errors.
    *
    * @return array<string, string>
    */
    public function attributes()
    {
        return [
            'roles' => __('validation.attributes.user.roles'),
        ];
    }

}

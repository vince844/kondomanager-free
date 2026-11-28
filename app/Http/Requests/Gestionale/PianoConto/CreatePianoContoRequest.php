<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\PianoConto;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method bool input(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class CreatePianoContoRequest extends FormRequest
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
            'nome'                => 'required|string|max:255', 
            'descrizione'         => 'required|string|max:255',
            'note'                => 'sometimes|nullable|string',
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
            // La gestione è di questo condominio: quella di un altro, mandata con una richiesta costruita, darebbe al suo preventivo e al suo
            // riparto le voci di un piano che non è loro.
            'gestione_id'         => ['required', 'integer', Rule::exists('gestioni', 'id')->where('condominio_id', $this->route('condominio')->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'gestione_id.exists' => 'La gestione scelta non è di questo condominio.',
        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id'  => $this->route('condominio')->id,
        ]);
    }
}

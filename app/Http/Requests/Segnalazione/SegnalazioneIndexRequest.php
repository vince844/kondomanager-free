<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Segnalazione;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class SegnalazioneIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * «Condominio» è una relazione singola: si ordina per il suo nome.
     * ⚠️ Fuori «Anagrafiche», che è un elenco.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'subject'      => 'subject',
            'created_at'   => 'created_at',
            'condominio'   => fn () => \App\Models\Condominio::select('nome')
                ->whereColumn('condomini.id', 'segnalazioni.condominio_id'),
            'stato'        => 'stato',
            'priority'     => 'priority',
            'is_published' => 'is_published',
            'assegnata_a'  => fn () => \App\Models\User::select('name')
                ->whereColumn('users.id', 'segnalazioni.assigned_to'),
        ];
    }

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
        return array_merge([
            'page'              => ['sometimes', 'integer', 'min:1'],
            'per_page'          => ['sometimes', 'integer'],
            'subject'           => ['sometimes', 'string', 'max:255'],
            'priority'          => ['sometimes', 'array'],
            'priority.*'        => ['string', 'in:bassa,media,alta,urgente'],
            'stato'             => ['sometimes', 'array'],
            'stato.*'           => ['string', 'in:aperta,in lavorazione,chiusa'],
            'condominio_id'     => ['nullable', 'array'],
            'condominio_id.*'   => ['integer'],
            'visibility'        => ['nullable', 'array'],
            'visibility.*'      => ['string', 'in:published,draft,pending,private'],
            'assegnazione'      => ['sometimes', 'array'],
            'assegnazione.*'    => ['string', 'regex:/^(mie|nessuno|[0-9]+)$/'],
            'search'            => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}

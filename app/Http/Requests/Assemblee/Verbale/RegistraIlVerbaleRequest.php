<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Verbale;

use App\Http\Requests\Assemblee\RichiestaDelleAssemblee;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaAMano;
use App\Support\LimiteCaricamento;

/**
 * «Registra il verbale sottoscritto»: chi ha il permesso suo (decisione 562), la copia (pdf, p7m, jpg, png, al più 10 MB, come il verbale della riunione a
 * mano), il numero (facoltativo: senza, quello proposto), «Sottoscritto il», e la prima volta da dove parte il registro. Le due conferme: `senza_la_bozza`
 * (si registra senza aver stampato la bozza da firmare) e `salto` (un numero più alto del proposto).
 */
class RegistraIlVerbaleRequest extends RichiestaDelleAssemblee
{
    public function authorize(): bool
    {
        return AccessoAlleAssemblee::registraIlVerbale($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'copia' => ['required', 'file', 'max:' . LimiteCaricamento::regolaMax(intdiv(DeliberaAMano::KB_MASSIMI_DEL_VERBALE, 1024)),
                'extensions:' . implode(',', DeliberaAMano::ESTENSIONI_DEL_VERBALE)],
            'numero' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'sottoscritto_il' => ['required', 'date_format:Y-m-d'],
            'partenza' => ['nullable', 'array'],
            'partenza.scelta' => ['nullable', 'string', 'in:libro,da_uno'],
            'partenza.ultimo' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'partenza.del' => ['nullable', 'date_format:Y-m-d'],
            'senza_la_bozza' => ['sometimes', 'boolean'],
            'salto' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['copia' => 'la copia sottoscritta', 'numero' => 'il numero nel registro', 'sottoscritto_il' => 'il giorno della firma',
            'partenza.ultimo' => 'l\'ultimo numero del libro di carta', 'partenza.del' => 'il giorno dell\'ultimo verbale di carta'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $frase = 'La copia sottoscritta è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.';

        return parent::messages() + ['copia.required' => 'Scegli la copia sottoscritta.', 'copia.file' => $frase, 'copia.max' => $frase, 'copia.extensions' => $frase];
    }
}

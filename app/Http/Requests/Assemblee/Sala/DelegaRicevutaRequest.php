<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Sala;

use App\Http\Requests\Assemblee\RichiestaDelleAssemblee;
use App\Support\LimiteCaricamento;

/**
 * «Deleghe ricevute»: chi delega, a chi (un condòmino con il suo id, o il nome di una persona), il giorno in cui lo studio l'ha ricevuta, i punti (per la
 * delega del conduttore), il motivo del presidente (con «Decide il presidente»), la scansione facoltativa (PDF o immagine, al più 10 MB). Il resto lo
 * controlla il servizio: la persona dell'assemblea, l'amministratore, il quinto.
 */
class DelegaRicevutaRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delegante_anagrafica_id' => ['required', 'integer'],
            'delegato' => ['required', 'array'],
            'delegato.anagrafica_id' => ['nullable', 'integer'],
            'delegato.nome' => ['nullable', 'string', 'max:255'],
            'ricevuta_il' => ['nullable', 'date_format:Y-m-d'],
            'punti' => ['nullable', 'array'],
            'punti.*' => ['integer'],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'scansione' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:' . LimiteCaricamento::regolaMax(10.0)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['delegante_anagrafica_id' => 'chi delega', 'delegato' => 'a chi è la delega', 'delegato.nome' => 'il nome del delegato',
            'ricevuta_il' => 'il giorno in cui l\'hai ricevuta', 'punti' => 'i punti', 'motivo' => 'il motivo', 'scansione' => 'la scansione'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'scansione.mimes' => 'La scansione della delega è un PDF o un\'immagine (pdf, jpg, png).',
            'scansione.max' => 'La scansione della delega è al più ' . LimiteCaricamento::etichetta(10.0) . '.',
        ];
    }
}

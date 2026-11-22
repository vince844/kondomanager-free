<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\AmbitoAssemblea;
use App\Helpers\DateHelper;
use App\Services\Assemblee\SchedaDelCondominio;
use Illuminate\Validation\Rule;

/**
 * Il memo «Da portare in assemblea» (§3 P8, scena 2 della bozza approvata con la decisione 501): il punto, da dove nasce, per quale assemblea, la nota dello
 * studio, «Ricordamelo» con il giorno e a chi. **«Ricordamelo dal» non accetta un giorno già passato**: la finestra lo dice, e qui il server lo rifiuta.
 * Senza «Ricordamelo» il giorno non si salva (il memo aspetta la prossima assemblea).
 */
class MemoRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $obbligo = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'testo' => [$obbligo, 'string', 'max:2000'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'origine_tipo' => ['nullable', 'string', Rule::in(array_keys(SchedaDelCondominio::TIPI)), 'required_with:origine_id'],
            'origine_id' => ['nullable', 'integer', 'required_with:origine_tipo'],
            'assemblea_id' => ['nullable', 'integer'],
            'ricordami' => ['sometimes', 'boolean'],
            'ricordami_dal' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:' . DateHelper::oggiUtente(), 'required_if:ricordami,true,1'],
            'a_chi' => ['nullable', Rule::in(AChiMemo::valori())],
            'assegnato_a' => ['nullable', 'integer', 'required_if:a_chi,collaboratore'],
            'ambito' => ['nullable', Rule::in(AmbitoAssemblea::valori())],
            'palazzina_id' => ['nullable', 'integer', 'required_if:ambito,palazzina'],
            'scala_id' => ['nullable', 'integer', 'required_if:ambito,scala'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'testo' => 'il punto',
            'nota' => 'la nota',
            'origine_tipo' => 'da dove nasce',
            'origine_id' => 'da dove nasce',
            'assemblea_id' => 'per quale assemblea',
            'ricordami_dal' => 'il giorno di «Ricordamelo»',
            'a_chi' => 'a chi ricordarlo',
            'assegnato_a' => 'il collaboratore',
            'ambito' => 'per chi',
            'palazzina_id' => 'la palazzina',
            'scala_id' => 'la scala',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'testo.required' => 'Scrivi il punto da portare in assemblea.',
            'ricordami_dal.after_or_equal' => 'Il giorno di «Ricordamelo» è già passato: scegli oggi o un giorno dopo.',
            'ricordami_dal.required_if' => 'Scegli il giorno da cui ricordartelo.',
            'assegnato_a.required_if' => 'Scegli il collaboratore.',
        ];
    }

    /** I dati per il servizio: senza «Ricordamelo» il giorno è vuoto. @return array<string, mixed> */
    public function dati(): array
    {
        $dati = $this->validated();
        if (array_key_exists('ricordami', $dati) && ! $this->boolean('ricordami')) {
            $dati['ricordami_dal'] = null;
        }
        unset($dati['ricordami']);

        return $dati;
    }
}

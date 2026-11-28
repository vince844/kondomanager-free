<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\PianoConto\Conto;

use App\Services\Gestionale\LettureDiFineAnno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Le letture di fine anno di una voce ({@see LettureDiFineAnno}): l'unità, il contatore generale e, per ogni tabella della voce, il modo («lettura»,
 * «percentuale», «differenza») e il suo valore. Le tabelle devono essere tutte e sole quelle della voce; il calcolo (le letture oltre il generale, la
 * somma diversa da 100 %, due tabelle per differenza) si rifiuta sul campo `letture`, con la frase del calcolo.
 */
class SalvaLettureDiFineAnnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unita' => ['required', Rule::in(LettureDiFineAnno::UNITA)],
            'generale' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'tabelle' => ['required', 'array', 'min:1'],
            'tabelle.*.tabella_id' => ['required', 'integer', 'distinct'],
            'tabelle.*.modo' => ['required', Rule::in(LettureDiFineAnno::MODI)],
            'tabelle.*.lettura' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'tabelle.*.percentuale' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'generale.gt' => 'La lettura del contatore generale deve essere più di zero.',
            'tabelle.*.lettura.min' => 'Una lettura non può essere negativa.',
            'tabelle.*.percentuale.max' => 'Una percentuale va da 0 a 100.',
            'tabelle.*.percentuale.min' => 'Una percentuale va da 0 a 100.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $conto = $this->route('conto');
            $tabelle = DB::table('conto_tabella_millesimale as ctm')->join('tabelle as t', 't.id', '=', 'ctm.tabella_id')->where('ctm.conto_id', $conto->id)
                ->orderBy('ctm.id')->pluck('t.nome', 'ctm.tabella_id')->all();
            $date = collect($this->input('tabelle'))->keyBy(fn ($t) => (int) $t['tabella_id']);
            if (count($tabelle) < 2) {
                $validator->errors()->add('letture', 'Le letture di fine anno dividono una voce fra più tabelle: questa voce ne ha una sola.');

                return;
            }
            $chiavi = $date->keys()->sort()->values()->all();
            $attese = collect(array_keys($tabelle))->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($chiavi !== $attese) {
                $validator->errors()->add('letture', 'Le tabelle delle letture devono essere quelle della voce, tutte: '.implode(', ', $tabelle).'.');

                return;
            }
            $righe = [];
            foreach ($tabelle as $tid => $nome) {
                $t = $date[(int) $tid];
                $righe[] = ['tabella_id' => (int) $tid, 'nome' => (string) $nome, 'modo' => (string) $t['modo'], 'lettura' => $t['lettura'] ?? null, 'percentuale' => $t['percentuale'] ?? null];
            }
            $generale = $this->input('generale');
            $calcolo = LettureDiFineAnno::calcola($generale === null || $generale === '' ? null : (float) $generale, $righe, (string) $this->input('unita'));
            foreach ($calcolo['errori'] as $errore) {
                $validator->errors()->add('letture', $errore);
            }
        }];
    }
}

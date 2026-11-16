<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Allegati;

use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAllegatiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $elemento = $this->route('segnalazione') ?? $this->route('comunicazione');
        if ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) {
            return RegoleAllegati::puoCaricare($user, $elemento);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return RegoleAllegati::regoleValidazioneFile(obbligatorio: true);
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $files = (array) $this->file('allegati', []);
            if ($errore = RegoleAllegati::erroreSpazio($files)) {
                $validator->errors()->add('allegati', $errore);
            }
        }];
    }
}

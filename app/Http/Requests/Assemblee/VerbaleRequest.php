<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaAMano;

/** Il verbale sottoscritto di una riunione registrata a mano: chi ha «Registra il verbale sottoscritto» (decisione 562), un file come le prove. */
class VerbaleRequest extends RichiestaDelleAssemblee
{
    public function authorize(): bool
    {
        return AccessoAlleAssemblee::registraIlVerbale($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['verbale' => ['required', 'file', 'max:' . DeliberaAMano::KB_MASSIMI_DEL_VERBALE,
            'extensions:' . implode(',', DeliberaAMano::ESTENSIONI_DEL_VERBALE)]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['verbale' => 'il verbale sottoscritto'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'verbale.required' => 'Scegli il file del verbale sottoscritto.',
            'verbale.file' => 'Il verbale sottoscritto è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.',
            'verbale.max' => 'Il verbale sottoscritto è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.',
            'verbale.extensions' => 'Il verbale sottoscritto è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.',
        ];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\TipoAssemblea;
use App\Helpers\DateHelper;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\SchedaDelCondominio;
use Illuminate\Validation\Rule;

/**
 * «Registra una delibera a mano» (§3 P5, scena 4 della bozza approvata con la decisione 502): il giorno della riunione (non dopo oggi), ordinaria o
 * straordinaria (di serie ordinaria), per chi, che cosa ha deciso, l'esito, il numero del verbale di carta e, solo con «Registra il verbale sottoscritto», il
 * file (decisioni 562 e 563: senza, la delibera nasce con «Verbale da caricare»). L'origine e ciò di cui parla arrivano dalla pagina che apre la finestra, e il
 * servizio li risolve nel condominio (R18).
 */
class DeliberaAManoRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'tipo' => ['nullable', Rule::in(TipoAssemblea::valori())],
            'ambito' => ['nullable', Rule::in(AmbitoAssemblea::valori())],
            'palazzina_id' => ['nullable', 'integer', 'required_if:ambito,palazzina'],
            'scala_id' => ['nullable', 'integer', 'required_if:ambito,scala'],
            'testo' => ['required', 'string', 'max:2000'],
            'esito' => ['required', Rule::in(EsitoDelibera::valori())],
            'numero_verbale' => ['nullable', 'string', 'max:50'],
            // Di che cosa parla: dall'archivio la sceglie la finestra; aperta da una pagina, se manca, è quella della pagina.
            'materia' => ['nullable', Rule::in(MateriaPunto::valori())],
            'riunione_id' => ['nullable', 'integer'],
            'verbale' => ['nullable', 'file', 'max:' . DeliberaAMano::KB_MASSIMI_DEL_VERBALE, 'extensions:' . implode(',', DeliberaAMano::ESTENSIONI_DEL_VERBALE)],
            'oggetto_tipo' => ['nullable', 'string', Rule::in(array_keys(SchedaDelCondominio::TIPI)), 'required_with:oggetto_id'],
            'oggetto_id' => ['nullable', 'integer', 'required_with:oggetto_tipo'],
            'parla_di' => ['sometimes', 'array', 'max:10'],
            'parla_di.*.tipo' => ['required', 'string', Rule::in(array_keys(SchedaDelCondominio::TIPI))],
            'parla_di.*.id' => ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'giorno' => 'il giorno della riunione',
            'tipo' => 'ordinaria o straordinaria',
            'ambito' => 'per chi',
            'palazzina_id' => 'la palazzina',
            'scala_id' => 'la scala',
            'testo' => 'che cosa ha deciso',
            'esito' => 'l\'esito',
            'numero_verbale' => 'il numero del verbale',
            'materia' => 'di che cosa parla',
            'riunione_id' => 'la riunione',
            'verbale' => 'il verbale sottoscritto',
            'oggetto_tipo' => 'la pagina da cui nasce',
            'oggetto_id' => 'la pagina da cui nasce',
            'parla_di.*.tipo' => 'ciò di cui parla',
            'parla_di.*.id' => 'ciò di cui parla',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'giorno.required' => 'Scrivi il giorno della riunione.',
            'giorno.before_or_equal' => 'Il giorno della riunione non può essere dopo oggi.',
            'testo.required' => 'Scrivi che cosa ha deciso l\'assemblea.',
            'esito.required' => 'Scegli l\'esito della delibera.',
            'verbale.max' => 'Il verbale sottoscritto è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.',
            'verbale.extensions' => 'Il verbale sottoscritto è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.',
        ];
    }

    /** I dati per il servizio. @return array<string, mixed> */
    public function dati(): array
    {
        return array_intersect_key($this->validated(), array_flip(['giorno', 'tipo', 'ambito', 'palazzina_id', 'scala_id', 'testo', 'esito', 'numero_verbale',
            'materia', 'riunione_id']));
    }

    /** L'origine e ciò di cui parla. @return array<string, mixed>|null */
    public function contesto(): ?array
    {
        $tipo = $this->validated('oggetto_tipo');
        $parla = array_map(fn ($p) => [(string) $p['tipo'], (int) $p['id']], (array) $this->validated('parla_di', []));
        if ($tipo === null && $parla === []) {
            return null;
        }

        return ['oggetto_tipo' => $tipo, 'oggetto_id' => $this->validated('oggetto_id'), 'parla_di' => $parla];
    }
}

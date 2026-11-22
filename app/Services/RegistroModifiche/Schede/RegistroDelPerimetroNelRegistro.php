<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Assemblee\RegistroDelPerimetro;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/**
 * Il registro dei verbali di un perimetro, con il punto di partenza: «Registro dei verbali di Via Roma 12: continua il libro di carta, ultimo numero 41 del
 * 22/10/2026». Compare nella «Storia» del condominio; la scelta si cambia finché il registro non ha un verbale registrato.
 */
final class RegistroDelPerimetroNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = RegistroDelPerimetro::class;

    public const AREA = 'assemblee';

    public const TIPO = 'registro_dei_verbali';

    public const PAROLA = 'Registro dei verbali';

    public const ETICHETTE = [
        'partenza' => 'Da dove parte il registro',
        'ultimo_del_libro' => 'L\'ultimo numero del libro di carta',
        'libro_del' => 'Del',
    ];

    public const FORMATI = [
        'partenza' => ['scelta', [RegistroDelPerimetro::LIBRO => 'Continua il libro di carta', RegistroDelPerimetro::DA_UNO => 'Comincia dal n. 1']],
        'ultimo_del_libro' => 'numero',
        'libro_del' => 'data',
    ];

    /** Il condominio e il perimetro vengono dalla pagina. */
    public const ESCLUSI = ['condominio_id', 'registro', 'palazzina_id', 'scala_id'];

    /** «Registro dei verbali di Via Roma 12», «Registro dei verbali della scala B di Via Roma 12». */
    public function nome(Model $modello): string
    {
        $condominio = (string) (Condominio::query()->whereKey($modello->getAttribute('condominio_id'))->value('nome') ?? '');
        $scala = $modello->getAttribute('scala_id') !== null ? Scala::query()->whereKey($modello->getAttribute('scala_id'))->value('name') : null;
        $palazzina = $modello->getAttribute('palazzina_id') !== null ? Palazzina::query()->whereKey($modello->getAttribute('palazzina_id'))->value('name') : null;
        $parte = $scala !== null ? 'della scala ' . $scala . ' ' : ($palazzina !== null ? 'della palazzina ' . $palazzina . ' ' : '');

        return 'Registro dei verbali ' . $parte . 'di ' . $condominio;
    }

    /** «Continua il libro di carta, ultimo numero 41 del 22/10/2026», «Comincia dal n. 1». */
    public function riepilogo(Model $modello, string $azione): ?string
    {
        $grezzi = $modello->getAttributes();
        if (($grezzi['partenza'] ?? null) === RegistroDelPerimetro::DA_UNO) {
            return $this->nome($modello) . ': comincia dal n. 1';
        }
        $del = FormatoDelValore::testo($grezzi['libro_del'] ?? null, 'data');

        return $this->nome($modello) . ': continua il libro di carta, ultimo numero ' . ($grezzi['ultimo_del_libro'] ?? '—') . ($del !== null ? ' del ' . $del : '');
    }

    public function scheda(Model $modello): array
    {
        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }
}

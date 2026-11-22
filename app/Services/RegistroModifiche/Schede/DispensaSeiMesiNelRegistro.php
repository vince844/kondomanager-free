<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Gestionale\DispensaSeiMesi;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/** Una dispensa dei sei mesi (`DispenseSeiMesi.vue`): compare nella «Storia» del condominio (P22); le persone sono un legame del gesto. */
final class DispensaSeiMesiNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = DispensaSeiMesi::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'dispensa_sei_mesi';

    public const PAROLA = 'Dispensa dei sei mesi';

    public const ETICHETTE = [
        'data_delibera' => 'Data della delibera',
        'numero_verbale' => 'Numero del verbale',
        'nota' => 'Nota',
        'tutte_le_persone' => 'Per chi vale',
        'revocata_il' => 'Data della revoca',
        'nota_revoca' => 'Nota della revoca',
        'togli_sollecito_tesoreria' => 'Togli anche il sollecito in tesoreria',
    ];

    public const FORMATI = [
        'data_delibera' => 'data',
        'tutte_le_persone' => ['scelta', ['1' => 'Per tutte le persone', '0' => 'Solo per alcune persone']],
        'revocata_il' => 'data',
        'togli_sollecito_tesoreria' => 'si_no',
    ];

    public const ESCLUSI = ['condominio_id'];

    public const DEL_PROGRAMMA = ['registrata_da', 'revocata_da'];

    public function nome(Model $modello): string
    {
        $data = FormatoDelValore::testo($modello->getAttributes()['data_delibera'] ?? null, 'data');
        $verbale = trim((string) $modello->getAttribute('numero_verbale'));

        return 'Dispensa dei sei mesi, assemblea del ' . $data . ($verbale !== '' ? ' — verbale n. ' . $verbale : '');
    }

    public function scheda(Model $modello): array
    {
        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }
}

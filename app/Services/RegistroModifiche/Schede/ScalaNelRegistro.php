<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Palazzina;
use App\Models\Scala;
use Illuminate\Database\Eloquent\Model;

/** Una scala (`ScaleEdit.vue`): compare nella «Storia» del condominio (P22). */
final class ScalaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Scala::class;

    public const AREA = 'condominio';

    public const TIPO = 'scala';

    public const PAROLA = 'Scala';

    public const ETICHETTE = [
        'palazzina_id' => 'Palazzina di appartenenza',
        'name' => 'Nome scala',
        'description' => 'Descrizione',
        'note' => 'Note interne',
        // La scelta dell'amministratore per le assemblee (§6.2 del progetto della tappa 2).
        'tabella_proprieta_id' => 'Tabella di proprietà generale',
    ];

    public const FORMATI = [
        'palazzina_id' => ['chiave', Palazzina::class, 'name'],
        'tabella_proprieta_id' => ['chiave', \App\Models\Tabella::class, 'nome'],
    ];

    /** Il condominio viene dall'indirizzo della pagina. */
    public const ESCLUSI = ['condominio_id'];

    public const RIPRISTINABILI = ['name', 'description', 'note'];

    public function nome(Model $modello): string
    {
        return 'Scala ' . $modello->getAttribute('name');
    }

    public function scheda(Model $modello): array
    {
        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }
}

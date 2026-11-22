<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Palazzina;
use Illuminate\Database\Eloquent\Model;

/** Una palazzina (`PalazzineEdit.vue`): compare nella «Storia» del condominio (P22). */
final class PalazzinaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Palazzina::class;

    public const AREA = 'condominio';

    public const TIPO = 'palazzina';

    public const PAROLA = 'Palazzina';

    public const ETICHETTE = [
        'name' => 'Nome palazzina',
        'description' => 'Descrizione',
        'note' => 'Note interne',
        // La scelta dell'amministratore per le assemblee (§6.2 del progetto della tappa 2).
        'tabella_proprieta_id' => 'Tabella di proprietà generale',
    ];

    public const FORMATI = [
        'tabella_proprieta_id' => ['chiave', \App\Models\Tabella::class, 'nome'],
    ];

    /** Il condominio viene dall'indirizzo della pagina. */
    public const ESCLUSI = ['condominio_id'];

    public const RIPRISTINABILI = ['name', 'description', 'note'];

    public function nome(Model $modello): string
    {
        return 'Palazzina ' . $modello->getAttribute('name');
    }

    public function scheda(Model $modello): array
    {
        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }
}

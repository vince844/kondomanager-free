<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;

/** Il piano dei conti (`PianiDeiContiEdit.vue`). */
final class PianoContoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = PianoConto::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'piano_conti';

    public const PAROLA = 'Piano dei conti';

    public const ETICHETTE = [
        'gestione_id' => 'Gestione',
        'nome' => 'Nome piano dei conti',
        'descrizione' => 'Descrizione',
        'note' => 'Note',
    ];

    public const FORMATI = [
        'gestione_id' => ['chiave', Gestione::class, 'nome'],
    ];

    public const ESCLUSI = ['condominio_id'];

    public function nome(Model $modello): string
    {
        return 'Piano dei conti «' . $modello->getAttribute('nome') . '»';
    }
}

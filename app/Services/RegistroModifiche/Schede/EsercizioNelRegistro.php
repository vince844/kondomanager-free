<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Esercizio;
use Illuminate\Database\Eloquent\Model;

/** L'esercizio (`EserciziEdit.vue`). In contabilità e negli esercizi «Ripristina» non c'è: si cambia dalla scheda. */
final class EsercizioNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Esercizio::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'esercizio';

    public const PAROLA = 'Esercizio';

    public const ETICHETTE = [
        'nome' => 'Nome',
        'descrizione' => 'Descrizione',
        'data_inizio' => 'Data inizio',
        'data_fine' => 'Data fine',
        'stato' => 'Stato',
        'note' => 'Note',
    ];

    public const FORMATI = [
        'data_inizio' => 'data',
        'data_fine' => 'data',
        'stato' => ['scelta', ['aperto' => 'Aperto', 'chiuso' => 'Chiuso']],
    ];

    public const ESCLUSI = ['condominio_id'];

    public function nome(Model $modello): string
    {
        return 'Esercizio «' . $modello->getAttribute('nome') . '»';
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;

/** La gestione (`GestioniEdit.vue`); le voci compaiono nella sua «Storia» (P22). */
final class GestioneNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Gestione::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'gestione';

    public const PAROLA = 'Gestione';

    public const ETICHETTE = [
        'nome' => 'Nome gestione',
        'descrizione' => 'Descrizione',
        'tipo' => 'Tipologia',
        'data_inizio' => 'Data inizio',
        'data_fine' => 'Data fine',
        'note' => 'Note',
    ];

    public const FORMATI = [
        'tipo' => ['scelta', ['ordinaria' => 'Ordinaria', 'straordinaria' => 'Straordinaria']],
        'data_inizio' => 'data',
        'data_fine' => 'data',
    ];

    /** Il condominio dall'indirizzo; «attiva» la forza il programma (nessuna pagina la scrive). */
    public const ESCLUSI = ['condominio_id'];

    /** Il lucchetto dei saldi e la sua nota. */
    public const DEL_PROGRAMMA = ['attiva', 'saldo_applicato', 'nota_saldo'];

    public function nome(Model $modello): string
    {
        return 'Gestione «' . $modello->getAttribute('nome') . '»';
    }
}

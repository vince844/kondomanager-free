<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;

/** La tabella millesimale, intestazione (`TabelleEdit.vue`): le quote sono il gesto del salvataggio dei millesimi. */
final class TabellaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Tabella::class;

    public const AREA = 'millesimi';

    public const TIPO = 'tabella';

    public const PAROLA = 'Tabella';

    public const ETICHETTE = [
        'nome' => 'Nome tabella',
        'tipo' => 'Tipologia',
        'quota' => 'Unità di misura',
        'numero_decimali' => 'Numero decimali',
        'descrizione' => 'Descrizione',
        'note' => 'Note',
        'di_un_gruppo' => 'Tabella di un gruppo (assemblee)',
    ];

    public const FORMATI = [
        'tipo' => ['scelta', ['standard' => 'Standard', 'ascensore' => 'Ascensore', 'scale' => 'Scale', 'riscaldamento' => 'Riscaldamento',
            'acqua' => 'Acqua', 'lastrico' => 'Lastrico', 'speciale' => 'Speciale', 'altro' => 'Altro']],
        'quota' => ['scelta', ['millesimi' => 'Millesimi', 'persone' => 'Persone', 'quote' => 'Quote', 'kwatt' => 'Kilowatt', 'mtcubi' => 'Metri cubi']],
        'numero_decimali' => 'numero',
        'di_un_gruppo' => 'si_no',
    ];

    /** Non in pagina: il condominio dall'indirizzo, palazzina e scala (il riquadro è caduto), le regole di calcolo, la data di fine. */
    public const ESCLUSI = ['condominio_id', 'palazzina_id', 'scala_id', 'regole_calcolo', 'data_fine'];

    /** Vera e con il giorno della creazione, scritte alla creazione. */
    public const DEL_PROGRAMMA = ['attiva', 'data_inizio'];

    public function nome(Model $modello): string
    {
        return 'Tabella «' . $modello->getAttribute('nome') . '»';
    }
}

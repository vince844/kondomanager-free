<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Condominio;
use App\Models\ModelloTesto;
use Illuminate\Database\Eloquent\Model;

/** Un modello di lettera o un testo della nota sintetica (`ModelliLettere.vue`): compare nella «Storia» delle impostazioni delle stampe (P22). */
final class ModelloTestoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = ModelloTesto::class;

    public const AREA = 'impostazioni';

    public const TIPO = 'modello_testo';

    public const PAROLA = 'Modello';

    public const ETICHETTE = [
        'tipo' => 'Tipo',
        'nome' => 'Nome del modello',
        'testo' => 'Testo',
        'condominio_id' => 'Vale per',
    ];

    public const FORMATI = [
        'tipo' => ['scelta', ['promemoria' => 'Promemoria', 'sollecito' => 'Sollecito', 'diffida' => 'Diffida', 'messa_in_mora' => 'Messa in mora',
            'foglio_conguaglio' => 'Email del foglio del conguaglio', 'verbale' => 'Il verbale dell\'assemblea']],
        'condominio_id' => ['chiave', Condominio::class, 'nome'],
    ];

    /** Dipende dalla pagina da cui si salva. */
    public const DEL_PROGRAMMA = ['ambito'];

    public function nome(Model $modello): string
    {
        return 'Modello «' . $modello->getAttribute('nome') . '»';
    }

    public function scheda(Model $modello): array
    {
        return ['impostazioni_stampe', null];
    }
}

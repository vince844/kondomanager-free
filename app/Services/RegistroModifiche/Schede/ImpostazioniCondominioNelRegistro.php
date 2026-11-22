<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\ImpostazioniCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Le impostazioni del condominio (`ImpostazioniCondominio.vue`; le scelte sugli eredi in `RegoleRecupero.vue`, con le parole di
 * `RegoleDegliEredi.php`). Non ripristinabili (P3: validate in linea nel controller).
 */
final class ImpostazioniCondominioNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = ImpostazioniCondominio::class;

    public const AREA = 'impostazioni';

    public const TIPO = 'impostazioni_condominio';

    public const PAROLA = 'Impostazioni del condominio';

    public const ETICHETTE = [
        'recupero_salita' => 'Una rata nuova non fa tornare indietro',
        'recupero_suggerisce_azione' => 'Il programma suggerisce la prossima azione',
        'eredi_interessi_sugli_interessi' => 'Interessi anche sugli interessi già addebitati al defunto',
        'eredi_dal_decesso' => 'Partono dal giorno del decesso',
        'eredi_tolleranza_rata_nuova' => 'Il debito ereditato ha gli «Interessi dopo la scadenza» della rata nuova',
        'eredi_rata_per_rata' => 'Su più rate, scende rata per rata',
        'eredi_versamento_prima' => 'Un versamento dell\'erede copre prima il debito ereditato',
        'eredi_anche_accordo' => 'Anche il debito dell\'accordo di rientro in regola',
        'eredi_buco_continuano' => 'Con «continuano a suo nome», gli eredi coprono il buco fino alla scadenza',
        'eredi_interessi_defunto_agli_eredi' => 'Gli interessi del defunto registrati dopo vanno agli eredi',
        'assemblee_teste' => 'Le teste nel caso misto',
    ];

    public const FORMATI = [
        'recupero_salita' => ['scelta', ['avanti' => 'Acceso', 'ricomincia' => 'Spento']],
        'recupero_suggerisce_azione' => 'si_no',
        'eredi_interessi_sugli_interessi' => 'si_no',
        'eredi_dal_decesso' => 'si_no',
        'eredi_tolleranza_rata_nuova' => 'si_no',
        'eredi_rata_per_rata' => 'si_no',
        'eredi_versamento_prima' => 'si_no',
        'eredi_anche_accordo' => 'si_no',
        'eredi_buco_continuano' => 'si_no',
        'eredi_interessi_defunto_agli_eredi' => 'si_no',
        'assemblee_teste' => ['scelta', ['una_testa' => 'Una testa per le persone legate da una comproprietà', 'due_teste' => 'La persona e l\'unità in comproprietà a parte']],
    ];

    public const ESCLUSI = ['condominio_id'];

    /** Chi ha salvato: la riga stessa lo dice. */
    public const DEL_PROGRAMMA = ['aggiornate_da'];

    public function nome(Model $modello): string
    {
        return 'Impostazioni del condominio';
    }
}

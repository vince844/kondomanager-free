<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;

/** Il piano rate, intestazione, delibera e verbale (`PianiRateNew.vue`, `PianiRateShow.vue`): le rate e le quote sono il gesto della generazione. */
final class PianoRateNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = PianoRate::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'piano_rate';

    public const PAROLA = 'Piano rate';

    public const ETICHETTE = [
        'gestione_id' => 'Gestione di riferimento',
        'nome' => 'Nome identificativo',
        'descrizione' => 'Descrizione / note esterne',
        'numero_rate' => 'Numero rate',
        'giorno_scadenza' => 'Giorno del mese',
        'metodo_distribuzione' => 'Metodo di distribuzione',
        'stato' => 'Stato',
        'data_delibera_assemblea' => 'Data della delibera assembleare',
        'numero_verbale' => 'N. verbale',
        'nota_approvazione' => 'Note o riferimenti',
        'tipo' => 'Che cosa mettere a rate',
        'tipo_autorizzazione' => 'Tipo autorizzazione',
        'motivazione_autorizzazione' => 'Motivazione / estremi verbale',
        'giorno_dei_lavori' => 'Lavori ordinati il',
        'nota_scoperti' => 'Nota delle quote non assegnate',
        'data_prima_scadenza' => 'Prima scadenza',
    ];

    public const FORMATI = [
        'gestione_id' => ['chiave', Gestione::class, 'nome'],
        'numero_rate' => 'numero',
        'giorno_scadenza' => 'numero',
        'metodo_distribuzione' => ['scelta', ['rata_zero' => 'Crea rata separata (Rata 0)', 'prima_rata' => 'Somma alla prima rata ordinaria',
            'tutte_rate' => 'Spalma matematicamente su tutte le rate']],
        'stato' => ['scelta', ['bozza' => 'Bozza', 'approvato' => 'Approvato']],
        'data_delibera_assemblea' => 'data',
        'tipo' => ['scelta', ['ordinario' => 'Dalle voci del preventivo', 'straordinario' => 'Spese fuori preventivo']],
        'tipo_autorizzazione' => ['scelta', ['delibera' => 'Delibera assembleare', 'urgenza' => 'Urgenza (art. 1135 c.c.)']],
        'data_prima_scadenza' => 'data',
        'giorno_dei_lavori' => 'data',
    ];

    /** Non in pagina: il condominio dall'indirizzo, le note (nel modulo ma senza campo), la scelta dei saldi (JSON senza parola: la riga del gesto la dice). */
    public const ESCLUSI = ['condominio_id', 'note', 'saldi_config'];

    /** Chi ha approvato e quando (la riga stessa li dice), il contesto, i saldi alla creazione, l'esercizio della pagina, le quote non assegnate, la natura. */
    public const DEL_PROGRAMMA = ['attivo', 'approvato_da_user_id', 'approvato_il', 'contesto_creazione', 'applica_saldi', 'esercizio_id',
        'quote_non_assegnate', 'natura'];

    public function nome(Model $modello): string
    {
        return 'Piano rate «' . $modello->getAttribute('nome') . '»';
    }
}

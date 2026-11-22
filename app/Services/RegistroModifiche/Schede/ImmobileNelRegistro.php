<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Immobile;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\TipologiaImmobile;
use Illuminate\Database\Eloquent\Model;

/** L'unità immobiliare (`ImmobiliEdit.vue`), nominata come le pagine la nominano: «Unità Int. 7» (`Immobile::etichetta`). */
final class ImmobileNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Immobile::class;

    public const AREA = 'condominio';

    public const TIPO = 'immobile';

    public const PAROLA = 'Unità';

    public const ETICHETTE = [
        'palazzina_id' => 'Palazzina',
        'scala_id' => 'Scala',
        'tipologia_id' => 'Tipologia',
        'nome' => 'Nome immobile',
        'descrizione' => 'Descrizione',
        'interno' => 'Interno',
        'piano' => 'Piano',
        'superficie' => 'Superficie (m²)',
        'numero_vani' => 'Numero vani',
        'comune_catasto' => 'Comune catastale',
        'codice_catasto' => 'Codice catastale',
        'sezione_catasto' => 'Sezione',
        'foglio_catasto' => 'Foglio',
        'particella_catasto' => 'Particella',
        'subalterno_catasto' => 'Subalterno',
        'note' => 'Note riservate',
        'pertinenza_di_immobile_id' => 'Pertinenza di',
        'pertinenza_di_esterna' => 'Qual è l\'unità principale',
    ];

    public const FORMATI = [
        'palazzina_id' => ['chiave', Palazzina::class, 'name'],
        'scala_id' => ['chiave', Scala::class, 'name'],
        'tipologia_id' => ['chiave', TipologiaImmobile::class, 'nome'],
        'superficie' => 'numero',
        'numero_vani' => 'numero',
        'pertinenza_di_immobile_id' => ['chiave', Immobile::class, 'etichetta'],
    ];

    /** Il condominio viene dall'indirizzo della pagina; «attivo» non lo scrive nessuna pagina. */
    public const ESCLUSI = ['condominio_id', 'attivo'];

    /** Il codice dell'unità lo genera il programma. */
    public const DEL_PROGRAMMA = ['codice_immobile'];

    public const RIPRISTINABILI = ['nome', 'descrizione', 'interno', 'piano', 'superficie', 'numero_vani', 'comune_catasto', 'codice_catasto',
        'sezione_catasto', 'foglio_catasto', 'particella_catasto', 'subalterno_catasto', 'note'];

    public function nome(Model $modello): string
    {
        return 'Unità ' . $modello->getAttribute('etichetta');
    }
}

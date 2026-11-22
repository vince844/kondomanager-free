<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Gestionale\RegolaRecupero;
use Illuminate\Database\Eloquent\Model;

/**
 * Una regola del recupero dei crediti (`RegoleRecupero.vue`): «una regola non si riscrive», se ne scrive una nuova. Tutte le regole di un condominio
 * compaiono in una «Storia» sola, quella delle regole del condominio (`regole_recupero`, con l'id del condominio). Non ripristinabile (P3).
 */
final class RegolaRecuperoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = RegolaRecupero::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'regola_recupero';

    public const PAROLA = 'Regole del recupero dei crediti';

    public const TIPO_DELLA_SCHEDA = 'regole_recupero';

    public const ETICHETTE = [
        'dal' => 'Da quando vale',
        'tasso_tipo' => 'Il tasso degli interessi di mora',
        'tasso_annuo' => 'Tasso degli interessi di mora',
        'fonte' => 'Da dove viene',
        'riferimento' => 'Il riferimento',
        'giorni_decorrenza' => 'Interessi dopo la scadenza (giorni)',
        'giorni_tolleranza' => 'Tolleranza prima del sollecito (giorni)',
        'termine_lettere_giorni' => 'Termine scritto nelle lettere (giorni)',
    ];

    public const FORMATI = [
        'dal' => 'data',
        'tasso_tipo' => ['scelta', ['legale' => 'Il tasso legale dell\'anno', 'scritto' => 'Un tasso scritto, con la sua fonte']],
        'tasso_annuo' => 'percentuale',
        'fonte' => ['scelta', ['regolamento' => 'regolamento', 'delibera' => 'delibera dell\'assemblea', 'altro' => 'altro']],
        'giorni_decorrenza' => 'numero',
        'giorni_tolleranza' => 'numero',
        'termine_lettere_giorni' => 'numero',
    ];

    public const ESCLUSI = ['condominio_id'];

    /** Chi l'ha scritta: la riga stessa lo dice. */
    public const DEL_PROGRAMMA = ['creata_da'];

    public function nome(Model $modello): string
    {
        return 'Regole del recupero dei crediti';
    }

    public function scheda(Model $modello): array
    {
        return [self::TIPO_DELLA_SCHEDA, (int) $modello->getAttribute('condominio_id')];
    }
}

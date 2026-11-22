<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\EsitoSeduta;
use App\Models\Assemblee\SedutaDellAssemblea;
use Illuminate\Database\Eloquent\Model;

/**
 * Una data dell'avviso (§6.1): le date si cambiano una per una, e la riga compare nella «Storia» dell'assemblea (P22). La sala scrive l'apertura, la
 * chiusura, l'esito, il presidente e il segretario dentro i suoi gesti, una riga per gesto; la fotografia e la sua impronta sono del programma.
 */
final class SedutaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = SedutaDellAssemblea::class;

    public const AREA = 'assemblee';

    public const TIPO = 'seduta';

    public const PAROLA = 'Seduta';

    public const ETICHETTE = [
        'convocazione' => 'Convocazione',
        'numero' => 'Riunione',
        'fissata_il' => 'Il giorno e l\'ora',
        'luogo' => 'Dove',
        'aperta_il' => 'Aperta alle',
        'chiusa_il' => 'Chiusa alle',
        'esito' => 'Esito della seduta',
        'presidente' => 'Presidente',
        'segretario' => 'Segretario',
        'apertura' => 'Il controllo dell\'apertura',
        'dal_foglio' => 'Registrata dal foglio della sala',
        'nel_libro_il' => 'Annotazione incollata nel libro dei verbali il',
    ];

    public const FORMATI = [
        'convocazione' => ['scelta', [ConvocazioneSeduta::PRIMA->value => 'Prima', ConvocazioneSeduta::SECONDA->value => 'Seconda']],
        'numero' => 'numero',
        'fissata_il' => 'ora_letta',
        'aperta_il' => 'data_ora',
        'chiusa_il' => 'data_ora',
        'esito' => ['scelta', [EsitoSeduta::DESERTA->value => 'Deserta', EsitoSeduta::TENUTA->value => 'Tenuta', EsitoSeduta::SOSPESA->value => 'Sospesa',
            EsitoSeduta::RINVIATA->value => 'Rinviata']],
        'dal_foglio' => 'si_no',
        'nel_libro_il' => 'data',
    ];

    /** L'assemblea viene dall'indirizzo della pagina (§13, rilievo 23). */
    public const ESCLUSI = ['assemblea_id'];

    /** La fotografia della seduta e la sua impronta: le scrive il programma all'apertura, e la riga del gesto le ricorda nel lotto. */
    public const DEL_PROGRAMMA = ['fotografia', 'impronta_fotografia'];

    /** Il presidente, il segretario e il motivo del presidente nominano persone. */
    public const DATI_DELLA_PERSONA = ['presidente', 'segretario', 'apertura'];

    /** «Seconda convocazione», «Prima convocazione, riunione 2». */
    public function nome(Model $modello): string
    {
        $grezzi = $modello->getAttributes();
        $convocazione = ($grezzi['convocazione'] ?? null) === ConvocazioneSeduta::SECONDA->value ? 'Seconda convocazione' : 'Prima convocazione';
        $numero = (int) ($grezzi['numero'] ?? 1);

        return $convocazione . ($numero > 1 ? ', riunione ' . $numero : '');
    }

    public function condomini(Model $modello): array
    {
        return AssembleeNelRegistroComune::condominiDellAssemblea($modello->getAttribute('assemblea_id'));
    }

    public function scheda(Model $modello): array
    {
        return [AssembleaNelRegistro::TIPO, (int) $modello->getAttribute('assemblea_id')];
    }
}

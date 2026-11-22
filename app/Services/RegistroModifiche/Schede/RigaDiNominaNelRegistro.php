<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\EventoNomina;
use App\Enums\Assemblee\StatoNomina;
use App\Models\Assemblee\RigaDiNomina;
use App\Models\Documento;
use App\Models\User;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/**
 * Una riga del registro di nomina e revoca dell'amministratore (art. 1130 n. 7 c.c.): compare nella «Storia» del condominio. La riga proposta dalla delibera
 * nasce «Proposta» con il verbale registrato; la conferma, la correzione e l'annullamento con il perché sono righe del registro delle modifiche.
 */
final class RigaDiNominaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = RigaDiNomina::class;

    public const AREA = 'assemblee';

    public const TIPO = 'riga_di_nomina';

    public const PAROLA = 'Nomina e revoca';

    public const ETICHETTE = [
        'evento' => 'Che cosa',
        'amministratore' => 'Amministratore',
        'dal' => 'Dal',
        'da_dove' => 'Da dove viene',
        'estremi' => 'Gli estremi',
        'delibera_id' => 'La delibera',
        'verbale_id' => 'Il verbale',
        'decreto' => 'Il decreto',
        'documento_id' => 'Il documento',
        'nota' => 'La nota',
        'stato' => 'Stato',
        'confermata_il' => 'Confermata il',
        'confermata_da' => 'Confermata da',
        'annullata_il' => 'Annullata il',
        'annullata_motivo' => 'Perché è annullata',
        'annullata_da' => 'Annullata da',
    ];

    public const FORMATI = [
        'evento' => ['scelta', [EventoNomina::NOMINA->value => 'Nomina', EventoNomina::REVOCA->value => 'Revoca']],
        'dal' => 'data',
        'da_dove' => ['scelta', [RigaDiNomina::DA_CARTA => 'Una delibera su carta', RigaDiNomina::DA_VERBALE => 'Un verbale registrato qui',
            RigaDiNomina::DA_DECRETO => 'Un decreto del tribunale']],
        'delibera_id' => ['chiave', \App\Models\Assemblee\Delibera::class, 'testo'],
        'verbale_id' => ['chiave', \App\Models\Assemblee\VerbaleDellAssemblea::class, 'numero'],
        'documento_id' => ['chiave', Documento::class, 'name'],
        'stato' => ['scelta', [StatoNomina::PROPOSTA->value => 'Proposta', StatoNomina::CONFERMATA->value => 'Confermata', StatoNomina::ANNULLATA->value => 'Annullata']],
        'confermata_il' => 'data_ora',
        'confermata_da' => ['chiave', User::class, 'name'],
        'annullata_il' => 'data_ora',
        'annullata_da' => ['chiave', User::class, 'name'],
    ];

    /** Il condominio viene dalla pagina. */
    public const ESCLUSI = ['condominio_id'];

    /** Il nome dell'amministratore, gli estremi, la nota e il perché dell'annullamento nominano persone. */
    public const DATI_DELLA_PERSONA = ['amministratore', 'estremi', 'nota', 'annullata_motivo', 'decreto'];

    /** «Nomina di Andrea Costa dal 15/12/2026». */
    public function nome(Model $modello): string
    {
        $grezzi = $modello->getAttributes();
        $evento = ($grezzi['evento'] ?? null) === EventoNomina::REVOCA->value ? 'Revoca' : 'Nomina';
        $dal = FormatoDelValore::testo($grezzi['dal'] ?? null, 'data');

        return $evento . ' di ' . ($grezzi['amministratore'] ?? '') . ($dal !== null ? ' dal ' . $dal : '');
    }

    public function scheda(Model $modello): array
    {
        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }
}

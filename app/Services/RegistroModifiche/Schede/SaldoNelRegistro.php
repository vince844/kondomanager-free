<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Anagrafica;
use App\Models\Gestione;
use App\Models\Gestionale\Subentro;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\Saldo;
use Illuminate\Database\Eloquent\Model;

/**
 * Un saldo iniziale (P4: `SaldiList.vue`, `SaldiDetailPanel.vue`, `SaldoInizialeController`): compare nella «Storia» dei saldi dell'esercizio. Non
 * ripristinabile; le colonne che scrive il programma restano escluse.
 */
final class SaldoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Saldo::class;

    public const AREA = 'contabilita';

    public const TIPO = 'saldo';

    public const PAROLA = 'Saldo iniziale';

    public const TIPO_DELLA_SCHEDA = 'saldi';

    public const ETICHETTE = [
        'anagrafica_id' => 'A chi è assegnato',
        'saldo_iniziale' => 'Importo',
        'gestione_id' => 'Gestione',
        'fornitore_id' => 'Fornitore',
        'descrizione' => 'Descrizione pendenza',
        'passaggio_id' => 'Legato a un passaggio',
        'esercizio_origine_id' => 'Esercizio di origine del pregresso',
        'origine_dichiarata' => 'Origine del pregresso',
    ];

    public const FORMATI = [
        'anagrafica_id' => ['chiave', Anagrafica::class, 'nome'],
        'saldo_iniziale' => 'euro',
        'gestione_id' => ['chiave', Gestione::class, 'nome'],
        'fornitore_id' => ['chiave', \App\Models\Fornitore::class, 'ragione_sociale'],
        'passaggio_id' => ['chiave', Subentro::class, 'id'],
        'esercizio_origine_id' => ['chiave', Esercizio::class, 'nome'],
        'origine_dichiarata' => ['scelta', ['esercizio' => 'Esercizio', 'con_il_piano' => 'Dovuto con questo piano', 'prima_del_primo' => 'Prima del primo esercizio']],
    ];

    /** P4: l'esercizio aperto, il condominio, l'unità della scheda, l'origine, il lucchetto, il piano e il passaggio che l'hanno scritto, chi ha dichiarato. */
    public const DEL_PROGRAMMA = ['esercizio_id', 'condominio_id', 'immobile_id', 'origine', 'is_applicato', 'piano_rate_id', 'subentro_id',
        'origine_dichiarata_da', 'origine_dichiarata_il'];

    public function nome(Model $modello): string
    {
        $unita = Immobile::query()->find($modello->getAttribute('immobile_id'));
        $persona = Anagrafica::query()->whereKey($modello->getAttribute('anagrafica_id'))->value('nome');
        $fornitore = \App\Models\Fornitore::query()->whereKey($modello->getAttribute('fornitore_id'))->value('ragione_sociale');
        $di = $unita !== null ? $unita->etichetta . ($persona !== null ? ' (' . $persona . ')' : ' (intero immobile)') : ($fornitore ?? $persona ?? '');

        return 'Saldo iniziale di ' . $di;
    }

    public function scheda(Model $modello): array
    {
        return [self::TIPO_DELLA_SCHEDA, $modello->getAttribute('esercizio_id') === null ? null : (int) $modello->getAttribute('esercizio_id')];
    }

    public function soggetti(Model $modello): array
    {
        $soggetti = [];
        if ($modello->getAttribute('anagrafica_id') !== null) {
            $soggetti[] = ['anagrafica', (int) $modello->getAttribute('anagrafica_id')];
        }
        if ($modello->getAttribute('fornitore_id') !== null) {
            $soggetti[] = ['fornitore', (int) $modello->getAttribute('fornitore_id')];
        }

        return $soggetti;
    }
}

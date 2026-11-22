<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Anagrafica;
use App\Models\Gestionale\AccordoRientro;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/** Un accordo di rientro (`AccordoRientroDialog.vue`): nella «Storia» del condominio e in quella della persona (P22). */
final class AccordoRientroNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = AccordoRientro::class;

    public const AREA = 'piani_rate';

    public const TIPO = 'accordo_rientro';

    public const PAROLA = 'Accordo di rientro';

    public const ETICHETTE = [
        'data_accordo' => 'Data dell\'accordo',
        'fino_al' => 'Fino al',
        'autorizzato_assemblea' => 'Autorizzato dall\'assemblea',
        'data_autorizzazione' => 'Data della delibera',
        'verbale_autorizzazione' => 'Verbale n.',
        'nota' => 'Nota',
        'chiuso_il' => 'Chiuso il',
        'nota_chiusura' => 'Nota della chiusura',
        'decadenza_rate' => 'Rate del calendario non versate per la decadenza',
        'decadenza_giorni' => 'Giorni dopo una rata non versata per la decadenza',
        'decadenza_registrata_il' => 'Decadenza dal',
        'interessi_parte_fuori' => 'Sulla parte della rata fuori dall\'accordo',
        'chiusura_per_successione' => 'L\'accordo di rientro del defunto',
    ];

    public const FORMATI = [
        'data_accordo' => 'data',
        'fino_al' => 'data',
        'autorizzato_assemblea' => 'si_no',
        'data_autorizzazione' => 'data',
        'chiuso_il' => 'data',
        'decadenza_rate' => 'numero',
        'decadenza_giorni' => 'numero',
        'decadenza_registrata_il' => 'data',
        'interessi_parte_fuori' => ['scelta', ['continuano' => 'gli interessi continuano', 'sospesi' => 'restano sospesi finché l\'accordo regge']],
        'chiusura_per_successione' => ['scelta', ['in_regola' => 'resta in regola fino al decesso', 'decadenza' => 'vale come una decadenza']],
    ];

    /** La persona è quella del titolo dell'accordo (nel nome della riga e fra i soggetti). */
    public const ESCLUSI = ['condominio_id', 'anagrafica_id'];

    /** Chi l'ha registrato e chiuso, i segnali della decadenza, la storia delle scelte (ha già il suo registro). */
    public const DEL_PROGRAMMA = ['registrato_da', 'chiuso_da', 'segnali', 'registro'];

    public const DATI_DELLA_PERSONA = ['nota', 'nota_chiusura'];

    public const NELLA_STORIA_DEI_SOGGETTI = true;

    public function nome(Model $modello): string
    {
        $persona = Anagrafica::query()->whereKey($modello->getAttribute('anagrafica_id'))->value('nome');
        $data = FormatoDelValore::testo($modello->getAttributes()['data_accordo'] ?? null, 'data');

        return 'Accordo di rientro di ' . $persona . ($data !== null ? ' del ' . $data : '');
    }

    public function scheda(Model $modello): array
    {
        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }

    public function soggetti(Model $modello): array
    {
        return [['anagrafica', (int) $modello->getAttribute('anagrafica_id')]];
    }
}

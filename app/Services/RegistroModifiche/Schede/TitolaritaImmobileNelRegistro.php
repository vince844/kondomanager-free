<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/**
 * La titolarità di una persona su un'unità (pivot con modello, `AnagraficheEdit.vue` dell'unità): «collegata» e «scollegata» nella «Storia» dell'unità,
 * con la persona fra i soggetti, così compare anche nella sua (la bozza: «All'unità Int. 7, proprietaria al 100 % dal 01/01/2019»).
 */
final class TitolaritaImmobileNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = TitolaritaImmobile::class;

    public const AREA = 'condominio';

    public const TIPO = 'titolarita';

    public const PAROLA = 'Titolarità';

    public const RUOLI = ['proprietario' => 'Proprietario', 'nuda_proprietario' => 'Nudo proprietario', 'usufruttuario' => 'Usufruttuario',
        'inquilino' => 'Inquilino'];

    public const ETICHETTE = [
        'anagrafica_id' => 'Anagrafica associata',
        'tipologia' => 'Tipologia',
        'quota' => 'Quota competenza (%)',
        'data_inizio' => 'Data inizio competenza',
        'data_fine' => 'Data fine competenza',
        'note' => 'Note interne',
    ];

    public const FORMATI = [
        'anagrafica_id' => ['chiave', Anagrafica::class, 'nome'],
        'tipologia' => ['scelta', self::RUOLI],
        'quota' => 'quota',
        'data_inizio' => 'data',
        'data_fine' => 'data',
    ];

    /** L'unità viene dall'indirizzo della pagina. */
    public const ESCLUSI = ['immobile_id'];

    /** Sempre vera dal modulo. */
    public const DEL_PROGRAMMA = ['attivo'];

    public const DATI_DELLA_PERSONA = ['note'];

    public const NELLA_STORIA_DEI_SOGGETTI = true;

    public function nome(Model $modello): string
    {
        $persona = Anagrafica::query()->whereKey($modello->getAttribute('anagrafica_id'))->value('nome');
        $unita = Immobile::query()->find($modello->getAttribute('immobile_id'));

        return 'Titolarità di ' . $persona . ' sull\'unità ' . ($unita?->etichetta ?? '');
    }

    public function condomini(Model $modello): array
    {
        $unita = Immobile::query()->find($modello->getAttribute('immobile_id'));

        return $unita === null ? [] : (new ImmobileNelRegistro())->condomini($unita);
    }

    public function scheda(Model $modello): array
    {
        return [ImmobileNelRegistro::TIPO, (int) $modello->getAttribute('immobile_id')];
    }

    public function soggetti(Model $modello): array
    {
        return [['anagrafica', (int) $modello->getAttribute('anagrafica_id')]];
    }

    public function azioneDi(string $evento): string
    {
        return match ($evento) {
            'creata' => 'collegata',
            'eliminata' => 'scollegata',
            default => $evento,
        };
    }

    public function riepilogo(Model $modello, string $azione): ?string
    {
        if (! in_array($azione, ['collegata', 'scollegata'], true)) {
            return null;
        }
        $unita = Immobile::query()->find($modello->getAttribute('immobile_id'));
        $ruolo = self::RUOLI[(string) $modello->getAttribute('tipologia')] ?? (string) $modello->getAttribute('tipologia');
        $quota = FormatoDelValore::testo($modello->getAttribute('quota'), 'quota');
        $dal = FormatoDelValore::testo($modello->getRawOriginal('data_inizio') ?? $modello->getAttributes()['data_inizio'] ?? null, 'data');
        $testo = ($azione === 'collegata' ? 'All\'unità ' : 'Dall\'unità ') . ($unita?->etichetta ?? '') . ': ' . mb_strtolower($ruolo)
            . ($quota !== null ? ' al ' . $quota : '') . ($dal !== null ? ' dal ' . $dal : '');

        return $testo . '.';
    }
}

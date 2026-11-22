<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\StatoMemo;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\User;
use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/**
 * Un memo «Da portare in assemblea» (§6.1 e §6.2): compare nella «Storia» della sua origine (la fattura n. 45), o in quella del condominio se l'origine non
 * ha una «Storia». Il testo, la nota dello studio e il nome dell'origine copiato sono dati della persona (§13, rilievo 18).
 */
final class MemoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = MemoDellAssemblea::class;

    public const AREA = 'assemblee';

    public const TIPO = 'memo_assemblea';

    public const PAROLA = 'Memo per l\'assemblea';

    public const ETICHETTE = [
        'ambito' => 'Per chi',
        'palazzina_id' => 'Palazzina',
        'scala_id' => 'Scala',
        'testo' => 'Il punto',
        'nota' => 'La nota dello studio',
        'origine_nome' => 'Da dove nasce',
        'assemblea_id' => 'Per quale assemblea',
        'ricordami_dal' => 'Ricordamelo dal',
        'a_chi' => 'A chi',
        'assegnato_a' => 'Il collaboratore',
        'stato' => 'Stato',
        'punto_id' => 'Nel punto',
        'chiuso_motivo' => 'Perché non serve più',
    ];

    public const FORMATI = [
        'ambito' => ['scelta', [AmbitoAssemblea::CONDOMINIO->value => 'Tutto il condominio', AmbitoAssemblea::PALAZZINA->value => 'La palazzina',
            AmbitoAssemblea::SCALA->value => 'La scala']],
        'palazzina_id' => ['chiave', Palazzina::class, 'name'],
        'scala_id' => ['chiave', Scala::class, 'name'],
        'assemblea_id' => ['chiave', Assemblea::class, '@nome'],
        'ricordami_dal' => 'data',
        'a_chi' => ['scelta', [AChiMemo::ME->value => 'A me', AChiMemo::STUDIO->value => 'A tutto lo studio', AChiMemo::COLLABORATORE->value => 'A un collaboratore']],
        'assegnato_a' => ['chiave', User::class, 'name'],
        'stato' => ['scelta', [StatoMemo::IN_ATTESA->value => 'In attesa', StatoMemo::NEL_PUNTO->value => 'Nell\'ordine del giorno',
            StatoMemo::DELIBERATO->value => 'Deliberato', StatoMemo::NON_SERVE->value => 'Non serve più']],
        'punto_id' => ['chiave', PuntoDellOrdineDelGiorno::class, 'testo'],
    ];

    /** Il condominio viene dalla pagina (§13, rilievo 23). */
    public const ESCLUSI = ['condominio_id'];

    /** L'origine (il suo nome copiato sta in «Da dove nasce»), il compito e il giorno della chiusura li scrive il programma. */
    public const DEL_PROGRAMMA = ['origine_tipo', 'origine_id', 'evento_id', 'chiuso_il'];

    public const DATI_DELLA_PERSONA = ['testo', 'nota', 'origine_nome'];

    public function nome(Model $modello): string
    {
        return 'Memo per l\'assemblea';
    }

    /** «Creata: «Ratifica della spesa oltre il preventivo di € 1.800,00…», ricordamelo dal 25/11/2026, a te» (§6.3). */
    public function riepilogo(Model $modello, string $azione): ?string
    {
        if ($azione !== 'creata') {
            return null;
        }
        $grezzi = $modello->getAttributes();
        $testo = trim(preg_replace('/\s+/u', ' ', (string) ($grezzi['testo'] ?? '')) ?? '');
        if (mb_strlen($testo) > 60) {
            $testo = rtrim(mb_substr($testo, 0, 59)) . '…';
        }
        $pezzi = ['«' . $testo . '»'];
        if (($dal = FormatoDelValore::testo($grezzi['ricordami_dal'] ?? null, 'data')) !== null) {
            $pezzi[] = 'ricordamelo dal ' . $dal;
            $pezzi[] = match ($grezzi['a_chi'] ?? 'me') {
                AChiMemo::STUDIO->value => 'a tutto lo studio',
                AChiMemo::COLLABORATORE->value => 'a ' . (User::query()->whereKey($grezzi['assegnato_a'] ?? null)->value('name') ?? 'un collaboratore'),
                default => 'a te',
            };
        }

        return 'Creata: ' . implode(', ', $pezzi);
    }

    /** Nella «Storia» dell'origine se ha una «Storia» (una scheda del registro), altrimenti in quella del condominio. */
    public function scheda(Model $modello): array
    {
        $tipo = $modello->getAttribute('origine_tipo');
        $id = $modello->getAttribute('origine_id');
        if (is_string($tipo) && $id !== null && CatalogoDelRegistro::descrizionePerTipo($tipo) !== null) {
            return [$tipo, (int) $id];
        }

        return [CondominioNelRegistro::TIPO, (int) $modello->getAttribute('condominio_id')];
    }
}

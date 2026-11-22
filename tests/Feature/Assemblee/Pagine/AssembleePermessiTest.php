<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role as Ruolo;

require_once __DIR__ . '/RotteDelleAssemblee.php';

/**
 * 1.12.0-beta.13 (Coda 679, §7 del progetto della tappa 2): **ogni rotta** delle pagine delle assemblee rifiuta con 403 i ruoli `utente` e `fornitore`,
 * qualunque permesso abbiano (anche «Accesso pannello amministratore», «Vede» e «Gestisce le assemblee»), e chi nello studio non ha i permessi delle
 * assemblee; chi ha solo «Vede le assemblee» legge (l'archivio, la pagina, «Scegli la delibera») e non scrive. L'elenco delle rotte si legge dal router:
 * una rotta nuova nel file entra da sola nel controllo.
 *
 * Che cosa non copre: le rotte della convocazione (hanno il loro file e il loro test); il 403 dentro le dodici pagine quando arriva una delibera (hanno i
 * loro test).
 */

it('ci sono le rotte da controllare, e ognuna risponde 403 ai condòmini, ai fornitori e a chi nello studio non ha i permessi delle assemblee', function () {
    $this->withoutVite();
    $p = apFixture($this);
    $rotte = apRotte($p);
    expect(count($rotte))->toBeGreaterThanOrEqual(30);
    $tutti = [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE, Permesso::REGISTER_VERBALE_SOTTOSCRITTO];
    $chi = [
        'condòmino con tutti i permessi' => apUtente(Ruolo::UTENTE->value, $tutti),
        'fornitore con tutti i permessi' => apUtente(Ruolo::FORNITORE->value, $tutti),
        'studio senza i permessi delle assemblee' => apUtente(null, []),
    ];
    $sbagliate = [];
    foreach ($chi as $nome => $u) {
        foreach ($rotte as [$metodo, $rotta, $parametri]) {
            $stato = $this->actingAs($u)->json($metodo, route($rotta, $parametri))->status();
            if ($stato !== 403) {
                $sbagliate[] = "{$nome}: {$metodo} {$rotta} → {$stato}";
            }
        }
    }
    expect($sbagliate)->toBe([]);
});

it('chi ha solo «Vede le assemblee» legge l\'archivio, la pagina e «Scegli la delibera», e non scrive niente', function () {
    $this->withoutVite();
    $p = apFixture($this);
    $vede = apUtente(null, [Permesso::VIEW_ASSEMBLEE]);
    $sbagliate = [];
    foreach (apRotte($p) as [$metodo, $rotta, $parametri]) {
        $stato = $this->actingAs($vede)->json($metodo, route($rotta, $parametri + ($rotta === 'admin.gestionale.delibere.scegli' ? ['ruolo' => 'delibera'] : [])))->status();
        $legge = in_array($rotta, ['admin.gestionale.assemblee.index', 'admin.gestionale.assemblee.show', 'admin.gestionale.delibere.scegli',
            'admin.gestionale.assemblee.verbale.show'], true);
        if ($legge ? $stato === 403 : $stato !== 403) {
            $sbagliate[] = "{$metodo} {$rotta} → {$stato}";
        }
    }
    expect($sbagliate)->toBe([]);
});

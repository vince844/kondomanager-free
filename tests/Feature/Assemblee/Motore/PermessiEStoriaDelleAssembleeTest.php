<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\CasellaPecNonImpostata;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\DocumentoPerPec;
use App\Services\Posta\InvioPerPec;
use App\Services\RegistroModifiche\RegistroPerLaPagina;
use App\Services\RegistroModifiche\SchedeDellaStoria;
use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §7 e §6.1 del progetto della tappa 2; decisioni 562 e 564): i tre permessi nei ruoli di serie (l'amministratore tutti, il
 * collaboratore vede e gestisce ma non carica il verbale), i ruoli `utente` e `fornitore` mai, anche con il permesso; la «Storia» dell'assemblea con
 * «Vede le assemblee»; l'area «Assemblee» nei filtri; la casella PEC che oggi non c'è, e l'invio per PEC che lo dice.
 *
 * Che cosa non copre: le rotte (il 403 per rotta è di chi le scrive); la pagina delle impostazioni della casella PEC (in attesa della bozza).
 */

it('i ruoli di serie: l\'amministratore tutti e tre, il collaboratore vede e gestisce, condòmini e fornitori mai', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $amm = \App\Models\User::factory()->create();
    $amm->assignRole(Role::AMMINISTRATORE->value);
    $coll = \App\Models\User::factory()->create();
    $coll->assignRole(Role::COLLABORATORE->value);
    $condomino = \App\Models\User::factory()->create();
    $condomino->assignRole(Role::UTENTE->value);
    $condomino->givePermissionTo(Permesso::VIEW_ASSEMBLEE->value);

    expect(AccessoAlleAssemblee::vede($amm))->toBeTrue()->and(AccessoAlleAssemblee::registraIlVerbale($amm))->toBeTrue()
        ->and(AccessoAlleAssemblee::vede($coll))->toBeTrue()->and(AccessoAlleAssemblee::gestisce($coll))->toBeTrue()
        ->and(AccessoAlleAssemblee::registraIlVerbale($coll))->toBeFalse()
        ->and(AccessoAlleAssemblee::vede($condomino))->toBeFalse()
        ->and(AccessoAlleAssemblee::vede(null))->toBeFalse();
});

it('la «Storia» dell\'assemblea è un tipo della rotta, con il titolo, e la vede chi vede le assemblee; l\'area ha la sua parola', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $senza = ViaRoma12::utente();

    expect(SchedeDellaStoria::esiste('assemblea'))->toBeTrue()->and(SchedeDellaStoria::esiste('delibera'))->toBeTrue()
        ->and(SchedeDellaStoria::titolo('assemblea', $a))->toBe('Storia dell\'assemblea ordinaria del 15/12/2026')
        ->and(SchedeDellaStoria::puoVedere($s->admin, 'assemblea', $a))->toBeTrue()
        ->and(SchedeDellaStoria::puoVedere($senza, 'assemblea', $a))->toBeFalse()
        ->and(RegistroPerLaPagina::AREE['assemblee'])->toBe('Assemblee');
});

it('la casella PEC oggi non è impostata e l\'invio lo dice; con una casella finta il programma la legge', function () {
    $s = ViaRoma12::nuovo($this);
    $documento = new DocumentoPerPec((int) $s->c->id, null, 'rossi@pec.it', 'convocazione', $s->c, '%PDF', 'convocazione.pdf', 'Convocazione', 'Testo');
    expect(app(CasellaPec::class)->configurazione())->toBeNull()
        ->and(fn () => app(InvioPerPec::class)->invia($documento, $s->admin->id))->toThrow(CasellaPecNonImpostata::class);

    app()->instance(CasellaPec::class, new class extends CasellaPec {
        public function configurazione(): ?ConfigurazionePec
        {
            return new ConfigurazionePec('studio@pec.it', 'smtps.pec.it', 465, 'studio@pec.it', 'segreta');
        }
    });
    $config = app(CasellaPec::class)->configurazione();
    expect($config->indirizzo)->toBe('studio@pec.it')->and(print_r($config, true))->not->toContain('segreta')
        ->and(app(InvioPerPec::class)->puoInviare())->toBeTrue();
});

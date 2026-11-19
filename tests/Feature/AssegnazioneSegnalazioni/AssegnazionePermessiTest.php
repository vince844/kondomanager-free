<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Assegnazione delle segnalazioni: il permesso e i ruoli di serie (decisione 294, punto 1)
 *
 * Chi assegna ha un permesso suo, «Assegna segnalazioni»: di serie all'amministratore e al collaboratore. Un ruolo su
 * misura lo riceve solo se glielo si dà: «Modifica segnalazioni» da solo non basta. Il seeder lo dà ai ruoli di serie a
 * ogni aggiornamento.
 */

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermessoSpatie;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

function asgRuoloHa(string $ruolo): bool
{
    return RuoloSpatie::findByName($ruolo, 'web')->permissions->pluck('name')->contains(ASG_PERMESSO);
}

test('il permesso «Assegna segnalazioni» esiste, con la sua spiegazione, anche per le pagine', function () {
    $permesso = Permission::tryFrom(ASG_PERMESSO);

    expect($permesso)->not->toBeNull('Manca il permesso in App\\Enums\\Permission')
        ->and($permesso->name)->toBe('ASSIGN_SEGNALAZIONI')
        ->and(PermessoSpatie::where('name', ASG_PERMESSO)->where('guard_name', 'web')->exists())->toBeTrue()
        ->and($permesso->description())->toBe('Permette di assegnare le segnalazioni a un collaboratore');

    $pagine = file_get_contents(resource_path('js/enums/Permission.ts'));
    expect($pagine)->toMatch('/ASSIGN_SEGNALAZIONI\s*=\s*[\'"]Assegna segnalazioni[\'"]/');
});

test('di serie lo hanno l\'amministratore e il collaboratore, non il condòmino né il fornitore', function () {
    expect(asgRuoloHa(Role::AMMINISTRATORE->value))->toBeTrue()
        ->and(asgRuoloHa(Role::COLLABORATORE->value))->toBeTrue()
        ->and(asgRuoloHa(Role::UTENTE->value))->toBeFalse()
        ->and(asgRuoloHa(Role::FORNITORE->value))->toBeFalse();
});

test('un aggiornamento lo dà al collaboratore che non lo aveva', function () {
    RuoloSpatie::findByName(Role::COLLABORATORE->value, 'web')->revokePermissionTo(ASG_PERMESSO);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    expect(asgRuoloHa(Role::COLLABORATORE->value))->toBeFalse();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(asgRuoloHa(Role::COLLABORATORE->value))->toBeTrue();
});

test('il collaboratore di serie assegna', function () {
    asgAssegna($this, $this->collaboratrice, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id);
});

test('un ruolo su misura con il pannello e «Modifica segnalazioni», ma senza il permesso, non assegna', function () {
    $ruolo = ccmRuolo('segreteria', ['Accesso pannello amministratore', 'Visualizza segnalazioni', 'Modifica segnalazioni']);
    $segreteria = ccmStudioSenzaAnagrafica('Sara Segreteria', $ruolo);

    asgAssegna($this, $segreteria, $this->s, $this->elena->id)->assertForbidden();

    expect(asgRiga($this->s)->assigned_to)->toBeNull();
});

test('lo stesso ruolo, ricevuto il permesso, assegna', function () {
    $ruolo = ccmRuolo('segreteria', ['Accesso pannello amministratore', 'Visualizza segnalazioni', ASG_PERMESSO]);
    $segreteria = ccmStudioSenzaAnagrafica('Sara Segreteria', $ruolo);

    asgAssegna($this, $segreteria, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id)
        ->and((int) asgRiga($this->s)->assegnata_da)->toBe($segreteria->id);
});

test('un condòmino non assegna, nemmeno la sua segnalazione, nemmeno se gli si dà il permesso senza il pannello', function () {
    $risposta = asgAssegna($this, $this->mario, $this->s, $this->elena->id);
    expect($risposta->status())->toBeIn([302, 403])
        ->and(asgRiga($this->s)->assigned_to)->toBeNull();

    $this->mario->givePermissionTo(ASG_PERMESSO);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $risposta = asgAssegna($this, $this->mario->fresh(), $this->s, $this->elena->id);

    expect($risposta->status())->toBeIn([302, 403])
        ->and(asgRiga($this->s)->assigned_to)->toBeNull();
});

test('le regole dicono chi assegna e chi può ricevere', function () {
    $regole = ASG_REGOLE;
    expect(class_exists($regole))->toBeTrue('Manca la classe delle regole');
    $sospesa = ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore');
    $sospesa->forceFill(['suspended_at' => now()])->save();

    expect($regole::puoAssegnare($this->admin))->toBeTrue()
        ->and($regole::puoAssegnare($this->collaboratrice))->toBeTrue()
        ->and($regole::puoAssegnare($this->mario))->toBeFalse()
        ->and($regole::puoRicevere($this->elena))->toBeTrue()
        ->and($regole::puoRicevere($this->admin))->toBeTrue()
        ->and($regole::puoRicevere($this->mario))->toBeFalse()
        ->and($regole::puoRicevere($sospesa->fresh()))->toBeFalse();
});

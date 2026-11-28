<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Sulla pertinenza spuntata nel passaggio, la riga di chi esce che si chiude è quella con una quota, non la prima in corso.
 *
 * Il dato: sul Box 12 Ugo ha due righe «proprietario» in corso, la prima a quota 0 e la seconda al 100 % (scritte prima della guardia
 * del modulo, o dall'importatore: dall'interfaccia due righe della stessa persona e ruolo sovrapposte non si scrivono). La registrazione,
 * il pannello e la fermata leggevano la prima riga in corso: il passaggio chiudeva la riga a zero e Ugo restava al 100 % sul box insieme
 * a chi entra. Ora tutti e tre leggono `TitolaritaImmobile::rigaCheEsceDallaPertinenza`; con due righe con una quota il passaggio si
 * ferma. Lo scenario è quello di `ScenariFineLocazione.php`: «Spese generali» € 1.200,00, 30 % «Inquilino», 70 % «Proprietario»,
 * Interno 1 900 millesimi e Box 12 100.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/FermataDopoScriviHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Le righe di una persona su un'unità, in ordine di scrittura: [quota, dal, al]. */
function ppqzRighe(array $s, string $nome, $unita): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $s['persone'][$nome]->id)->orderBy('id')
        ->get(['quota', 'data_inizio', 'data_fine'])
        ->map(fn ($r) => [round((float) $r->quota, 2), substr((string) $r->data_inizio, 0, 10), $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10)])->all();
}

it('la vendita con il box chiude la riga di Ugo al 100 %, non quella a zero; il piano generato dopo divide il box fra Ugo ed Elsa', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 0], ['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclPersona($s, 'Elsa');

    fclRegistra($this, $s, ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-07-01'));

    expect(ppqzRighe($s, 'Ugo', $s['box']))->toBe([[0.0, '2019-01-01', null], [100.0, '2019-01-01', '2026-06-30']])
        ->and(ppqzRighe($s, 'Elsa', $s['box']))->toBe([[100.0, '2026-07-01', null]]);

    // Il riparto del box nel piano generato dopo: «Proprietario» 70 % di 12000 = 8400; Ugo 8400 × 181/365 = 4165,48, Elsa 8400 × 184/365
    // = 4234,52 → 4165 + 4234 = 8399, il centesimo al resto più grande (,52) → Elsa 4235. Ines 30 % = 3600 per tutto l'anno.
    fclGenera($s);
    expect(fclNetti($s, $s['box']))->toBe(['Elsa' => 4235, 'Ines' => 3600, 'Ugo' => 4165]);
});

it('dopo una fine «Scrivi», la vendita con il box: il pannello annuncia la fermata del box e la registrazione chiude la stessa riga', function () {
    // La storia della mutazione M83 della 1.11.0-beta.50, che fissava il comportamento vecchio come «dato anomalo»: il pannello non
    // annunciava la fermata del box perché la registrazione chiudeva la riga a zero. Ora la riga chiusa è quella al 100 %, il peso di Ugo
    // sul box cala, e la fermata del box c'è nel pannello e nel registro. L'Interno 1 come prima: € 81,66 verso Elsa.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 0], ['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    fclPersona($s, 'Elsa');
    $modulo = ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01');

    $fermate = collect(fclAnteprima($this, $s, $modulo)['rate']['fermate_dopo_scrivi']);
    $vendita = fclRegistra($this, $s, $modulo);

    $delBox = $fermate->firstWhere('immobile_id', (int) $s['box']->id);
    expect($fermate->firstWhere('immobile_id', (int) $s['unita']->id)['cifra'] ?? null)->toBe(8166)
        ->and($delBox)->not->toBeNull()
        ->and(ppqzRighe($s, 'Ugo', $s['box']))->toBe([[0.0, '2019-01-01', null], [100.0, '2019-01-01', '2026-09-30']]);

    $registrate = collect(fds50Fermate($vendita))->merge(\App\Models\Gestionale\Subentro::where('subentro_padre_id', $vendita->id)->get()->flatMap(fn ($f) => fds50Fermate($f)));
    expect($registrate->pluck('cifra')->sum())->toBe($fermate->sum('cifra'));
});

it('con due righe di Ugo con una quota sul box il passaggio si ferma, nel pannello e nella registrazione, con la frase che dice dove correggere', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 40], ['Ugo', 'proprietario', 60]]]);
    fclPersona($s, 'Elsa');
    $modulo = ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-07-01');
    $frase = 'Box 12: Ugo ha due righe di proprietario in corso; correggile dallo storico della pertinenza prima del passaggio.';

    fclAnteprimaRisposta($this, $s, $modulo)->assertStatus(422)->assertJsonPath('errors.pertinenze.0', $frase);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $modulo)
        ->assertSessionHasErrors(['pertinenze' => $frase]);
    expect(ppqzRighe($s, 'Ugo', $s['box']))->toBe([[40.0, '2019-01-01', null], [60.0, '2019-01-01', null]]);
});

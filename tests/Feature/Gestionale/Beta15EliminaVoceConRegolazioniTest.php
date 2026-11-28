<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\BloccoDellaVoce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Una voce con dei movimenti a giornale non si elimina: la riga del giornale perderebbe la voce (`righe_scritture.voce_spesa_id` va a null
 * con la voce), e la spesa resterebbe nei costi ma uscirebbe dal consuntivo per voce e dal riparto. Il caso: «Spese postali», € 42,00 di
 * regolazioni immediate in due movimenti e nessuna fattura.
 */

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function b15evContesto($test): array
{
    [$condominio, $esercizio, $gestione, , , $voce] = setupPagamentiService();
    $test->actingAs($test->user);
    $cassa = DB::table('casse')->where('condominio_id', $condominio->id)->value('id');
    $scritture = [];
    foreach ([25.00, 17.00] as $importo) {
        $scritture[] = (new RegistraRegolazioneImmediataAction())->execute([
            'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id, 'conto_id' => $voce->id, 'cassa_id' => $cassa, 'fornitore_id' => null,
            'data_operazione' => now()->toDateString(), 'causale' => 'Raccomandate ai condòmini', 'importo' => $importo,
        ], $condominio, $esercizio);
    }

    return ['c' => $condominio, 'e' => $esercizio, 'pc' => $voce->piano_conto_id, 'voce' => $voce, 'scritture' => $scritture];
}

function b15evFlash(): ?array
{
    return session('message');
}

test('la voce con due regolazioni a giornale non si elimina, e le righe del giornale tengono la voce', function () {
    $s = b15evContesto($this);

    $this->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['pc'], $s['voce']]))
        ->assertRedirect('/piano-dei-conti');

    expect(b15evFlash())->toBe(['type' => 'error', 'message' => BloccoDellaVoce::rifiutoDeiMovimentiAGiornale(2)])
        ->and(BloccoDellaVoce::rifiutoDeiMovimentiAGiornale(2))->toBe('Eliminazione inibita: la voce ha 2 movimenti a giornale: stornali prima, o lascia la voce.')
        ->and(Conto::find($s['voce']->id))->not->toBeNull()
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $s['voce']->id)->count())->toBe(2);
});

test('il piano dei conti con una voce che ha movimenti a giornale non si elimina', function () {
    $s = b15evContesto($this);

    $this->from('/piani-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.destroy', [$s['c'], $s['e'], $s['pc']]))
        ->assertRedirect();

    expect(b15evFlash())->toBe(['type' => 'error', 'message' => BloccoDellaVoce::rifiutoDelPianoDeiContiPerIMovimenti(1, 2)])
        ->and(BloccoDellaVoce::rifiutoDelPianoDeiContiPerIMovimenti(1, 2))
        ->toBe('Eliminazione inibita: 1 voce del piano dei conti ha 2 movimenti a giornale: stornali prima, o lascia il piano dei conti.')
        ->and(DB::table('piani_conti')->where('id', $s['pc'])->exists())->toBeTrue()
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $s['voce']->id)->count())->toBe(2);
});

test('stornati tutti e due i movimenti, la voce si elimina', function () {
    $s = b15evContesto($this);
    foreach ($s['scritture'] as $scrittura) {
        (new StornaRegolazioneImmediataAction())->execute(ScritturaContabile::find($scrittura->id), $s['c'], 'Registrata per errore sulla voce sbagliata');
    }

    $this->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['pc'], $s['voce']]))
        ->assertRedirect();

    expect(b15evFlash()['type'] ?? null)->toBe('success')
        ->and(Conto::find($s['voce']->id))->toBeNull();
});

test('con uno solo dei due stornato la frase dice il movimento rimasto', function () {
    $s = b15evContesto($this);
    (new StornaRegolazioneImmediataAction())->execute(ScritturaContabile::find($s['scritture'][0]->id), $s['c'], 'Registrata per errore sulla voce sbagliata');

    $this->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['pc'], $s['voce']]));

    expect(b15evFlash())->toBe(['type' => 'error', 'message' => 'Eliminazione inibita: la voce ha 1 movimento a giornale: stornalo prima, o lascia la voce.']);
});

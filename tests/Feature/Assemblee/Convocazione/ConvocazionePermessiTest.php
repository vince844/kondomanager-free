<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role;
use App\Models\Documento;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§7 del progetto della tappa 2): ogni rotta della convocazione e del fascicolo risponde 403 a chi non ha i permessi delle assemblee, anche
 * con l'accesso al pannello, e a un condòmino o a un fornitore anche con il permesso; chi vede soltanto stampa la prova e scarica, ma non spedisce. Le rotte
 * si leggono dal file: una rotta nuova sotto lo stesso prefisso entra da sola nel controllo.
 *
 * Che cosa non copre: la limitazione per condominio del collaboratore (non c'è ancora).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le rotte della convocazione e del fascicolo, con il metodo e i parametri riempiti. @return list<array{0: string, 1: string, 2: string}> */
function convRotteDaProvare($s, $a, $invio, $documento): array
{
    $rotte = [];
    foreach (Route::getRoutes() as $r) {
        $nome = (string) $r->getName();
        if (! preg_match('/^admin\.gestionale\.assemblee\.(convocazione\.|invii\.|fascicolo\.|riconvoca$)/', $nome)) {
            continue;
        }
        // I documenti di un punto (il passo «Documenti»): il punto 4, dei lavori, che li prende.
        $parametri = ['condominio' => $s->c->id, 'assemblea' => $a->id, 'invio' => $invio->id, 'documento' => $documento->id,
            'punto' => $a->punti()->where('numero', 4)->value('id')];
        $metodo = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $rotte[] = [$nome, $metodo, route($nome, array_intersect_key($parametri, array_flip($r->parameterNames())))];
    }

    return $rotte;
}

it('ogni rotta della convocazione e del fascicolo: 403 senza i permessi delle assemblee, e per un condòmino e un fornitore con il permesso', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $invio = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $documento = Documento::find($invio->dati['documento_id']);
    $rotte = convRotteDaProvare($s, $a, $invio, $documento);
    expect(count($rotte))->toBeGreaterThanOrEqual(20);

    $senza = \App\Models\User::factory()->create();
    $senza->assignRole('admin');
    Permission::firstOrCreate(['name' => Permesso::VIEW_ASSEMBLEE->value, 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => Permesso::MANAGE_ASSEMBLEE->value, 'guard_name' => 'web']);
    $utenti = [$senza];
    foreach ([Role::UTENTE->value, Role::FORNITORE->value] as $ruolo) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
        $u = \App\Models\User::factory()->create();
        $u->assignRole($ruolo);
        $u->assignRole('admin');
        $u->givePermissionTo([Permesso::VIEW_ASSEMBLEE->value, Permesso::MANAGE_ASSEMBLEE->value]);
        $utenti[] = $u;
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $aperte = [];
    foreach ($utenti as $u) {
        foreach ($rotte as [$nome, $metodo, $url]) {
            $stato = $this->actingAs($u)->json($metodo, $url)->status();
            if ($stato !== 403) {
                $aperte[] = $nome . ' (' . $stato . ')';
            }
        }
    }
    expect($aperte)->toBe([]);
});

it('chi vede soltanto stampa la prova e scarica il PDF di un invio, ma non stampa le lettere né registra una consegna', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $invio = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $vede = \App\Models\User::factory()->create();
    $vede->assignRole('admin');
    Permission::firstOrCreate(['name' => Permesso::VIEW_ASSEMBLEE->value, 'guard_name' => 'web']);
    $vede->givePermissionTo(Permesso::VIEW_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($vede)->post(route('admin.gestionale.assemblee.convocazione.prova', [$s->c, $a]))->assertOk();
    $this->actingAs($vede)->get(route('admin.gestionale.assemblee.invii.pdf', [$s->c, $a, $invio]))->assertOk();
    $this->actingAs($vede)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]))->assertForbidden();
    $this->actingAs($vede)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $invio]), ['esito' => 'consegnata', 'giorno' => '2026-12-02'])
        ->assertForbidden();
});

it('con il corpo pieno, chi non ha i permessi delle assemblee riceve 403 prima di leggere i campi (le lettere, le PEC, l\'anteprima, il foglio)', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $senza = \App\Models\User::factory()->create();
    $senza->assignRole('admin');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $stati = [];
    foreach (['lettere', 'pec', 'anteprima', 'foglio'] as $r) {
        $stati[$r] = $this->actingAs($senza)->postJson(route('admin.gestionale.assemblee.convocazione.' . $r, [$s->c, $a]), ['anagrafiche' => 'tutte', 'formato' => 'doc'])->status();
    }

    expect($stati)->toBe(['lettere' => 403, 'pec' => 403, 'anteprima' => 403, 'foglio' => 403]);
});

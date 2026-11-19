<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'Action inbox sa a chi è assegnata una segnalazione (decisioni 486 e 487, il punto K7 della mappa dei collegamenti del 17/11/2026).
 *
 * - La riga del compito di una segnalazione porta chi la segue (`assegnata`): «te» se è chi guarda, il nome, o nessuno; chi non può più
 *   riceverla (sospeso, o senza il pannello) è segnato come non attivo, come nella scheda. Si legge dalla segnalazione, non da una copia nel
 *   compito: riassegnata, la riga lo dice subito. Le righe degli altri compiti non lo portano.
 * - Una lettura per tutte le righe della pagina: le richieste al database non crescono con le segnalazioni assegnate.
 * - Il filtro «Le mie» (`?mie=1`) lascia solo i compiti delle segnalazioni assegnate a chi guarda; le schede contano solo quelli; il numero
 *   di «Le mie» (la prop `le_mie`) viene dal server con la regola di chi vede (`CompitoDellaSegnalazione::soloQuelliCheVede`), segue solo il
 *   condominio come le card, e con zero assegnate è 0. Acceso, torna nei filtri della pagina (`filtri.mie`); spento non c'è.
 *
 * Che cosa non copre: come la riga scrive «assegnata a te» e il pulsante «Le mie» (li prova il vitest
 * `resources/js/pages/dashboard/ActionInboxLeMie.test.ts`), l'Inbox del condominio (porta la stessa riga, senza il filtro).
 */

use App\Enums\Role;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
    $this->anna = User::factory()->create(['name' => 'Anna Neri', 'email_verified_at' => now()]);
    $this->anna->assignRole(Role::COLLABORATORE->value);
});

function almSegnalazione($test, string $titolo, ?User $assegnata = null): Segnalazione
{
    $s = Segnalazione::create([
        'subject' => $titolo, 'description' => 'Da vedere.', 'created_by' => $test->admin->id, 'condominio_id' => $test->condominio->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true,
    ]);
    if ($assegnata !== null) {
        $s->forceFill(['assigned_to' => $assegnata->id, 'assegnata_da' => $test->admin->id, 'assegnata_il' => now()])->save();
    }

    return $s;
}

/** Le props della casella per chi guarda, con i filtri dati. */
function almPagina($test, User $chi, array $filtri = []): array
{
    return $test->actingAs($chi)->get(route('admin.inbox', $filtri))->assertOk()->viewData('page')['props'];
}

function almRighe(array $props): \Illuminate\Support\Collection
{
    $righe = collect($props['tasks']['data'] ?? []);
    foreach ($props['anteprima'] ?? [] as $gruppo) {
        $righe = $righe->merge($gruppo['righe']);
    }

    return $righe->unique('id')->values();
}

it('la riga del compito di una segnalazione dice chi la segue: te, un nome, nessuno; gli altri compiti no', function () {
    almSegnalazione($this, 'Ascensore fermo', $this->admin);
    almSegnalazione($this, 'Perdita in cantina', $this->anna);
    almSegnalazione($this, 'Cancello rotto');
    icsCompito($this, 'emissione_rata', ['numero_rata' => 1, 'totale_rata' => 10000], 'Emettere rata 1');

    $righe = almRighe(almPagina($this, $this->admin, ['tipo' => 'segnalazione_guasto']));
    $di = fn (string $titolo) => $righe->firstWhere('title', $titolo);

    expect($di('Ascensore fermo')['assegnata'])->toBe(['nome' => 'Vincenzo', 'te' => true, 'attivo' => true])
        ->and($di('Perdita in cantina')['assegnata'])->toBe(['nome' => 'Anna Neri', 'te' => false, 'attivo' => true])
        ->and($di('Cancello rotto')['assegnata'])->toBe(['nome' => null, 'te' => false, 'attivo' => true]);

    $rata = almRighe(almPagina($this, $this->admin))->firstWhere('title', 'Emettere rata 1');
    expect($rata)->not->toBeNull()
        ->and($rata['assegnata'] ?? null)->toBeNull();
});

it('si legge dalla segnalazione: riassegnata, la riga lo dice subito; chi è sospeso è segnato come non attivo', function () {
    $s = almSegnalazione($this, 'Perdita in cantina', $this->anna);
    $s->forceFill(['assigned_to' => $this->admin->id])->save();
    expect(almRighe(almPagina($this, $this->admin))->firstWhere('title', 'Perdita in cantina')['assegnata']['te'])->toBeTrue();

    $s->forceFill(['assigned_to' => $this->anna->id])->save();
    $this->anna->forceFill(['suspended_at' => now()])->save();
    expect(almRighe(almPagina($this, $this->admin))->firstWhere('title', 'Perdita in cantina')['assegnata'])
        ->toBe(['nome' => 'Anna Neri', 'te' => false, 'attivo' => false]);
});

it('«Le mie» lascia solo i compiti delle segnalazioni assegnate a chi guarda, e le schede contano solo quelli', function () {
    almSegnalazione($this, 'Ascensore fermo', $this->admin);
    almSegnalazione($this, 'Citofono muto', $this->admin);
    almSegnalazione($this, 'Perdita in cantina', $this->anna);
    almSegnalazione($this, 'Cancello rotto');
    icsCompito($this, 'emissione_rata', ['numero_rata' => 1, 'totale_rata' => 10000], 'Emettere rata 1');

    $tutte = almPagina($this, $this->admin);
    expect($tutte['le_mie'])->toBe(2)
        ->and($tutte['schede']['tutti'])->toBe(5)
        ->and($tutte['filtri']['mie'] ?? false)->toBeFalse();

    $mie = almPagina($this, $this->admin, ['mie' => 1]);
    expect(almRighe($mie)->pluck('title')->sort()->values()->all())->toBe(['Ascensore fermo', 'Citofono muto'])
        ->and($mie['schede']['tutti'])->toBe(2)
        ->and($mie['schede']['scaduti'])->toBe(0)
        ->and($mie['le_mie'])->toBe(2)
        ->and($mie['filtri']['mie'])->toBeTrue();

    // Per Anna le sue.
    $diAnna = almPagina($this, $this->anna, ['mie' => 1]);
    expect(almRighe($diAnna)->pluck('title')->all())->toBe(['Perdita in cantina'])
        ->and($diAnna['le_mie'])->toBe(1);
});

it('con zero assegnate «Le mie» c\'è con 0, e il numero segue la regola di chi vede le segnalazioni', function () {
    almSegnalazione($this, 'Perdita in cantina', $this->anna);
    expect(almPagina($this, $this->admin)['le_mie'])->toBe(0);
    $vuota = almPagina($this, $this->admin, ['mie' => 1]);
    expect(almRighe($vuota))->toHaveCount(0)->and($vuota['schede']['tutti'])->toBe(0);

    // Chi vede solo le segnalazioni che ha scritto non conta quelle degli altri, anche se gliele hanno assegnate.
    $ruolo = \Spatie\Permission\Models\Role::create(['name' => 'Solo le proprie', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Visualizza proprie segnalazioni']);
    $ugo = User::factory()->create(['name' => 'Ugo Proprie', 'email_verified_at' => now()]);
    $ugo->assignRole('Solo le proprie');
    almSegnalazione($this, 'Scritta dallo studio', $ugo);
    expect(almPagina($this, $ugo->fresh())['le_mie'])->toBe(0);
});

it('chi segue le segnalazioni si legge una volta per tutta la pagina: le richieste non crescono con le righe assegnate', function () {
    $conta = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.inbox', ['tipo' => 'segnalazione_guasto']))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    almSegnalazione($this, 'Prima', $this->anna);
    $conta();
    $con1 = $conta();

    foreach (range(1, 10) as $i) {
        almSegnalazione($this, "Altra {$i}", $i % 2 === 0 ? $this->anna : $this->admin);
    }
    expect(Evento::query()->compitiAperti()->count())->toBe(11);
    // Le segnalazioni nuove svuotano la memoria del numero del menu: la prima lettura dopo la rifà, e non conta.
    $conta();

    expect($conta())->toBe($con1);
});

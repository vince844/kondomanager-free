<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La persona con le rate non si toglie dal condominio e non si elimina dalla rubrica
 *
 * «Modifica» in rubrica faceva `condomini()->sync(...)` senza guardie, e «Elimina» fermava solo chi aveva ancora un condominio, un passaggio, un
 * accordo o un versamento per conto di altri. Bastava svuotare il campo «Condomini», salvare, ed eliminare: le chiavi esterne di `rate_quote`,
 * `anagrafica_immobile`, `saldi`, `crediti_residui` e `righe_riparto` sono in cascata, e con la persona sparivano le sue quote e la sua titolarità,
 * mentre la riga del giornale dell'incasso restava senza nome.
 *
 * Le due guardie:
 * - **«Elimina»** rifiuta chi ha righe in una di quelle tabelle, **in qualunque condominio**, anche staccato a mano dal condominio;
 * - **«Modifica»** rifiuta di togliere un condominio dove la persona ha una titolarità non chiusa, una quota con un residuo (a debito o a credito) o un
 *   saldo non ancora in un piano. Con la titolarità chiusa e tutto pagato passa: chi ha venduto può uscire dal condominio.
 *
 * La catena: persona con le rate → «Modifica» rifiutata → stacco a mano → «Elimina» rifiutata → la lettura dei debiti dice le stesse quote e l'incasso
 * successivo di quella persona si registra.
 *
 * Cosa NON copre: chi cancella dal database a mano; l'area del condòmino, che non ha l'eliminazione; la scelta dell'unità da cui far uscire chi ha
 * venduto (si registra dal passaggio).
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Saldo;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/LetturaDebitiProva.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole(Role::AMMINISTRATORE->value);
    $this->user = $this->user->fresh();
});

/** Anna, Interno 4 del «Condominio Aurora»: dodici rate da € 100,00, la prima emessa e pagata. */
function elAnnaConLeRate($test, array $saldi = []): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['4' => ['anna', 1000]], 12, 'prima_rata', $saldi, 120000);
    $s['c']->update(['nome' => 'Condominio Aurora']);
    lcdEmetti($test, $s, [1], '2026-01-02');
    $test->travelTo('2026-01-06 10:00:00');
    lcdIncassa($test, $s, 'anna', [lcdQuota($s, 'anna', 1) => 10000], '2026-01-05');

    return $s;
}

/** Il corpo del modulo di modifica di una persona, con i condomini dati. */
function elModulo(Anagrafica $a, array $condomini): array
{
    return ['nome' => $a->nome, 'indirizzo' => $a->indirizzo, 'codice_fiscale' => $a->codice_fiscale, 'email' => $a->email, 'condomini' => $condomini];
}

/** Quante righe hanno, nelle cinque tabelle in cascata, la persona. */
function elRighe(Anagrafica $a): array
{
    return [
        'rate_quote' => DB::table('rate_quote')->where('anagrafica_id', $a->id)->count(),
        'anagrafica_immobile' => DB::table('anagrafica_immobile')->where('anagrafica_id', $a->id)->count(),
        'saldi' => DB::table('saldi')->where('anagrafica_id', $a->id)->count(),
        'crediti_residui' => DB::table('crediti_residui')->where('anagrafica_id', $a->id)->count(),
        'righe_riparto' => DB::table('righe_riparto')->where('anagrafica_id', $a->id)->count(),
    ];
}

function elMessaggio(): array
{
    return session('message') ?? [];
}

test('«Modifica» con il campo «Condomini» svuotato rifiuta chi ha le rate: il condominio resta, le quote anche, e la frase dice dove', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    $prima = elRighe($anna);
    expect($prima['rate_quote'])->toBe(12)->and($prima['anagrafica_immobile'])->toBe(1);

    $r = $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))
        ->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []));

    $r->assertRedirect(route('admin.anagrafiche.edit', $anna));
    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')
        ->toContain('Anna ha 12 quote nelle rate di «Condominio Aurora» (Interno 4), di cui 11 da pagare')
        ->toContain('non si può togliere quel condominio dalla sua scheda')
        ->toContain('registra il passaggio dalla pagina dei titolari dell\'unità');
    expect($anna->condomini()->pluck('condomini.id')->all())->toBe([$s['c']->id])
        ->and(elRighe($anna))->toBe($prima);
});

test('«Elimina» rifiuta chi ha le rate anche staccato a mano dal condominio, e non porta via niente', function () {
    $s = elAnnaConLeRate($this, [['anna', '4', 5000]]);
    $anna = $s['persone']['anna'];
    // Si arriva alla guardia come fa `AccordoRientroRegoleTest`: la persona è già fuori dal condominio.
    DB::table('anagrafica_condominio')->where('anagrafica_id', $anna->id)->delete();
    $prima = elRighe($anna);
    expect($prima['rate_quote'])->toBe(12)->and($prima['saldi'])->toBeGreaterThan(0);

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $anna));

    $r->assertRedirect('/anagrafiche');
    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')
        ->toContain('Anna non si può eliminare: ha quote, saldi o righe di titolarità in «Condominio Aurora» (Interno 4)')
        ->toContain('Eliminandola sparirebbero anche le sue rate.');
    expect(Anagrafica::find($anna->id))->not->toBeNull()
        ->and(elRighe($anna))->toBe($prima);
});

test('la catena: rate, «Modifica» rifiutata, stacco a mano, «Elimina» rifiutata, la situazione debitoria dice le stesse quote e l\'incasso dopo si registra', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    $letturaPrima = lcdLeggi($s, 'anna')['totali'];

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []));
    expect(elMessaggio()['type'] ?? null)->toBe('error');

    DB::table('anagrafica_condominio')->where('anagrafica_id', $anna->id)->delete();
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $anna));
    expect(elMessaggio()['type'] ?? null)->toBe('error');

    expect(lcdLeggi($s, 'anna')['totali'])->toBe($letturaPrima)
        ->and(DB::table('rate_quote')->where('anagrafica_id', $anna->id)->count())->toBe(12);

    // L'incasso della rata successiva si registra: la persona è ancora titolare dell'unità, anche fuori dal pivot.
    $this->travelTo('2026-02-06 10:00:00');
    lcdEmetti($this, $s, [2], '2026-02-02');
    lcdIncassa($this, $s, 'anna', [lcdQuota($s, 'anna', 2) => 10000], '2026-02-05');
    expect((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'anna', 2))->value('importo_pagato'))->toBe(10000);
});

test('«Modifica» lascia passare chi ha venduto: titolarità chiusa e tutto pagato, il condominio si toglie', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    // Chiusa e pagata: la titolarità è finita il 4 gennaio (oggi è il 6) e ogni quota è chiusa.
    DB::table('anagrafica_immobile')->where('anagrafica_id', $anna->id)->update(['data_fine' => '2026-01-04']);
    DB::table('rate_quote')->where('anagrafica_id', $anna->id)->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []))
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));

    expect(elMessaggio()['type'] ?? null)->toBe('success')
        ->and($anna->condomini()->count())->toBe(0);
});

test('«Modifica» rifiuta chi ha venduto ma ha ancora una quota da pagare', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    DB::table('anagrafica_immobile')->where('anagrafica_id', $anna->id)->update(['data_fine' => '2026-01-04']);
    // Tutte pagate tranne la seconda.
    DB::table('rate_quote')->where('anagrafica_id', $anna->id)->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    DB::table('rate_quote')->where('id', lcdQuota($s, 'anna', 2))->update(['importo_pagato' => 0, 'stato' => 'da_pagare']);

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []));

    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')->toContain('Anna ha 12 quote nelle rate di «Condominio Aurora» (Interno 4), di cui 1 da pagare')
        ->and($anna->condomini()->count())->toBe(1);
});

test('«Modifica» rifiuta chi ha ancora una titolarità non chiusa, anche con tutte le quote pagate', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    DB::table('rate_quote')->where('anagrafica_id', $anna->id)->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []));

    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')->toContain('Anna è titolare di Interno 4 in «Condominio Aurora»: non si può togliere quel condominio dalla sua scheda')
        ->and($anna->condomini()->count())->toBe(1);
});

test('«Modifica» rifiuta chi ha un saldo non ancora in un piano', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    DB::table('anagrafica_immobile')->where('anagrafica_id', $anna->id)->update(['data_fine' => '2026-01-04']);
    DB::table('rate_quote')->where('anagrafica_id', $anna->id)->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    // Un saldo scritto dopo la generazione, che nessun piano ha ancora assorbito.
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $anna->id, 'immobile_id' => $s['immobili']['4']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 7000, 'origine' => 'manuale', 'is_applicato' => false]);

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []));

    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')->toContain('Anna ha un saldo non ancora in un piano in «Condominio Aurora» (Interno 4)')
        ->and($anna->condomini()->count())->toBe(1);
});

test('«Modifica» guarda solo i condomini che si tolgono: un altro condominio senza dati si toglie, e chi non ha dati si salva come prima', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    $altro = Condominio::factory()->create(['nome' => 'Condominio Sole']);
    $anna->condomini()->attach($altro->id);

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))
        ->put(route('admin.anagrafiche.update', $anna), elModulo($anna, [$s['c']->id]))
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));
    expect($anna->condomini()->pluck('condomini.id')->all())->toBe([$s['c']->id]);

    // Chi non ha righe in nessuna tabella si cancella come prima, dopo essere stato tolto dal condominio.
    $nuova = Anagrafica::create(['nome' => 'Da eliminare', 'indirizzo' => 'Via Verdi 2']);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $nuova))
        ->assertRedirect('/anagrafiche');
    expect(elMessaggio()['type'] ?? null)->toBe('success')
        ->and(Anagrafica::find($nuova->id))->toBeNull();
});

test('«Elimina» nomina i condomini e le unità, e con un saldo solo non parla di rate', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    $senzaRate = Anagrafica::forceCreate(['nome' => 'Bice', 'indirizzo' => 'Via Po 3', 'codice_fiscale' => 'BCEBCE80A01H501X']);
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $senzaRate->id, 'immobile_id' => $s['immobili']['4']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 7000, 'origine' => 'manuale', 'is_applicato' => false]);

    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $senzaRate));

    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')->toContain('Bice non si può eliminare: ha quote, saldi o righe di titolarità in «Condominio Aurora» (Interno 4)')
        ->not->toContain('le sue rate')
        ->and(Anagrafica::find($senzaRate->id))->not->toBeNull()
        ->and($anna->id)->not->toBe($senzaRate->id);
});

test('chi ha venduto con il passaggio registrato e ha ancora quote: il rifiuto non gli chiede di registrare il passaggio, dice che le rate rimaste si incassano e come toglierlo', function () {
    $s = elAnnaConLeRate($this);
    $anna = $s['persone']['anna'];
    $unita = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $anna->id)->value('immobile_id');
    DB::table('anagrafica_immobile')->where('anagrafica_id', $anna->id)->update(['data_fine' => '2026-01-31']);
    DB::table('subentri')->insert(['condominio_id' => $s['c']->id, 'immobile_id' => $unita, 'anagrafica_uscente_id' => $anna->id, 'tipologia' => 'proprietario',
        'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo('2026-02-06 10:00:00');
    $prima = elRighe($anna);

    $this->actingAs($this->user)->from(route('admin.anagrafiche.edit', $anna))->put(route('admin.anagrafiche.update', $anna), elModulo($anna, []))
        ->assertRedirect(route('admin.anagrafiche.edit', $anna));

    expect(elMessaggio()['type'] ?? null)->toBe('error')
        ->and(elMessaggio()['message'] ?? '')
        ->toContain('Anna ha 12 quote nelle rate di «Condominio Aurora» (Interno 4), di cui 11 da pagare: non si può togliere quel condominio dalla sua scheda.')
        ->not->toContain('registra il passaggio')
        ->toContain('Il passaggio della sua uscita è già registrato: le sue rate rimaste si incassano anche se resta fra le persone del condominio. Per togliere la persona dal condominio, salda o annulla le quote rimaste.');
    // Che cosa si può fare non cambia: il condominio resta, e niente si tocca.
    expect($anna->condomini()->pluck('condomini.id')->all())->toBe([$s['c']->id])->and(elRighe($anna))->toBe($prima);
});

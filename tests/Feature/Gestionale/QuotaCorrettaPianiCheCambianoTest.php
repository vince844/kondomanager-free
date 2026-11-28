<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Corretta una quota, l'elenco dei piani che cambiano
 *
 * Il caso di Carmen: Interno 5, il costruttore proprietario dal 01/01 al 15/09/2026 con quota 0 %, il nuovo proprietario (Mario) dal 16/09. Preventivo di
 * € 1.000,00 su due unità da 500 millesimi: con la quota a 0 % il piano chiede € 646,58 (€ 500,00 a Bice, € 146,58 a Mario), e € 353,42 (258 giorni su 365)
 * non li paga nessuno. Corretta la quota a 100 %, il salvataggio non tocca il piano: la pagina delle anagrafiche dell'unità mostra l'elenco dei piani che
 * chiederebbero cifre diverse, con chi paga quanto prima e dopo e la strada per ognuno (in bozza «Ricalcola»; emesso senza incassi annullare le emissioni,
 * ricalcolare, emettere; con incassi un addebito individuale).
 *
 * La catena: anagrafiche dell'unità → elenco → «Ricalcola» del piano in bozza, che chiede le cifre dell'elenco.
 *
 * Cosa NON copre: il pulsante che rifà i piani da solo (non c'è); la pagina a video (le prove del componente stanno in `AnagraficheListPianiCheCambiano.test.ts`).
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/LetturaDebitiProva.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole(Role::AMMINISTRATORE->value);
    $this->user = $this->user->fresh();
});

/** Il caso di Carmen, con il piano rigenerato sulle righe di oggi (costruttore a 0 %). */
function qcpCarmen($test): array
{
    $test->travelTo('2026-11-24 10:00:00');
    $s = lcdScenario(['5' => ['edil', 500], '6' => ['bice', 500]], 12, 'prima_rata', [], 100000);
    $mario = Anagrafica::forceCreate(['nome' => 'Mario', 'email' => 'mario-qcp@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'MARIOXQCP000001']);
    $mario->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['persone']['mario'] = $mario;
    $rigaEdil = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['edil']->id)->where('immobile_id', $s['immobili']['5']->id)->value('id');
    DB::table('anagrafica_immobile')->where('id', $rigaEdil)->update(['quota' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-09-15']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $mario->id, 'immobile_id' => $s['immobili']['5']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-09-16', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $s['riga_edil'] = $rigaEdil;
    qcpRicalcola($test, $s);
    expect(qcpQuote($s))->toBe(['Bice' => 50000, 'Mario' => 14658]);

    return $s;
}

function qcpRicalcola($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']->fresh()]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors()->assertRedirect();
}

/** Quanto chiede il piano a ogni persona, in centesimi, per nome. */
function qcpQuote(array $s): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
        ->where('rate.piano_rate_id', $s['piano']->id)->groupBy('anagrafiche.nome')->selectRaw('anagrafiche.nome as nome, sum(rate_quote.importo) as totale')
        ->pluck('totale', 'nome')->map(fn ($v) => (int) $v)->all();
}

/** Corregge la quota del costruttore a 100 % e segue il ritorno alla pagina delle anagrafiche: le props della pagina. */
function qcpCorreggi($test, array $s, int $quota = 100, ?string $note = null): array
{
    $test->actingAs($test->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['immobili']['5'], $s['riga_edil']]), [
        'anagrafica_id' => $s['persone']['edil']->id, 'tipologia' => 'proprietario', 'quota' => $quota, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-09-15', 'note' => $note,
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['immobili']['5']]));

    return $test->actingAs($test->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['immobili']['5']]))
        ->assertOk()->viewData('page')['props'];
}

/** Le righe di un piano dell'elenco per nome: [nome => [prima, dopo, differenza]]. */
function qcpRighe(array $piano): array
{
    return collect($piano['righe'])->mapWithKeys(fn ($r) => [$r['nome'] => [$r['prima'], $r['dopo'], $r['differenza']]])->all();
}

test('piano in bozza: salvata la quota, l\'elenco nomina «Preventivo 2026», € 646,58 che diventano € 1.000,00, € 353,42 al costruttore; «Ricalcola» chiede le stesse cifre', function () {
    $s = qcpCarmen($this);

    $props = qcpCorreggi($this, $s);
    $elenco = $props['piani_che_cambiano'];

    // Il salvataggio non ha toccato il piano.
    expect(qcpQuote($s))->toBe(['Bice' => 50000, 'Mario' => 14658])
        ->and($elenco['riga'])->toMatchArray(['persona' => 'Edil', 'tipologia' => 'proprietario', 'al' => '2026-09-15', 'quota_prima' => 0.0, 'quota_dopo' => 100.0])
        ->and($elenco['piani'])->toHaveCount(1)
        ->and($elenco['piani'][0])->toMatchArray(['nome' => 'Preventivo 2026', 'stato' => 'bozza', 'addebiti' => []])
        ->and($elenco['piani'][0]['totale'])->toBe(['prima' => 64658, 'dopo' => 100000, 'differenza' => 35342])
        ->and(qcpRighe($elenco['piani'][0]))->toBe(['Edil' => [0, 35342, 35342], 'Mario' => [14658, 14658, 0]]);

    // La catena: «Ricalcola» del piano in bozza chiede le cifre dell'elenco, e dopo l'elenco non c'è più.
    qcpRicalcola($this, $s);
    expect(qcpQuote($s))->toBe(['Bice' => 50000, 'Edil' => 35342, 'Mario' => 14658])
        ->and(qcpCorreggi($this, $s)['piani_che_cambiano'])->toBeNull();
});

test('piano emesso senza incassi: la strada è annullare le emissioni, ricalcolare ed emettere', function () {
    $s = qcpCarmen($this);
    lcdEmetti($this, $s, [1, 2], '2026-11-24');

    $piano = qcpCorreggi($this, $s)['piani_che_cambiano']['piani'][0];

    expect($piano['stato'])->toBe('emesso')
        ->and($piano['totale']['differenza'])->toBe(35342)
        ->and($piano['addebiti'])->toBe([]);
});

test('piano con incassi: la strada è un addebito individuale di € 353,42 al costruttore, e gli incassi non si toccano', function () {
    $s = qcpCarmen($this);
    lcdEmetti($this, $s, [1], '2026-11-24');
    $quota = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['bice']->id)->value('id');
    $importo = (int) DB::table('rate_quote')->where('id', $quota)->value('importo');
    lcdIncassa($this, $s, 'bice', [$quota => $importo], '2026-11-24');

    $piano = qcpCorreggi($this, $s)['piani_che_cambiano']['piani'][0];

    expect($piano['stato'])->toBe('con_incassi')
        ->and($piano['addebiti'])->toBe([['anagrafica_id' => $s['persone']['edil']->id, 'nome' => 'Edil', 'importo' => 35342]])
        ->and(qcpQuote($s))->toBe(['Bice' => 50000, 'Mario' => 14658]);
});

test('un salvataggio che non cambia le cifre (solo la nota) non porta l\'elenco', function () {
    $s = qcpCarmen($this);

    expect(qcpCorreggi($this, $s, 0, 'Costruttore, rogito del 2025')['piani_che_cambiano'])->toBeNull();
});

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # L'elenco delle unità dice le righe di proprietà chiuse a quota 0
 *
 * Il controllo di prima della 1.11.0-beta.30 non accettava due proprietari al 100 % sulla stessa unità, anche in periodi diversi: per registrare chi era
 * subentrato si abbassava a 0 % chi aveva venduto. Le unità 5, 11 e 30 di Carmen sono rimaste così (258, 53 e 266 giorni in cui nessuno paga). Nessun punto
 * dell'interfaccia le elencava; il comando `kondomanager:verifica-titolarita` solo da terminale.
 *
 * `RigheAQuotaZero` le trova: una riga di proprietà attiva, chiusa, a quota 0, che un altro proprietario con quota segue entro il giorno dopo la sua fine, e nei
 * cui giorni nessun altro copre l'unità. La pagina «Unità immobiliari» porta la prop `righeAQuotaZero`, una riga per caso con l'indirizzo dello storico. Mai una
 * correzione automatica, e nessun «Correggi tutte»: la correzione è dello storico, riga per riga.
 *
 * La catena: l'elenco → lo storico → «Correggi questa riga» → la riga a quota 100 esce dall'elenco → il piano in bozza si ricalcola con la cifra dell'unità.
 *
 * Cosa NON copre: la prova a video del riquadro (`ImmobiliList.test.ts` non c'è: la pagina non ha prove del componente; le righe del riquadro sono dati del
 * server); i piani già emessi, che non seguono la correzione.
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\User;
use App\Services\Gestionale\Titolarita\RigheAQuotaZero;
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

/** Una persona del condominio. */
function rqzPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $a = Anagrafica::forceCreate(['nome' => $nome, 'email' => "rqz{$n}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(str_pad(substr($nome, 0, 5), 5, 'X')) . 'RQZ' . str_pad((string) $n, 8, '0', STR_PAD_LEFT)]);
    $a->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $a;
}

/** Una riga di proprietà scritta a mano. */
function rqzRiga(array $s, string $unita, Anagrafica $chi, float $quota, ?string $dal, ?string $al, string $tipologia = 'proprietario', bool $attivo = true): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $chi->id, 'immobile_id' => $s['immobili'][$unita]->id, 'tipologia' => $tipologia, 'quota' => $quota,
        'attivo' => $attivo, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Le tre unità di Carmen (5, 11 e 30: il costruttore a 0 %, chiuso al 15/09, al 22/02 e al 23/09, e chi compra dal giorno dopo) e quattro che non sono questo
 * caso: la 40 (a 0 % senza nessuno dopo), la 41 (a 0 % ancora in corso), la 42 (a 0 % chiusa mentre un altro proprietario copre tutto) e la 43 (la riga a 0 %
 * è spenta). Il preventivo è di € 1.000,00 su mille millesimi.
 */
function rqzCarmen($test): array
{
    $test->travelTo('2026-11-24 10:00:00');
    $s = lcdScenario(['5' => ['edil', 250], '6' => ['bice', 450], '11' => ['edil', 50], '30' => ['edil', 50], '40' => ['edil', 50], '41' => ['edil', 50], '42' => ['edil', 50], '43' => ['edil', 50]],
        12, 'prima_rata', [], 100000);
    $edil = $s['persone']['edil'];
    // Le righe che lcdScenario ha scritto per il costruttore, tolte: le scrive ogni caso.
    DB::table('anagrafica_immobile')->whereIn('immobile_id', collect(['5', '11', '30', '40', '41', '42', '43'])->map(fn ($u) => $s['immobili'][$u]->id))->delete();
    $mario = rqzPersona($s, 'Mario');
    $lia = rqzPersona($s, 'Lia');
    $omar = rqzPersona($s, 'Omar');
    $s['righe'] = [
        '5' => rqzRiga($s, '5', $edil, 0, '2026-01-01', '2026-09-15'),
        '11' => rqzRiga($s, '11', $edil, 0, '2026-01-01', '2026-02-22'),
        '30' => rqzRiga($s, '30', $edil, 0, '2026-01-01', '2026-09-23'),
        '40' => rqzRiga($s, '40', $edil, 0, '2026-01-01', '2026-03-31'),
        '41' => rqzRiga($s, '41', $edil, 0, '2026-01-01', null),
        '42' => rqzRiga($s, '42', $edil, 0, '2026-01-01', '2026-03-31'),
        '43' => rqzRiga($s, '43', $edil, 0, '2026-01-01', '2026-03-31', attivo: false),
    ];
    rqzRiga($s, '5', $mario, 100, '2026-09-16', null);
    rqzRiga($s, '11', $lia, 100, '2026-02-23', null);
    rqzRiga($s, '30', $omar, 100, '2026-09-24', null);
    rqzRiga($s, '42', $lia, 100, '2019-01-01', null);
    rqzRiga($s, '43', $mario, 100, '2026-04-01', null);
    $s['persone'] += ['mario' => $mario, 'lia' => $lia, 'omar' => $omar];

    return $s;
}

test('il servizio trova le tre unità di Carmen, con unità, persona, periodo e giorni, e non le altre quattro', function () {
    $s = rqzCarmen($this);

    $righe = app(RigheAQuotaZero::class)->perCondominio($s['c']->id);

    expect(array_column($righe, 'unita'))->toBe(['Interno 5', 'Interno 11', 'Interno 30'])
        ->and(array_column($righe, 'persona'))->toBe(['Edil', 'Edil', 'Edil'])
        ->and(array_column($righe, 'giorni'))->toBe([258, 53, 266])
        ->and(array_column($righe, 'periodo'))->toBe(['1 gen 2026 – 15 set 2026', '1 gen 2026 – 22 feb 2026', '1 gen 2026 – 23 set 2026'])
        ->and(array_column($righe, 'riga_id'))->toBe([$s['righe']['5'], $s['righe']['11'], $s['righe']['30']])
        ->and($righe[0]['url_storico'])->toBe(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['immobili']['5']]) . '?storico=1');
});

test('un\'altra riga a quota 0 che nessuno segue, o che nessuno copre, o di un altro condominio, non è elencata', function () {
    $s = rqzCarmen($this);
    $altro = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000);
    DB::table('anagrafica_immobile')->where('immobile_id', $altro['immobili']['A']->id)->update(['quota' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-03-31']);
    $nuovo = rqzPersona($altro, 'Nuovo');
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $nuovo->id, 'immobile_id' => $altro['immobili']['A']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-04-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    expect(array_column(app(RigheAQuotaZero::class)->perCondominio($s['c']->id), 'unita'))->not->toContain('Interno 40', 'Interno 41', 'Interno 42', 'Interno 43', 'Interno A')
        ->and(array_column(app(RigheAQuotaZero::class)->perCondominio($altro['c']->id), 'unita'))->toBe(['Interno A']);
});

test('la pagina «Unità immobiliari» porta le righe, una per caso, con lo storico; senza casi, la lista è vuota', function () {
    $s = rqzCarmen($this);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.index', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect(array_column($props['righeAQuotaZero'], 'unita'))->toBe(['Interno 5', 'Interno 11', 'Interno 30'])
        ->and($props['righeAQuotaZero'][0]['giorni'])->toBe(258)
        ->and($props['righeAQuotaZero'][0]['url_storico'])->toContain(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['immobili']['5']]));

    DB::table('anagrafica_immobile')->where('quota', 0)->update(['quota' => 100]);
    $vuoto = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect($vuoto['righeAQuotaZero'])->toBe([]);
});

test('la catena: il riquadro, lo storico, la correzione della riga e il piano in bozza che chiede all\'unità la sua cifra', function () {
    $s = rqzCarmen($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']->fresh()]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors();
    $perPersona = fn () => DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
        ->where('rate.piano_rate_id', $s['piano']->id)->where('rate_quote.immobile_id', $s['immobili']['5']->id)->groupBy('anagrafiche.nome')
        ->selectRaw('anagrafiche.nome as nome, sum(rate_quote.importo) as totale')->pluck('totale', 'nome')->map(fn ($v) => (int) $v)->all();
    // L'unità 5 pesa 250 millesimi su 1.000: € 250,00 l'anno; con il costruttore a 0 % Mario paga i soli giorni dal 16/09.
    expect($perPersona())->not->toHaveKey('Edil');

    // Dal riquadro allo storico: l'unità 5 porta la riga correggibile, con i suoi 258 giorni.
    $riquadro = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.index', [$s['c']]))->assertOk()->viewData('page')['props']['righeAQuotaZero'];
    $storico = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $riquadro[0]['immobile_id']]))->assertOk()->viewData('page')['props']['storico'];
    $riga = collect($storico['righe'])->first(fn ($r) => $r['id'] === $riquadro[0]['riga_id']);
    expect($riga['correggibile'])->toBeTrue()->and($riga['giorni_scoperti'])->toBe(258);

    // La correzione dalla pagina di modifica della riga: la quota 100, come doveva essere.
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['immobili']['5'], $riga['id']]), [
        'anagrafica_id' => $s['persone']['edil']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-09-15', 'note' => null,
    ])->assertSessionHasNoErrors();

    // La riga esce dal riquadro, e il piano in bozza chiede al costruttore i suoi 258 giorni.
    $dopo = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.index', [$s['c']]))->assertOk()->viewData('page')['props']['righeAQuotaZero'];
    expect(array_column($dopo, 'unita'))->toBe(['Interno 11', 'Interno 30']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']->fresh()]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors();
    $dopoLaCorrezione = $perPersona();
    expect($dopoLaCorrezione['Edil'])->toBeGreaterThan(0)
        ->and($dopoLaCorrezione['Edil'] + $dopoLaCorrezione['Mario'])->toBe(25000);
});

<?php

/**
 * La previsione di cassa a 30 giorni con lo scaduto dei condòmini (1.12.0-beta.3, decisione 217 D4): gli incassi attesi vengono
 * dalla lettura comune (le rate emesse, per persona), divisi in già scaduti e in scadenza entro 30 giorni; il denaro atteso da una
 * persona è il suo residuo meno il suo credito disponibile nella stessa gestione, mai il credito di un altro; le prime cinque persone
 * per scaduto, con i giorni di ritardo della rata più vecchia e il collegamento al loro estratto conto, e «e altri N» con lo scaduto
 * per fasce. I semafori seguono la regola di prima; «oggi» è il giorno dell'amministratore.
 *
 * Centesimi.
 */

use App\Models\User;
use App\Services\Treasury\TreasuryGuardianService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function tgsStato(array $s, ?int $gestioneId = null): \App\Support\Treasury\TreasuryStatus
{
    return app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, $gestioneId);
}

/** Una fattura passiva aperta, approvata, che scade fra `$giorni` giorni. */
function tgsFattura(array $s, int $importo, int $giorni): void
{
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Enel', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('fatture_passive')->insert([
        'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura', 'numero_documento' => 'F-1',
        'netto_a_pagare' => $importo, 'totale_documento' => $importo, 'importo_imponibile' => $importo, 'importo_iva' => 0,
        'data_scadenza' => now()->addDays($giorni)->toDateString(), 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'is_pregresso' => false,
        'data_documento' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('nessuna rata scaduta: tutto è in scadenza, nessuna persona in ritardo', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1, 2, 3, 4, 5], '2026-01-02');
    foreach (['ugo', 'bice'] as $chi) {
        lcdIncassa($this, $s, $chi, [lcdQuota($s, $chi, 1) => 5000, lcdQuota($s, $chi, 2) => 5000, lcdQuota($s, $chi, 3) => 5000], '2026-03-15');
    }

    $t = tgsStato($s);
    // La rata 4 (05/04) di tutti e due, 5000 a testa; la 5 (05/05) è emessa ma fuori dai 30 giorni.
    expect($t->incassiScadutiCents)->toBe(0)
        ->and($t->incassiInScadenzaCents)->toBe(10000)
        ->and($t->incassiAttesiCents)->toBe(10000)
        ->and($t->morositaImpattanti)->toBe([])
        ->and($t->morositaAltri)->toBe(0)
        ->and($t->scadutoPerFasce)->toBe(['1_30' => 0, '31_90' => 0, 'oltre_90' => 0]);
});

it('scadute e in scadenza: lo scaduto di Ugo con i giorni della rata più vecchia e il collegamento al suo estratto conto', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1, 2, 3, 4], '2026-01-02');
    lcdIncassa($this, $s, 'bice', [lcdQuota($s, 'bice', 1) => 5000, lcdQuota($s, 'bice', 2) => 5000, lcdQuota($s, 'bice', 3) => 5000], '2026-03-15');

    $t = tgsStato($s);
    // Ugo: rate 1 (05/01, 74 giorni), 2 (05/02, 43), 3 (05/03, 15) scadute, 5000 ciascuna; la 4 (05/04) in scadenza, per tutti e due.
    expect($t->incassiScadutiCents)->toBe(15000)
        ->and($t->incassiInScadenzaCents)->toBe(10000)
        ->and($t->incassiAttesiCents)->toBe(25000)
        ->and($t->scadutoPerFasce)->toBe(['1_30' => 5000, '31_90' => 10000, 'oltre_90' => 0])
        ->and($t->morositaAltri)->toBe(0)
        ->and($t->morositaImpattanti)->toHaveCount(1)
        ->and($t->morositaImpattanti[0])->toMatchArray([
            'anagraficaId' => (int) $s['persone']['ugo']->id, 'condomino' => 'Ugo', 'immobile' => $s['immobili']['A']->etichetta,
            'importoCents' => 15000, 'giorniRitardo' => 74,
            'route' => 'admin.gestionale.anagrafiche.estratto-conto', 'routeParams' => ['condominio' => (int) $s['c']->id, 'anagrafica' => (int) $s['persone']['ugo']->id],
        ]);
    expect(route($t->morositaImpattanti[0]['route'], $t->morositaImpattanti[0]['routeParams']))->toContain("/{$s['persone']['ugo']->id}");
});

it('la rata 0 mista: il credito di Bice non toglie niente al denaro atteso da Ugo, e il semaforo passa da rosso a giallo', function () {
    $this->travelTo('2026-03-20 10:00:00');
    // Rata 0: Ugo deve 5000 di pregresso, Bice ha 5000 di credito riportato. Sulla stessa rata il netto è zero.
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'rata_zero', [['ugo', 'A', 5000], ['bice', 'B', -5000]]);
    lcdEmetti($this, $s, [0], '2026-03-01');
    expect(substr((string) DB::table('rate')->where('id', lcdRata($s, 0))->value('data_scadenza'), 0, 10) < '2026-03-20')->toBeTrue();
    DB::table('casse')->where('id', $s['cassa']->id)->update(['saldo_iniziale' => 0]);
    tgsFattura($s, 3000, 5);

    $t = tgsStato($s);
    // Pessimistico 0 − 3000 < 0; ottimistico −3000 + 5000 > 0: giallo. Con il netto dentro la rata (prima) gli attesi erano 0: rosso.
    expect($t->incassiAttesiCents)->toBe(5000)
        ->and($t->incassiScadutiCents)->toBe(5000)
        ->and($t->scenarioPessimisticoCents)->toBe(-3000)
        ->and($t->scenarioOttimisticoCents)->toBe(2000)
        ->and($t->livello)->toBe('giallo')
        ->and(collect($t->morositaImpattanti)->pluck('condomino')->all())->toBe(['Ugo']);
});

it('il credito disponibile di una persona, nella stessa gestione, toglie dal denaro atteso da lei, dalla rata più vecchia', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    // Ugo paga la rata 3 con 3000 in più: credito 3000 sulla rata 3.
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 10000], '2026-03-15', 3000);

    $t = tgsStato($s);
    // Rate 1 e 2 scadute, 10000 ciascuna; meno il credito di 3000: 17000. Lo scaduto della persona resta 20000 (il credito si mostra, non si netta).
    expect($t->incassiAttesiCents)->toBe(17000)
        ->and($t->incassiScadutiCents)->toBe(17000)
        ->and($t->incassiInScadenzaCents)->toBe(0)
        ->and($t->morositaImpattanti[0]['importoCents'])->toBe(20000);
});

it('alle 00:30 del 15/12 a Roma la finestra va fino al 14/01 e la rata del 14/12 è già scaduta: il giorno è quello dell\'amministratore', function () {
    $s = lcdRossi($this);
    DB::table('casse')->where('id', $s['cassa']->id)->update(['saldo_iniziale' => 0]);
    $this->travelTo(Carbon::parse('2026-12-15 00:30:00', 'Europe/Rome'));
    // Una fattura scaduta da cinque giorni (non pregressa): va al giorno 0, che è il 15/12.
    tgsFattura($s, 1000, -5);

    $t = tgsStato($s);
    // Rate emesse 1 (14/12), 2 (15/12), 3 (16/12), 5 (14/01/2027), 10000 ciascuna; la 4 è in bozza. Il credito di 3000 della rata 0
    // (stessa gestione) toglie dalla più vecchia: scadute 7000, in scadenza 30000.
    expect($t->incassiAttesiCents)->toBe(37000)
        ->and($t->incassiScadutiCents)->toBe(7000)
        ->and($t->incassiInScadenzaCents)->toBe(30000)
        ->and($t->morositaImpattanti[0])->toMatchArray(['importoCents' => 10000, 'giorniRitardo' => 1])
        // Il giorno 0 della timeline è il 15/12 e la finestra arriva al 14/01: la rata 5 entra nello scenario ottimistico.
        ->and($t->giornoScopertoPrevisto)->toBe('2026-12-15')
        ->and($t->scenarioPessimisticoCents)->toBe(-1000)
        ->and($t->scenarioOttimisticoCents)->toBe(36000);
});

it('le prime cinque persone per scaduto, e «e altri N» con lo scaduto per fasce di tutte', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $unita = [];
    foreach (range(1, 7) as $n) {
        $unita["U{$n}"] = ["p{$n}", $n * 100];
    }
    // 2800 millesimi: la rata 1 (05/01, 74 giorni) di p_n vale 10000 · 100n / 2800.
    $s = lcdScenario($unita, 12, 'prima_rata', [], 336000);
    lcdEmetti($this, $s, [1], '2026-01-02');

    $t = tgsStato($s);
    $quota = fn (int $n) => (int) DB::table('rate_quote')->where('id', lcdQuota($s, "p{$n}", 1))->value('importo');
    expect(collect($t->morositaImpattanti)->pluck('condomino')->all())->toBe(['P7', 'P6', 'P5', 'P4', 'P3'])
        ->and($t->morositaImpattanti[0]['importoCents'])->toBe($quota(7))
        ->and($t->morositaAltri)->toBe(2)
        ->and($t->scadutoPerFasce)->toBe(['1_30' => 0, '31_90' => 28000, 'oltre_90' => 0])
        ->and(array_sum(array_map($quota, range(1, 7))))->toBe(28000);
});

it('il ramo per gestione passa la gestione alla lettura: le rate di un\'altra gestione non sono attese', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-01-02');
    $altra = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);

    expect(tgsStato($s, (int) $s['g']->id)->incassiAttesiCents)->toBe(10000)
        ->and(tgsStato($s, (int) $altra->id)->incassiAttesiCents)->toBe(0)
        ->and(tgsStato($s, (int) $altra->id)->morositaImpattanti)->toBe([]);
});

it('il credito di una persona in un\'altra gestione non toglie niente al denaro atteso nella gestione della rata', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-01-02');
    // Un credito di 3000 di Ugo su un piano della straordinaria (una quota a credito, in bozza).
    $altra = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $piano = DB::table('piani_rate')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $altra->id, 'nome' => 'Lavori', 'tipo' => 'straordinario', 'created_at' => now(), 'updated_at' => now()]);
    $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $piano, 'numero_rata' => 1, 'importo_totale' => -3000, 'stato' => 'bozza', 'data_scadenza' => '2026-06-05', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert(['rata_id' => $rata, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id, 'importo' => -3000, 'importo_pagato' => 0,
        'stato' => 'credito', 'data_scadenza' => '2026-06-05', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(\App\Services\Gestionale\CreditoService::class)->perAnagrafica((int) $s['c']->id, (int) $s['persone']['ugo']->id)['totale_cents'])->toBe(3000);

    expect(tgsStato($s)->incassiAttesiCents)->toBe(10000);
});

it('la quota a credito a giornale sulla stessa persona, unità e rata di una a debito (vendita e ricompra): il credito non si toglie due volte', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    // La gemella a credito di Ugo sulla rata 1 dell'unità A, come la lascia una vendita seguita da una ricompra (decisione 25), emessa con la
    // sua gemella a debito: il giornale porta la rata 1 a 10000 − 3000 = 7000.
    $quota = (array) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 1))->first();
    DB::table('rate_quote')->insert(array_merge(\Illuminate\Support\Arr::except($quota, ['id']), ['importo' => -3000, 'importo_pagato' => 0, 'stato' => 'credito']));
    lcdEmetti($this, $s, [1], '2026-01-02');

    $l = lcdLeggi($s, 'ugo');
    expect(lcdRiga($l, $s, 'A', 1)['residuo'])->toBe(7000)
        ->and($l['credito_disponibile']['totale'])->toBe(3000);

    $t = tgsStato($s);
    // Il credito della gemella è già nel residuo della chiave: il denaro atteso è 7000, non 7000 − 3000.
    expect($t->incassiAttesiCents)->toBe(7000)
        ->and($t->incassiScadutiCents)->toBe(7000)
        ->and($t->morositaImpattanti[0])->toMatchArray(['importoCents' => 7000, 'creditoCents' => 0]);
});

it('accanto a ogni persona in ritardo il suo credito: chi ha un credito che copre lo scaduto lo mostra, e «Rate scadute» conta zero da lei', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    // Ugo paga la rata 3 con 20000 in più: credito 20000 sulla rata 3; le rate 1 e 2 (10000 ciascuna) restano scadute.
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 10000], '2026-03-15', 20000);

    $t = tgsStato($s);

    expect($t->incassiScadutiCents)->toBe(0)
        ->and($t->morositaImpattanti[0])->toMatchArray(['importoCents' => 20000, 'creditoCents' => 20000, 'giorniRitardo' => 74]);
});

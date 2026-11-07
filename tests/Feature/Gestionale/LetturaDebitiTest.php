<?php

/**
 * La lettura comune dei debiti dei condòmini (1.12.0-beta.3): per un condominio e una data D, per persona, il dovuto delle rate
 * emesse entro D (la scrittura di emissione con data ≤ D), il versato, il residuo e il credito sulla rata, dal giornale letto per
 * data; le righe su rate non ancora emesse in un gruppo a parte; gli anticipi; lo scaduto (scadenza < D) con le fasce. Le righe
 * su «Passate gestioni» non entrano: sono il rovescio di un credito riportato che il giornale non porta.
 *
 * Le cifre sono scritte a mano dalla regola, prima del codice. Centesimi; positivo = debito, negativo = credito.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Subentro\AnteprimaPassaggio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
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

/** La prova per persona e per chiave, per D = oggi, su ogni persona dello scenario: {@see lcdProvaPersone()}, {@see lcdProvaChiavi()}. */
function lcdAccetta($test, array $s): void
{
    $persone = [];
    foreach ($s['persone'] as $chi => $p) {
        $persone[$chi] = (int) $p->id;
    }
    expect(lcdProvaPersone($test, $test->user, (int) $s['c']->id, (int) $s['e']->id, $persone))->toBe([])
        ->and(lcdProvaChiavi($test, $test->user, (int) $s['c']->id, $persone, $s['immobili']))->toBe([]);
}

// ─── «Alla data»: registrato dopo, con la data prima; il credito; lo storno ───────────────────────────────────────────────

/** Una rata sola da € 300,00 a testa, scadenza 30/11/2026, emessa il 01/11/2026 per Ugo, Bianca e Carla. */
function lcdTrePersone($test): array
{
    $test->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000], 'B' => ['bianca', 1000], 'C' => ['carla', 1000]], 1, 'prima_rata', [], 90000, '2026-11-30', 30);
    expect(substr((string) DB::table('rate')->where('id', lcdRata($s, 1))->value('data_scadenza'), 0, 10))->toBe('2026-11-30')
        ->and((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 1))->value('importo'))->toBe(30000);
    $test->travelTo('2026-11-01 10:00:00');
    lcdEmetti($test, $s, [1], '2026-11-01');

    return $s;
}

it('al 31/12 conta il versamento con data 20/12 registrato il 10/01, non quello con data 03/01; il credito di chi ha versato di più; lo storno di un versamento tornato indietro vale dalla sua data', function () {
    $s = lcdTrePersone($this);

    $this->travelTo('2026-12-10 10:00:00');
    $bianca = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-10');
    $this->travelTo('2026-12-22 10:00:00');
    lcdIncassa($this, $s, 'carla', [lcdQuota($s, 'carla', 1) => 30000], '2026-12-22', 5000);
    // Il 2026 resta aperto fino al 15/01: Ugo registra il 10/01 un bonifico del 20/12 e uno del 03/01.
    $this->travelTo('2027-01-10 11:00:00');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 20000], '2026-12-20');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 5000], '2027-01-03');
    // Il versamento di Bianca torna indietro (assegno insoluto): lo storno del 15/01.
    $this->travelTo('2027-01-15 11:00:00');
    lcdStorna($this, $s, $bianca);
    $this->travelTo('2027-01-20 09:00:00');

    $ugo = lcdLeggi($s, 'ugo', '2026-12-31');
    $biancaL = lcdLeggi($s, 'bianca', '2026-12-31');
    $carla = lcdLeggi($s, 'carla', '2026-12-31');

    // Ugo: dovuto 30000, versato al 31/12 il solo 20000 del 20/12; scaduta il 30/11, al 31/12 sono 31 giorni.
    expect(lcdRiga($ugo, $s, 'A', 1))->toMatchArray(['dovuto' => 30000, 'versato' => 20000, 'residuo' => 10000, 'credito' => 0, 'scaduta' => true, 'giorni_ritardo' => 31])
        ->and($ugo['totali'])->toMatchArray(['emesso_non_pagato' => 10000, 'crediti' => 0, 'scaduto' => 10000, 'non_emesse' => 0, 'anticipi' => 0, 'netto' => 10000])
        ->and($ugo['totali']['fasce'])->toBe(['1_30' => 0, '31_90' => 10000, 'oltre_90' => 0])
        // Bianca al 31/12: il versamento c'era, lo storno è del 15/01.
        ->and(lcdRiga($biancaL, $s, 'B', 1))->toMatchArray(['dovuto' => 30000, 'versato' => 30000, 'residuo' => 0, 'credito' => 0])
        ->and($biancaL['totali']['scaduto'])->toBe(0)
        // Carla: € 350,00 su € 300,00, il credito di € 50,00 sta sulla rata; mai sommato al debito di un altro.
        ->and(lcdRiga($carla, $s, 'C', 1))->toMatchArray(['dovuto' => 30000, 'versato' => 35000, 'residuo' => 0, 'credito' => 5000])
        ->and($carla['totali'])->toMatchArray(['emesso_non_pagato' => 0, 'crediti' => 5000, 'netto' => -5000])
        // Per una data passata il credito disponibile non c'è, e la lettura lo dice.
        ->and($ugo['credito_disponibile'])->toBeNull();

    // Il filtro per data: la somma con segno delle righe su «Crediti verso condomini» al 31/12 è la voce dello stato patrimoniale.
    $sp = lcdStatoPatrimoniale($s, '2026-12-31');
    expect($ugo['totali']['netto'] + $biancaL['totali']['netto'] + $carla['totali']['netto'] + $sp['senza_persona'])->toBe($sp['voce'])
        ->and($sp['voce'])->toBe(5000);

    // Oggi (20/01/2027): Ugo ha versato anche il 03/01 (residuo 5000, 51 giorni); Bianca deve di nuovo 30000.
    $oggiUgo = lcdLeggi($s, 'ugo');
    $oggiBianca = lcdLeggi($s, 'bianca');
    expect($oggiUgo['alla'])->toBe('2027-01-20')
        ->and(lcdRiga($oggiUgo, $s, 'A', 1))->toMatchArray(['versato' => 25000, 'residuo' => 5000, 'giorni_ritardo' => 51])
        ->and($oggiUgo['totali']['fasce'])->toBe(['1_30' => 0, '31_90' => 5000, 'oltre_90' => 0])
        ->and($oggiBianca['totali'])->toMatchArray(['emesso_non_pagato' => 30000, 'scaduto' => 30000])
        // Oggi c'è il credito disponibile, lordo, con la sua fonte.
        ->and(lcdLeggi($s, 'carla')['credito_disponibile']['totale'])->toBe(5000)
        ->and($oggiUgo['credito_disponibile']['totale'])->toBe(0);

    lcdAccetta($this, $s);
});

it('lo storno di un errore di registrazione vale dalla data del versamento: al 31/12 Bianca deve 30000 (il 2026 aperto al 15/01)', function () {
    $s = lcdTrePersone($this);
    $this->travelTo('2026-12-10 10:00:00');
    $bianca = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-10');
    $this->travelTo('2027-01-15 11:00:00');
    expect(DB::table('esercizi')->where('id', $s['e']->id)->value('stato'))->toBe('aperto');
    lcdStorna($this, $s, $bianca, ['natura_storno' => 'errore_registrazione']);
    $this->travelTo('2027-01-20 09:00:00');

    expect(lcdRiga(lcdLeggi($s, 'bianca', '2026-12-31'), $s, 'B', 1))->toMatchArray(['residuo' => 30000]);
});

// ─── Il credito riportato della rata 0, l'emissione con data futura, il rimborso su una bozza ─────────────────────────────

it('il credito riportato usato con la rata 0 in bozza e poi emessa: per rata residui 15000 e credito 15000, «Passate gestioni» fuori, netto 0 come l\'estratto conto', function () {
    // Credito riportato di Ugo 20000 (rata 0), rate da 10000. Emesse le rate 1 e 2 il 28/02; il 10/03 Ugo usa 5000 del credito sulla
    // rata 1 con la rata 0 in bozza (il prelievo esce da «Passate gestioni»); il 15/03 si emette la rata 0, che porta in AVERE 15000.
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    lcdEmetti($this, $s, [0], '2026-03-15');

    $l = lcdLeggi($s, 'ugo');
    expect(lcdRiga($l, $s, 'A', 1))->toMatchArray(['residuo' => 5000, 'credito' => 0])
        ->and(lcdRiga($l, $s, 'A', 2))->toMatchArray(['residuo' => 10000, 'credito' => 0])
        ->and(lcdRiga($l, $s, 'A', 0))->toMatchArray(['residuo' => 0, 'credito' => 15000])
        ->and($l['totali'])->toMatchArray(['emesso_non_pagato' => 15000, 'crediti' => 15000, 'non_emesse' => 0, 'anticipi' => 0, 'netto' => 0])
        ->and($l['credito_disponibile']['totale'])->toBe(15000)
        ->and(lcdEstratto($this, $s, 'ugo'))->toBe(0);

    // Al 12/03, prima dell'emissione della rata 0, il credito riportato non è nel giornale: la lettura dà 15000, e lo dichiara.
    $prima = lcdLeggi($s, 'ugo', '2026-03-12');
    expect($prima['totali'])->toMatchArray(['emesso_non_pagato' => 15000, 'crediti' => 0, 'netto' => 15000])
        ->and($prima['credito_disponibile'])->toBeNull();

    lcdAccetta($this, $s);
});

it('il credito riportato usato tutto con la rata 0 che resta in bozza: niente residuo, netto 0', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -20000, lcdQuota($s, 'ugo', 1) => 10000, lcdQuota($s, 'ugo', 2) => 10000], '2026-03-10');

    $l = lcdLeggi($s, 'ugo');
    expect($l['totali'])->toMatchArray(['emesso_non_pagato' => 0, 'crediti' => 0, 'scaduto' => 0, 'non_emesse' => 0, 'netto' => 0])
        ->and($l['credito_disponibile']['totale'])->toBe(0)
        ->and(lcdEstratto($this, $s, 'ugo'))->toBe(0);

    lcdAccetta($this, $s);
});

it('il credito riportato rimborsato con la rata 0 in bozza: la lettura non mette a debito il denaro restituito', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    $quota0 = RataQuote::findOrFail(lcdQuota($s, 'ugo', 0));
    app(\App\Actions\Gestionale\Rimborsi\RimborsaCreditoAction::class)->execute($s['c'], $s['e'], $quota0, $s['cassa'], CarbonImmutable::parse('2026-03-10'), 20000, null, $this->user->id);

    $l = lcdLeggi($s, 'ugo');
    expect($l['rate'])->toBe([])
        ->and($l['non_emesse'])->toBe([])
        ->and($l['totali']['netto'])->toBe(0)
        ->and(lcdEstratto($this, $s, 'ugo'))->toBe(0);

    lcdAccetta($this, $s);
});

it('una rata emessa con una data futura non è dovuta prima di quella data; la morosità del passaggio la conta (eccezione dichiarata)', function () {
    // La rata 1 scade il 05/01/2026; l'emissione ha data 01/06/2026; oggi è il 20/03/2026.
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero');
    lcdEmetti($this, $s, [1], '2026-06-01');
    // Un acconto di 3000 il 10/03, prima della data dell'emissione: fino al 01/06 sta fra le righe su rate non ancora emesse.
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 3000], '2026-03-10');

    $l = lcdLeggi($s, 'ugo');
    expect($l['rate'])->toBe([])
        ->and($l['non_emesse'][0] ?? null)->toMatchArray(['rata_id' => lcdRata($s, 1), 'dovuto' => 0, 'versato' => 3000, 'saldo' => -3000])
        ->and($l['totali'])->toMatchArray(['emesso_non_pagato' => 0, 'crediti' => 0, 'scaduto' => 0, 'non_emesse' => -3000, 'netto' => -3000]);
    // Al 01/06 la rata è emessa e scaduta da 147 giorni (05/01 → 01/06): 10000 − 3000.
    expect(lcdRiga(lcdLeggi($s, 'ugo', '2026-06-01'), $s, 'A', 1))->toMatchArray(['residuo' => 7000, 'scaduta' => true, 'giorni_ritardo' => 147]);

    $m = new ReflectionMethod(AnteprimaPassaggio::class, 'morosita');
    $m->setAccessible(true);
    $morosita = $m->invoke(app(AnteprimaPassaggio::class), $s['immobili']['A'], $s['persone']['ugo']->id, [$s['immobili']['A']->id]);
    // La morosità del passaggio guarda la rata a giornale e la sua scadenza, non la data dell'emissione: 10000 − 3000 scaduti, contro 0.
    expect($morosita['importo'] ?? 0)->toBe(7000)->and($l['totali']['scaduto'])->toBe(0);
});

it('il rimborso di un credito delle entrate con la rata in bozza sta fra le righe su rate non ancora emesse, mai nel residuo', function () {
    // Una voce «entrata» di € 2.400,00 contro € 1.200,00 di spesa: le quote di Ugo sono −10000, senza riporto. Rimborso dei 10000
    // della rata 1 in bozza: DARE «Crediti verso condomini» a nome di Ugo con la rata 1.
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [], 120000, '2026-01-05', 5, 240000);
    $quota1 = RataQuote::findOrFail(lcdQuota($s, 'ugo', 1));
    expect((int) $quota1->importo)->toBe(-10000)->and($quota1->scrittura_contabile_id)->toBeNull();
    app(\App\Actions\Gestionale\Rimborsi\RimborsaCreditoAction::class)->execute($s['c'], $s['e'], $quota1, $s['cassa'], CarbonImmutable::parse('2026-03-10'), 10000, null, $this->user->id);

    $l = lcdLeggi($s, 'ugo');
    expect($l['rate'])->toBe([])
        ->and($l['non_emesse'])->toHaveCount(1)
        ->and($l['non_emesse'][0])->toMatchArray(['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['A']->id, 'dovuto' => 10000, 'versato' => 0, 'saldo' => 10000])
        ->and($l['totali'])->toMatchArray(['emesso_non_pagato' => 0, 'scaduto' => 0, 'non_emesse' => 10000, 'netto' => 10000])
        ->and(lcdEstratto($this, $s, 'ugo'))->toBe(10000);

    lcdAccetta($this, $s);
});

// ─── Il giorno: il giorno della scadenza non è scaduto, a qualunque ora di Roma ────────────────────────────────────────────

it('alle 11:00 del 15/12 lo scaduto di Rossi è 10000: la rata del giorno stesso, la bozza e la rata 0 a credito non contano', function () {
    $s = lcdRossi($this);
    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));

    $l = lcdLeggi($s, 'rossi');
    expect($l['alla'])->toBe('2026-12-15')
        ->and($l['totali'])->toMatchArray(['emesso_non_pagato' => 40000, 'scaduto' => 10000, 'ritardo_massimo' => 1])
        ->and($l['totali']['fasce'])->toBe(['1_30' => 10000, '31_90' => 0, 'oltre_90' => 0])
        ->and(lcdRiga($l, $s, 'A', 2))->toMatchArray(['scaduta' => false, 'giorni_ritardo' => 0])
        ->and(lcdRiga($l, $s, 'A', 4))->toBeNull()
        ->and($l['credito_disponibile']['totale'])->toBe(3000);

    // La morosità del passaggio, la regola comune già scritta, dà la stessa cifra per (persona, unità).
    $m = new ReflectionMethod(AnteprimaPassaggio::class, 'morosita');
    $m->setAccessible(true);
    expect($m->invoke(app(AnteprimaPassaggio::class), $s['immobili']['A'], $s['persone']['rossi']->id, [$s['immobili']['A']->id])['importo'] ?? 0)->toBe($l['totali']['scaduto']);

    lcdAccetta($this, $s);
});

it('alle 00:30 del 15/12 a Roma (le 23:30 del 14/12 in UTC) lo scaduto di Rossi è ancora 10000, e il giorno è il 15/12', function () {
    $s = lcdRossi($this);
    $this->travelTo(Carbon::parse('2026-12-15 00:30:00', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2026-12-14');

    $l = lcdLeggi($s, 'rossi');
    expect($l['alla'])->toBe('2026-12-15')
        ->and($l['totali'])->toMatchArray(['emesso_non_pagato' => 40000, 'scaduto' => 10000])
        ->and($l['credito_disponibile'])->not->toBeNull();
});

// ─── Le persone e le unità: due persone sulla stessa rata, una persona con due unità, il netto mai fra persone ─────────────

it('due persone sulla stessa rata e una persona con appartamento e box: residuo per unità, crediti a parte, il netto per persona soltanto', function () {
    $this->travelTo('2026-03-20 10:00:00');
    // Ugo ha A (600) e il box G (200), Bice B (200): per rata Ugo 6000 + 2000, Bice 2000. Ugo ha un credito riportato di 7000 sull'unità A.
    $s = lcdScenario(['A' => ['ugo', 600], 'G' => ['ugo', 200], 'B' => ['bice', 200]], 12, 'prima_rata', [['ugo', 'A', -7000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    // Bice paga la rata 1 e 500 in più (parte in più sulla stessa quota).
    lcdIncassa($this, $s, 'bice', [lcdQuota($s, 'bice', 1) => 2000], '2026-03-10', 500);

    $ugo = lcdLeggi($s, 'ugo');
    $bice = lcdLeggi($s, 'bice');
    $quotaA1 = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 1))->where('immobile_id', $s['immobili']['A']->id)->value('importo');
    // La rata 1 dell'unità A: 6000 − 7000 = −1000 (credito sulla rata); il box G deve 2000; la rata 2: A 6000, G 2000.
    expect($quotaA1)->toBe(-1000)
        ->and(lcdRiga($ugo, $s, 'A', 1))->toMatchArray(['residuo' => 0, 'credito' => 1000])
        ->and(lcdRiga($ugo, $s, 'G', 1))->toMatchArray(['residuo' => 2000, 'credito' => 0])
        ->and(lcdRiga($ugo, $s, 'A', 2))->toMatchArray(['residuo' => 6000])
        ->and($ugo['totali'])->toMatchArray(['emesso_non_pagato' => 10000, 'crediti' => 1000, 'netto' => 9000])
        ->and(lcdRiga($bice, $s, 'B', 1))->toMatchArray(['residuo' => 0, 'credito' => 500])
        ->and($bice['totali'])->toMatchArray(['emesso_non_pagato' => 2000, 'crediti' => 500, 'netto' => 1500]);

    // Per condominio: una voce per persona, mai una somma.
    $tutte = app(LetturaDebiti::class)->perCondominio((int) $s['c']->id);
    expect(array_keys($tutte))->toEqualCanonicalizing([(int) $s['persone']['ugo']->id, (int) $s['persone']['bice']->id])
        ->and($tutte[(int) $s['persone']['ugo']->id]['totali']['netto'])->toBe(9000);

    lcdAccetta($this, $s);
});

it('un anticipo su una rata in bozza sta fra le righe su rate non ancora emesse; una parte in più senza quota d\'appoggio sta negli anticipi', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    // Un anticipo di 3000 sulla rata 5, ancora in bozza.
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 5) => 3000], '2026-03-10');

    $l = lcdLeggi($s, 'ugo');
    expect($l['non_emesse'])->toHaveCount(1)
        ->and($l['non_emesse'][0])->toMatchArray(['rata_id' => lcdRata($s, 5), 'dovuto' => 0, 'versato' => 3000, 'saldo' => -3000])
        ->and($l['totali'])->toMatchArray(['emesso_non_pagato' => 20000, 'scaduto' => 20000, 'non_emesse' => -3000, 'anticipi' => 0, 'netto' => 17000]);

    lcdAccetta($this, $s);

    // Un dato di prima: la parte in più scritta sul conto «Anticipi», senza rata (il ramo di quando non c'era una quota d'appoggio).
    $anticipi = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'anticipi_condomini')->value('id');
    $banca = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'banca')->value('id');
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
        'data_registrazione' => '2026-03-15', 'data_competenza' => '2026-03-15', 'numero_protocollo' => 'INC-2026-LCD', 'causale' => 'Bonifico',
        'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $banca, 'anagrafica_id' => null, 'rata_id' => null, 'immobile_id' => null, 'tipo_riga' => 'dare', 'importo' => 1500, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $anticipi, 'anagrafica_id' => $s['persone']['ugo']->id, 'rata_id' => null, 'immobile_id' => null, 'tipo_riga' => 'avere', 'importo' => 1500, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(lcdLeggi($s, 'ugo')['totali'])->toMatchArray(['emesso_non_pagato' => 20000, 'non_emesse' => -3000, 'anticipi' => -1500, 'netto' => 15500])
        ->and(lcdLeggi($s, 'ugo', '2026-03-14')['totali']['anticipi'])->toBe(0);
});

it('la gestione, se data, limita la lettura alle rate dei suoi piani', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-02-28');
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);

    expect(app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['ugo']->id, null, (int) $s['g']->id)['totali']['emesso_non_pagato'])->toBe(10000)
        ->and(app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['ugo']->id, null, (int) $altra->id)['totali']['emesso_non_pagato'])->toBe(0);
});

it('per condominio, oggi, c\'è anche chi ha solo un credito disponibile e nessuna riga nel giornale; per una data passata no', function () {
    $this->travelTo('2026-03-20 10:00:00');
    // Nessuna rata emessa. Ugo versa un anticipo sulla rata 3 il 20/02; Bice ha solo il credito riportato di 4000 nella rata 0 in bozza.
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'rata_zero', [['bice', 'B', -4000]]);
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 2000], '2026-02-20');

    $oggi = app(LetturaDebiti::class)->perCondominio((int) $s['c']->id);
    $bice = (int) $s['persone']['bice']->id;
    expect(array_keys($oggi))->toEqualCanonicalizing([(int) $s['persone']['ugo']->id, $bice])
        ->and($oggi[$bice]['rate'])->toBe([])
        ->and($oggi[$bice]['totali']['netto'])->toBe(0)
        ->and($oggi[$bice]['credito_disponibile']['totale'])->toBe(4000)
        ->and(array_keys(app(LetturaDebiti::class)->perCondominio((int) $s['c']->id, '2026-03-01')))->toBe([(int) $s['persone']['ugo']->id]);
});

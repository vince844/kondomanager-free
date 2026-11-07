<?php

/**
 * Il pregresso nella lettura comune dei debiti: i giorni e la fascia secondo l'origine dichiarata sul saldo.
 *
 * Il caso: nel piano 2026 la rata 0 scade il 31/01/2026, con la prima rata; dentro ci sono Rossi (€ 30,00, il conguaglio chiesto adesso),
 * Bianchi (€ 200,00, rate del 2025) e Ugo (€ 500,00, rate del 2023). Oggi è il 05/02/2026. Fino a questa versione la lettura dava a tutti e
 * tre «5 giorni», fascia 1–30, perché la rata 0 prende la scadenza della prima rata del piano. La regola:
 * - «dovuto con questo piano» (Rossi): i giorni dalla scadenza della rata 0, come una rata: 5 giorni, 1–30;
 * - un esercizio (Bianchi 2025, Ugo 2023): nessun numero di giorni, «oltre 90 giorni»;
 * - non dichiarata (Ugo prima della dichiarazione): nessun numero, «oltre 90 giorni»;
 * e il ritardo più vecchio della persona non conta il pregresso senza numero: la Tesoreria non scrive «in ritardo di 5 giorni» di un debito
 * del 2023. L'esercizio aperto dichiarato resta un esercizio: non diventa «dovuto con questo piano».
 *
 * Le cifre sono scritte a mano dalla regola. Centesimi.
 */

use App\Models\Esercizio;
use App\Models\User;
use App\Services\Gestionale\Debiti\OriginePregresso;
use App\Services\Gestionale\Debiti\SeiMesi;
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

/** Rossi, Bianchi e Ugo con il pregresso nella rata 0 (scadenza 31/01/2026), emessa il 20/01; gli esercizi 2023 e 2025, chiusi; oggi 05/02/2026. */
function rspTrePersone($test): array
{
    $test->travelTo('2026-01-20 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 300], 'B' => ['bianchi', 300], 'C' => ['ugo', 400]], 12, 'rata_zero',
        [['rossi', 'A', 3000], ['bianchi', 'B', 20000], ['ugo', 'C', 50000]], 120000, '2026-01-31', 31);
    lcdEmetti($test, $s, [0], '2026-01-20');
    foreach ([2023, 2025] as $anno) {
        $s["e{$anno}"] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'chiuso']);
    }
    expect(substr((string) DB::table('rate')->where('id', lcdRata($s, 0))->value('data_scadenza'), 0, 10))->toBe('2026-01-31');

    return $s;
}

function rspSaldo(array $s, string $chi): int
{
    return (int) DB::table('saldi')->where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
}

/** «Dichiara l'origine» dalla rotta della pagina dei saldi. */
function rspDichiara($test, array $s, array $chi, string $origine, ?int $esercizioId = null)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.saldi.origine', ['condominio' => $s['c']->id]), array_filter([
        'saldi' => array_map(fn ($c) => rspSaldo($s, $c), $chi), 'origine' => $origine, 'esercizio_origine_id' => $esercizioId,
    ], fn ($v) => $v !== null));
}

function rspTesoreria(array $s): array
{
    return collect(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->morositaImpattanti)->keyBy('condomino')->all();
}

it('senza un\'origine dichiarata nessun pregresso ha un numero di giorni: oltre 90, e la Tesoreria non scrive «in ritardo di 5 giorni»', function () {
    $s = rspTrePersone($this);
    $this->travelTo('2026-02-05 10:00:00');

    foreach (['rossi' => 3000, 'bianchi' => 20000, 'ugo' => 50000] as $chi => $importo) {
        $l = lcdLeggi($s, $chi);
        expect(lcdRiga($l, $s, ['rossi' => 'A', 'bianchi' => 'B', 'ugo' => 'C'][$chi], 0))
            ->toMatchArray(['residuo' => $importo, 'scaduta' => true, 'giorni_ritardo' => null])
            ->and(lcdRiga($l, $s, ['rossi' => 'A', 'bianchi' => 'B', 'ugo' => 'C'][$chi], 0)['pregresso']['origine'])->toBe(OriginePregresso::NON_DICHIARATA)
            ->and($l['totali']['fasce'])->toBe(['1_30' => 0, '31_90' => 0, 'oltre_90' => $importo])
            ->and($l['totali'])->toMatchArray(['scaduto' => $importo, 'ritardo_massimo' => 0, 'anzianita_non_nota' => true]);
    }

    $t = rspTesoreria($s);
    expect($t['Ugo'])->toMatchArray(['giorniRitardo' => null, 'ritardoTesto' => 'c\'è un pregresso di anzianità non nota'])
        ->and(collect($t)->pluck('ritardoTesto')->filter(fn ($x) => str_contains($x, 'in ritardo di'))->all())->toBe([]);
});

it('dichiarate le origini: Rossi «dovuto con questo piano» 5 giorni 1–30; Bianchi 2025 e Ugo 2023 senza numero, oltre 90, con le date dei sei mesi dal loro esercizio', function () {
    $s = rspTrePersone($this);
    $this->travelTo('2026-02-05 10:00:00');

    rspDichiara($this, $s, ['rossi'], OriginePregresso::CON_IL_PIANO)->assertSessionHasNoErrors();
    rspDichiara($this, $s, ['bianchi'], OriginePregresso::ESERCIZIO, (int) $s['e2025']->id)->assertSessionHasNoErrors();

    // Chi e quando; l'importo non cambia.
    $saldo = DB::table('saldi')->where('id', rspSaldo($s, 'bianchi'))->first();
    expect($saldo->origine_dichiarata)->toBe('esercizio')
        ->and((int) $saldo->esercizio_origine_id)->toBe((int) $s['e2025']->id)
        ->and((int) $saldo->origine_dichiarata_da)->toBe((int) $this->user->id)
        ->and($saldo->origine_dichiarata_il)->not->toBeNull()
        ->and((int) $saldo->saldo_iniziale)->toBe(20000);

    $rossi = lcdLeggi($s, 'rossi');
    expect(lcdRiga($rossi, $s, 'A', 0))->toMatchArray(['giorni_ritardo' => 5])
        ->and(lcdRiga($rossi, $s, 'A', 0)['pregresso']['origine'])->toBe(OriginePregresso::CON_IL_PIANO)
        ->and($rossi['totali']['fasce'])->toBe(['1_30' => 3000, '31_90' => 0, 'oltre_90' => 0])
        ->and($rossi['totali'])->toMatchArray(['ritardo_massimo' => 5, 'anzianita_non_nota' => false]);

    $bianchi = lcdLeggi($s, 'bianchi');
    $riga = lcdRiga($bianchi, $s, 'B', 0);
    expect($riga['giorni_ritardo'])->toBeNull()
        ->and($riga['pregresso'])->toMatchArray(['origine' => OriginePregresso::ESERCIZIO])
        ->and(OriginePregresso::etichetta($riga['pregresso']))->toBe('pregresso dell\'esercizio 2025')
        ->and($bianchi['totali']['fasce'])->toBe(['1_30' => 0, '31_90' => 0, 'oltre_90' => 20000]);
    // Le due date dei sei mesi del pregresso si calcolano dall'esercizio d'origine, e coincidono: 31/12/2025 più sei mesi.
    $date = app(SeiMesi::class)->perCondominio((int) $s['c']->id)->perRiga($riga, (int) $s['persone']['bianchi']->id);
    expect($date['dalla_scadenza']['data'])->toBe('2026-06-30')
        ->and($date['dalla_competenza']['data'])->toBe('2026-06-30')
        ->and($date['piu_vicina'])->toBe('2026-06-30');

    // Ugo senza origine: nessuna data.
    $ugo = lcdRiga(lcdLeggi($s, 'ugo'), $s, 'C', 0);
    $dateUgo = app(SeiMesi::class)->perCondominio((int) $s['c']->id)->perRiga($ugo, (int) $s['persone']['ugo']->id);
    expect(OriginePregresso::etichetta($ugo['pregresso']))->toBe('origine non dichiarata')
        ->and($dateUgo['dalla_scadenza']['data'])->toBeNull()
        ->and($dateUgo['dalla_competenza']['data'])->toBeNull()
        ->and($dateUgo['dalla_scadenza']['stato'])->toBe('origine_non_dichiarata')
        ->and($dateUgo['piu_vicina'])->toBeNull();

    // Ugo «2023»: le date dal 31/12/2023, 30/06/2024.
    rspDichiara($this, $s, ['ugo'], OriginePregresso::ESERCIZIO, (int) $s['e2023']->id)->assertSessionHasNoErrors();
    $ugo = lcdRiga(lcdLeggi($s, 'ugo'), $s, 'C', 0);
    expect($ugo['giorni_ritardo'])->toBeNull()
        ->and(app(SeiMesi::class)->perCondominio((int) $s['c']->id)->perRiga($ugo, (int) $s['persone']['ugo']->id)['piu_vicina'])->toBe('2024-06-30');

    $t = rspTesoreria($s);
    expect($t['Rossi'])->toMatchArray(['giorniRitardo' => 5, 'ritardoTesto' => 'in ritardo di 5 giorni'])
        ->and($t['Ugo'])->toMatchArray(['giorniRitardo' => null, 'ritardoTesto' => 'c\'è un pregresso di anzianità non nota'])
        ->and($t['Bianchi']['ritardoTesto'])->toBe('c\'è un pregresso di anzianità non nota');
});

it('Ugo senza origine con anche una rata ordinaria scaduta da 12 giorni: «ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota»', function () {
    $s = rspTrePersone($this);
    lcdEmetti($this, $s, [1], '2026-01-20');
    DB::table('rate')->where('id', lcdRata($s, 1))->update(['data_scadenza' => '2026-01-24']);
    $this->travelTo('2026-02-05 10:00:00');

    $l = lcdLeggi($s, 'ugo');
    $quota1 = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 1))->value('importo');
    expect(lcdRiga($l, $s, 'C', 1))->toMatchArray(['giorni_ritardo' => 12])
        ->and($l['totali'])->toMatchArray(['ritardo_massimo' => 12, 'anzianita_non_nota' => true])
        ->and($l['totali']['fasce'])->toBe(['1_30' => $quota1, '31_90' => 0, 'oltre_90' => 50000])
        ->and(rspTesoreria($s)['Ugo'])->toMatchArray(['giorniRitardo' => 12, 'ritardoTesto' => 'ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota']);
});

it('Bianchi dichiarato sull\'esercizio 2026, quello aperto: resta un esercizio senza numero di giorni, non diventa «dovuto con questo piano»', function () {
    $s = rspTrePersone($this);
    $this->travelTo('2026-02-05 10:00:00');
    expect($s['e']->stato)->toBe('aperto');

    rspDichiara($this, $s, ['bianchi'], OriginePregresso::ESERCIZIO, (int) $s['e']->id)->assertSessionHasNoErrors();

    $riga = lcdRiga(lcdLeggi($s, 'bianchi'), $s, 'B', 0);
    expect($riga['giorni_ritardo'])->toBeNull()
        ->and($riga['pregresso']['origine'])->toBe(OriginePregresso::ESERCIZIO)
        ->and(lcdLeggi($s, 'bianchi')['totali']['fasce']['oltre_90'])->toBe(20000);
});

it('il caso del lettore: prima rata del piano il 05/11/2026, oggi 20/11/2026, € 500,00 senza origine: oltre 90, non 15 giorni', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', 50000]], 120000, '2026-11-05', 5);
    lcdEmetti($this, $s, [0], '2026-10-20');
    $this->travelTo('2026-11-20 10:00:00');

    $l = lcdLeggi($s, 'ugo');
    expect(lcdRiga($l, $s, 'A', 0))->toMatchArray(['scadenza' => '2026-11-05', 'giorni_ritardo' => null])
        ->and($l['totali']['fasce'])->toBe(['1_30' => 0, '31_90' => 0, 'oltre_90' => 50000]);
});

it('alle 00:30 del 06/02 a Roma (le 23:30 del 05/02 in UTC) Rossi «dovuto con questo piano» è in ritardo di 6 giorni: il giorno è quello dell\'amministratore', function () {
    $s = rspTrePersone($this);
    $this->travelTo('2026-02-05 10:00:00');
    rspDichiara($this, $s, ['rossi'], OriginePregresso::CON_IL_PIANO)->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-02-06 00:30:00', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2026-02-05');

    expect(lcdRiga(lcdLeggi($s, 'rossi'), $s, 'A', 0))->toMatchArray(['giorni_ritardo' => 6]);
});

it('più saldi nella stessa quota: vale l\'origine più vecchia, e «non dichiarata» se anche uno solo non l\'ha', function () {
    $s = rspTrePersone($this);
    $this->travelTo('2026-02-05 10:00:00');
    $e2023 = ['id' => (int) $s['e2023']->id, 'nome' => 'Esercizio 2023', 'data_inizio' => '2023-01-01', 'data_fine' => '2023-12-31'];
    $e2025 = ['id' => (int) $s['e2025']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31'];

    expect(OriginePregresso::piuVecchia([['origine' => 'esercizio', 'esercizio' => $e2025], ['origine' => 'esercizio', 'esercizio' => $e2023]]))
        ->toBe(['origine' => 'esercizio', 'esercizio' => $e2023])
        ->and(OriginePregresso::piuVecchia([['origine' => 'con_il_piano', 'esercizio' => null], ['origine' => 'esercizio', 'esercizio' => $e2025]])['origine'])->toBe('esercizio')
        ->and(OriginePregresso::piuVecchia([['origine' => 'esercizio', 'esercizio' => $e2025], ['origine' => 'prima_del_primo', 'esercizio' => null]])['origine'])->toBe('prima_del_primo')
        ->and(OriginePregresso::piuVecchia([['origine' => 'esercizio', 'esercizio' => $e2025], ['origine' => null, 'esercizio' => null]])['origine'])->toBe('non_dichiarata')
        ->and(OriginePregresso::piuVecchia([])['origine'])->toBe('non_dichiarata');
});

it('un saldo a credito dell\'unità dentro la stessa quota non rende «non dichiarata» l\'origine del debito dichiarato: la regola sta fra i debiti', function () {
    // Ugo ha € 500,00 di debito del 2025 e l'unità C € 100,00 di credito senza una persona (ripartito per intero su Ugo): una quota sola da
    // € 400,00 nella rata 0. Il credito non ha un'età che faccia sottostimare il ritardo, e la pagina dei saldi non lo fa dichiarare.
    $this->travelTo('2026-01-20 10:00:00');
    $s = lcdScenario(['C' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'C', 50000], [null, 'C', -10000]], 120000, '2026-01-31', 31);
    lcdEmetti($this, $s, [0], '2026-01-20');
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $dettagli = json_decode((string) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('regole_calcolo'), true)['dettagli_saldo'];
    expect((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('importo'))->toBe(40000)
        ->and(collect($dettagli)->pluck('importo_originale')->sort()->values()->all())->toBe([-10000, 50000]);
    $this->travelTo('2026-02-05 10:00:00');

    // Il debito senza origine: non dichiarata, oltre 90.
    expect(lcdRiga(lcdLeggi($s, 'ugo'), $s, 'C', 0)['pregresso']['origine'])->toBe(OriginePregresso::NON_DICHIARATA);

    // Dichiarato il debito dalla pagina dei saldi (il credito non ha il bottone): l'origine è il 2025, con la data dei sei mesi.
    rspDichiara($this, $s, ['ugo'], 'esercizio', (int) $e2025->id)->assertSessionHasNoErrors();
    $riga = lcdRiga(lcdLeggi($s, 'ugo'), $s, 'C', 0);
    expect($riga['pregresso']['origine'])->toBe(OriginePregresso::ESERCIZIO)
        ->and($riga['pregresso']['esercizio']['id'])->toBe((int) $e2025->id)
        ->and($riga['giorni_ritardo'])->toBeNull()
        ->and(app(SeiMesi::class)->perCondominio((int) $s['c']->id)->perRiga($riga, (int) $s['persone']['ugo']->id)['piu_vicina'])->toBe('2026-06-30');

    // Un credito dichiarato non toglie niente alla regola fra i debiti: un debito non dichiarato resta «non dichiarata».
    rspDichiara($this, $s, ['ugo'], 'non_dichiarata')->assertSessionHasNoErrors();
    DB::table('saldi')->where('condominio_id', $s['c']->id)->whereNull('anagrafica_id')->update(['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id]);
    expect(lcdRiga(lcdLeggi($s, 'ugo'), $s, 'C', 0)['pregresso']['origine'])->toBe(OriginePregresso::NON_DICHIARATA);
});

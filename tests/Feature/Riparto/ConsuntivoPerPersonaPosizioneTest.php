<?php

/**
 * Il consuntivo per persona: le quote dei piani (B), le spese personali, la posizione sulle rate, i saldi fuori dalle rate e il saldo
 * finale, sul caso di Via Verdi fatto dal programma (le rate, gli incassi, il passaggio dalla pagina).
 *
 * Le cifre sono rifatte a mano dalla regola, in centesimi; positivo = debito, negativo = credito. A di Via Verdi: Pulizie € 3.300,00 →
 * Neri 1.650,00, Bianchi 3.300 × 0,3 × 181/365 = 490,93, Rossi 499,07, Verdi 660,00; Manutenzioni € 900,00 → 450,00, 133,89, 136,11,
 * 180,00. La coppia del passaggio sulle quote emesse a Bianchi (€ 1.200,00): 1.200 × 184/365 = 604,93 a Rossi.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** L'array con le chiavi in ordine a ogni livello: due letture con le stesse cifre in un altro ordine sono uguali. */
function cpcOrdinate(array $a): array
{
    ksort($a);

    return array_map(fn ($v) => is_array($v) ? cpcOrdinate($v) : $v, $a);
}

/** Le colonne di una riga del consuntivo, per confrontarle in un colpo. */
function cpcColonne(?array $r): array
{
    return $r === null ? [] : ['a' => $r['a'], 'personali' => $r['personali']['non_chieste'], 'b' => $r['b']['totale'], 'conguaglio' => $r['conguaglio'],
        'posizione' => $r['posizione'], 'fuori' => $r['fuori_dalle_rate'], 'saldo' => $r['saldo']];
}

it('Via Verdi: A, le spese personali, B con la coppia letta dal programma, il conguaglio, la posizione, i saldi fuori dalle rate e il saldo, persona per persona', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');

    // La coppia del programma: 1.200 × 184/365 = 604,93, a credito di Bianchi e a debito di Rossi, non ancora in un piano.
    $coppia = Saldo::where('subentro_id', $passaggio->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($v) => (int) $v)->all();
    expect($coppia)->toEqual([$s['persone']['Bianchi']->id => -60493, $s['persone']['Rossi']->id => 60493]);

    $c = cpcLeggi($this, $s);

    expect(cpcColonne(cpcDi($c, $s, 'Neri', 'U1')))->toBe(['a' => 210000, 'personali' => 0, 'b' => 200000, 'conguaglio' => 10000, 'posizione' => 30000, 'fuori' => 0, 'saldo' => 40000])
        ->and(cpcColonne(cpcDi($c, $s, 'Bianchi', 'U2')))->toBe(['a' => 62482, 'personali' => 0, 'b' => 59507, 'conguaglio' => 2975, 'posizione' => 0, 'fuori' => -60493, 'saldo' => -57518])
        ->and(cpcColonne(cpcDi($c, $s, 'Rossi', 'U2')))->toBe(['a' => 63518, 'personali' => 0, 'b' => 60493, 'conguaglio' => 3025, 'posizione' => 0, 'fuori' => 60493, 'saldo' => 63518])
        ->and(cpcColonne(cpcDi($c, $s, 'Verdi', 'U3')))->toBe(['a' => 84000, 'personali' => 8000, 'b' => 80000, 'conguaglio' => 12000, 'posizione' => 0, 'fuori' => 0, 'saldo' => 12000]);

    // B di Bianchi: le quote (1.200,00) e la coppia (−604,93); di Rossi la sola coppia. Il credito di € 50,00 di Verdi è nelle sue rate (riporto), non in B.
    expect(cpcDi($c, $s, 'Bianchi', 'U2')['b'])->toMatchArray(['quote' => 120000, 'di_cui_non_emesse' => 0, 'coppie' => -60493, 'regolato' => 0, 'gia_versato_persona' => 0])
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['b'])->toMatchArray(['quote' => 0, 'coppie' => 60493])
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['b'])->toMatchArray(['quote' => 80000, 'coppie' => 0]);

    // La riga del condominio: Σ A = uscite per voce (4.200,00); Σ conguagli comuni = 200,00 = quote 4.000,00 − costi 4.200,00 con il segno opposto.
    $tot = ['a' => 0, 'personali' => 0, 'b' => 0, 'conguaglio' => 0, 'posizione' => 0, 'fuori' => 0, 'saldo' => 0];
    foreach ($c['persone'][$s['g']->id] as $perUnita) {
        foreach ($perUnita as $r) {
            foreach (cpcColonne($r) as $k => $v) {
                $tot[$k] += $v;
            }
        }
    }
    expect($tot)->toBe(['a' => 420000, 'personali' => 8000, 'b' => 400000, 'conguaglio' => 28000, 'posizione' => 30000, 'fuori' => 0, 'saldo' => 58000]);
});

it('il saldo di ogni persona è la posizione dell\'estratto conto più il conguaglio: la posizione sulle rate e i saldi fuori dalle rate sono l\'estratto conto, anche con la coppia non ancora in un piano', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    foreach (['Neri' => 'U1', 'Bianchi' => 'U2', 'Rossi' => 'U2', 'Verdi' => 'U3'] as $chi => $u) {
        $r = cpcDi($c, $s, $chi, $u);
        expect($r['posizione'] + $r['fuori_dalle_rate'])->toBe(lcdEstratto($this, $s, $chi), $chi)
            ->and($r['saldo'])->toBe(lcdEstratto($this, $s, $chi) + $r['conguaglio'], $chi);
    }
    // Rossi: estratto 604,93 (la coppia), conguaglio 30,25, saldo 635,18.
    expect(cpcDi($c, $s, 'Rossi', 'U2')['saldo'])->toBe(63518);
});

it('il credito dell\'anno prima con la rata 0, con tutte le rate e più grande della quota: il saldo resta la posizione più il conguaglio, mai l\'anno prima più A meno il versato', function (string $metodo, int $credito, int $saldo) {
    $s = cpcViaVerdi($this, ['metodo' => $metodo, 'saldo_verdi' => $credito]);
    $emesse = $metodo === 'rata_zero' ? [0, 1, 2, 3, 4] : [1, 2, 3, 4];
    cpcRateEIncassi($this, $s, $emesse, ['Verdi' => array_fill_keys([1, 2, 3, 4], null)]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');

    // A 840,00, B 800,00 (la spesa delle quote, senza il riporto), conguaglio +40,00; il saldo con l'estratto conto.
    expect($verdi['a'])->toBe(84000)->and($verdi['b']['quote'])->toBe(80000)->and($verdi['conguaglio'])->toBe(4000)
        ->and($verdi['saldo'])->toBe($saldo)
        ->and($verdi['posizione'] + $verdi['fuori_dalle_rate'])->toBe(lcdEstratto($this, $s, 'Verdi'));
})->with([
    // Rata 0 emessa con il credito in AVERE, rate da 200,00 pagate per intero: il credito resta (−50,00) e il saldo è −50,00 + 40,00.
    'rata 0' => ['rata_zero', -5000, -1000],
    // Tutte le rate: 4 × (200,00 − 12,50) pagate: posizione 0, saldo +40,00.
    'tutte le rate' => ['tutte_rate', -5000, 4000],
    // Prima rata con un credito di € 300,00, più della quota di € 200,00: la prima quota va a −100,00 (un credito), le altre pagate; saldo −100,00 + 40,00.
    'credito più grande della quota' => ['prima_rata', -30000, -6000],
]);

it('B su tutto il piano, emesse o no: la quarta rata non ancora emessa al 31/12 è in B («di cui non ancora emesse») e non nella posizione', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [1, 2, 3], ['Neri' => [1 => null, 2 => null, 3 => null]]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $neri = cpcDi($c, $s, 'Neri', 'U1');

    // B 2.000,00 di cui 500,00 non emessi; conguaglio +100,00; posizione 0 (tre rate pagate); saldo +100,00. Con le sole emesse sarebbe +600,00.
    expect($neri['b'])->toMatchArray(['quote' => 200000, 'di_cui_non_emesse' => 50000, 'totale' => 200000])
        ->and($neri['conguaglio'])->toBe(10000)->and($neri['posizione'])->toBe(0)->and($neri['saldo'])->toBe(10000);
});

it('il già versato dell\'unità si toglie dalla spesa dell\'unità prima dei giorni; quello della persona va in B', function () {
    // U1 aveva versato € 300,00 su Pulizie (dell'unità): il piano chiede a Neri 1.200,00 di Pulizie, rate 1.700,00, tutte pagate.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 30000, null]]]);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], ['Neri' => array_fill_keys([1, 2, 3, 4], null)]);
    cpcSpese($s);
    $neri = cpcDi(cpcLeggi($this, $s), $s, 'Neri', 'U1');

    // A = (1.650,00 − 300,00) + 450,00 = 1.800,00; B = 1.700,00; conguaglio +100,00; saldo +100,00.
    expect($neri['a'])->toBe(180000)->and($neri['gia_versato_unita'])->toBe(30000)->and($neri['b']['totale'])->toBe(170000)
        ->and($neri['conguaglio'])->toBe(10000)->and($neri['posizione'])->toBe(0)->and($neri['saldo'])->toBe(10000);

    // Della persona: lo stesso versato con Neri. A resta lorda (2.100,00), B = 1.700,00 + 300,00 = 2.000,00, conguaglio +100,00.
    $t = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 30000, 'Neri']]]);
    cpcRateEIncassi($this, $t, [1, 2, 3, 4], ['Neri' => array_fill_keys([1, 2, 3, 4], null)]);
    cpcSpese($t);
    $neri = cpcDi(cpcLeggi($this, $t), $t, 'Neri', 'U1');
    expect($neri['a'])->toBe(210000)->and($neri['b']['gia_versato_persona'])->toBe(30000)->and($neri['b']['totale'])->toBe(200000)
        ->and($neri['conguaglio'])->toBe(10000)->and($neri['saldo'])->toBe(10000);
});

it('il già versato dell\'unità dopo un passaggio: Rossi non paga i suoi giorni di un versato che l\'unità aveva già dato', function () {
    // U2 aveva versato € 300,00 su Pulizie: il piano chiede a Bianchi 600,00 di Pulizie (900 − 300), quote 900,00.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U2', 'pulizie', 30000, null]]]);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], ['Bianchi' => array_fill_keys([1, 2, 3, 4], null)]);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // Pulizie di U2: 990,00 − 300,00 = 690,00 per giorni: Bianchi 69.000 × 181/365 = 34.216,44, Rossi 34.783,56 → 342,16 e 347,84;
    // Manutenzioni 133,89 e 136,11. A: Bianchi 476,05, Rossi 483,95 (Σ = 960,00 = 1.260,00 − 300,00).
    expect(cpcDi($c, $s, 'Bianchi', 'U2')['a'])->toBe(47605)->and(cpcDi($c, $s, 'Rossi', 'U2')['a'])->toBe(48395)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['gia_versato_unita'] + cpcDi($c, $s, 'Rossi', 'U2')['gia_versato_unita'])->toBe(30000);
});

it('il metodo nuovo contro le quote dei piani della lettura comune, termine per termine: la spesa delle emesse è l\'emesso meno il riporto; le coppie sommano zero; il già versato sono le righe del riparto', function () {
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 30000, 'Neri']]]);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $lettura = app(LetturaDebiti::class);

    $quote = $lettura->quoteDeiPianiPerUnita((int) $s['c']->id, [(int) $s['piano']->id], '2026-12-31');
    $piani = $lettura->quoteDeiPiani((int) $s['c']->id, [(int) $s['piano']->id], '2026-12-31')[$s['g']->id];
    $spesaEmesse = 0;
    $riportoEmesse = 0;
    foreach ($quote as $perGestione) {
        foreach ($perGestione[$s['g']->id] ?? [] as $q) {
            $spesaEmesse += $q['spesa'] - $q['spesa_non_emessa'];
            $riportoEmesse += $q['riporto'] - $q['riporto_non_emesso'];
        }
    }
    // Emesse 4.000,00 − 300,00 (il già versato di Neri) − 50,00 (il credito di Verdi) = 3.650,00; il riporto −50,00; la spesa 3.700,00.
    expect($piani['quote']['emesse'] + $piani['pregresso']['emesse'])->toBe(365000)->and($riportoEmesse)->toBe(-5000)->and($spesaEmesse)->toBe(370000)
        ->and($spesaEmesse)->toBe($piani['quote']['emesse'] + $piani['pregresso']['emesse'] - $riportoEmesse);

    // Le coppie di B sommano zero; il già versato della persona in B è la riga del riparto.
    $coppie = 0;
    foreach ($c['persone'][$s['g']->id] as $perUnita) {
        foreach ($perUnita as $r) {
            $coppie += $r['b']['coppie'];
        }
    }
    $netting = -(int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'netting')->sum('importo');
    expect($coppie)->toBe(0)->and($netting)->toBe(30000)->and(cpcDi($c, $s, 'Neri', 'U1')['b']['gia_versato_persona'])->toBe($netting);
});

it('rinuncia e conguaglio annullato: B porta la cifra regolata fra le parti letta dal registro del passaggio, e la posizione resta com\'è', function (string $come) {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, $come === 'rinuncia' ? ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli'] : []);
    if ($come === 'annullato') {
        $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita']['U2'], $passaggio]),
            ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto'])->assertRedirect();
    }
    expect(Saldo::where('subentro_id', $passaggio->id)->count())->toBe(0);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // B: Bianchi 1.200,00 − 604,93 = 595,07, Rossi 604,93; conguaglio +29,75 e +30,25; nessuna coppia nei saldi: saldo = posizione + conguaglio.
    expect(cpcDi($c, $s, 'Bianchi', 'U2')['b'])->toMatchArray(['quote' => 120000, 'coppie' => 0, 'regolato' => -60493, 'totale' => 59507])
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['b'])->toMatchArray(['regolato' => 60493, 'totale' => 60493])
        ->and(cpcColonne(cpcDi($c, $s, 'Bianchi', 'U2')))->toMatchArray(['conguaglio' => 2975, 'posizione' => 0, 'fuori' => 0, 'saldo' => 2975])
        ->and(cpcColonne(cpcDi($c, $s, 'Rossi', 'U2')))->toMatchArray(['conguaglio' => 3025, 'posizione' => 0, 'fuori' => 0, 'saldo' => 3025])
        ->and($c['gestioni'][$s['g']->id]['passaggi'][$passaggio->id]['come'])->toBe('regolato');
})->with(['rinuncia', 'annullato']);

it('un passaggio regolato fra le parti senza la cifra nel registro (prima della 1.11.0-beta.42): il conguaglio delle sue persone non è disponibile, e lo dice', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli']);
    $registro = $passaggio->registro;
    unset($registro['regolato_fuori']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // Senza la cifra la riga non ha un conguaglio né un saldo (sarebbero −575,18 e +635,18, come se nessuno avesse regolato); la riga del
    // condominio somma le altre e conta le righe che salta.
    expect($c['gestioni'][$s['g']->id]['passaggi'][$passaggio->id]['come'])->toBe('non_disponibile')
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['non_disponibile'])->toContain('nota')
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['non_disponibile'])->toContain('nota')
        ->and(cpcDi($c, $s, 'Neri', 'U1')['non_disponibile'])->toBeNull();
    foreach (['Bianchi', 'Rossi'] as $chi) {
        expect([cpcDi($c, $s, $chi, 'U2')['conguaglio'], cpcDi($c, $s, $chi, 'U2')['saldo'], cpcDi($c, $s, $chi, 'U2')['b']['totale']])->toBe([null, null, null], $chi);
    }
    expect(cpcColonne(cpcDi($c, $s, 'Neri', 'U1')))->toMatchArray(['conguaglio' => 10000, 'saldo' => 40000])
        ->and($c['gestioni'][$s['g']->id]['totali'])->toMatchArray(['conguaglio' => 10000 + 4000, 'non_disponibili' => 2]);
});

it('le spese personali non chieste, per riga di fattura: Verdi +80,00; la lettura per unità torna con le righe, e la sua somma con le anticipazioni senza persona', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');

    expect($verdi['personali']['non_chieste'])->toBe(8000)->and($verdi['personali']['di_cui_esercizi_prima'])->toBe(0)
        ->and(collect($verdi['personali']['righe'])->pluck('riga_fattura_id')->all())->toBe([$riga])
        ->and($verdi['conguaglio'])->toBe(12000);

    $perUnita = app(LetturaDebiti::class)->anticipazioniPerUnita((int) $s['c']->id, '2026-12-31');
    $senza = app(LetturaDebiti::class)->senzaPersona((int) $s['c']->id, '2026-12-31');
    expect($perUnita)->toBe([$s['g']->id => [$s['unita']['U3']->id => 8000]])
        ->and(array_sum(array_map('array_sum', $perUnita)))->toBe($senza['totale'])
        ->and(array_map('array_sum', $perUnita))->toBe($senza['per_gestione'])
        ->and($c['gestioni'][$s['g']->id]['personali_aperte'])->toBe([$s['unita']['U3']->id => 8000]);
});

it('una spesa personale su U2 prima del passaggio è di chi era titolare il giorno della fattura: Bianchi 80,00, Rossi niente', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U2', 8000, '2026-03-15');
    $c = cpcLeggi($this, $s);

    expect(cpcDi($c, $s, 'Bianchi', 'U2')['personali']['non_chieste'])->toBe(8000)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['personali']['non_chieste'])->toBe(0)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['conguaglio'])->toBe(2975 + 8000);
});

it('un\'anticipazione del 2025 scritta sulla gestione del 2025 e non ancora chiesta arriva al foglio del 2026, «di cui nate in esercizi prima», e al saldo', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcSpesaPersonale(array_merge($s, ['g' => $g2025]), 'U3', 3000, '2025-11-20', $e2025);
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');
    $altre = $c['da_altre_gestioni']['persone'][$s['persone']['Verdi']->id][$s['unita']['U3']->id];

    // Verdi: conguaglio 40,00 sulla riga dell'ordinaria e 30,00 sulla riga da altre gestioni e da esercizi prima: +70,00; posizione 0; saldo +70,00.
    // Le anticipazioni aperte dell'unità di una gestione che l'esercizio non ha le dice la riga da altre gestioni.
    expect($altre['personali']['non_chieste'])->toBe(3000)->and($altre['personali']['di_cui_esercizi_prima'])->toBe(3000)
        ->and($verdi['conguaglio'] + $altre['conguaglio'])->toBe(7000)->and($verdi['saldo'] + $altre['saldo'])->toBe(7000)
        ->and($c['da_altre_gestioni']['personali_aperte'])->toBe([$s['unita']['U3']->id => 3000]);
});

it('un\'anticipazione del 2025 chiesta da un piano del 2026 esce dal foglio del 2026; il foglio del 2025, riletto, la dice ancora', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    $riga = cpcSpesaPersonale(array_merge($s, ['g' => $g2025]), 'U3', 3000, '2025-11-20', $e2025);
    // Il piano del 2026 la chiede: la sua riga ad personam del riparto con la riga di fattura.
    DB::table('righe_riparto')->insert(['piano_rate_id' => $s['piano']->id, 'anagrafica_id' => $s['persone']['Verdi']->id, 'immobile_id' => $s['unita']['U3']->id,
        'conto_id' => null, 'tipo' => 'ad_personam', 'importo' => 3000, 'riga_fattura_id' => $riga, 'versione_calcolo' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $c2026 = cpcLeggi($this, $s);
    $c2025 = (new \App\Services\Gestionale\ConsuntivoPerPersona())->perEsercizio($e2025->fresh());

    expect(cpcDi($c2026, $s, 'Verdi', 'U3')['personali']['non_chieste'])->toBe(0)
        ->and($c2025['alla'])->toBe('2025-12-31')
        ->and($c2025['persone'][$g2025->id][$s['persone']['Verdi']->id][$s['unita']['U3']->id]['personali']['non_chieste'])->toBe(3000)
        ->and($c2025['persone'][$g2025->id][$s['persone']['Verdi']->id][$s['unita']['U3']->id]['personali']['di_cui_esercizi_prima'])->toBe(0);
});

it('l\'inquilino con la ripartizione per ruolo riceve il conguaglio della sua parte delle voci, come le sue rate', function () {
    // Gialli inquilino di U1 tutto l'anno, il 30 % delle voci all'inquilino: Pulizie 1.650,00 → 495,00 e 1.155,00; Manutenzioni 450,00 → 135,00 e
    // 315,00. A Gialli 630,00, Neri 1.470,00; le rate di U1 (2.000,00): 600,00 e 1.400,00. Conguaglio +30,00 e +70,00; nessun incasso.
    $s = cpcViaVerdi($this, ['inquilino' => ['U1', 'Gialli', 30, '2020-01-01', null]]);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], []);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    expect(cpcColonne(cpcDi($c, $s, 'Gialli', 'U1')))->toBe(['a' => 63000, 'personali' => 0, 'b' => 60000, 'conguaglio' => 3000, 'posizione' => 60000, 'fuori' => 0, 'saldo' => 63000])
        ->and(cpcColonne(cpcDi($c, $s, 'Neri', 'U1')))->toBe(['a' => 147000, 'personali' => 0, 'b' => 140000, 'conguaglio' => 7000, 'posizione' => 140000, 'fuori' => 0, 'saldo' => 147000]);
});

it('il credito dell\'anno prima entrato in una rata non ancora emessa sta fra i saldi fuori dalle rate, come nell\'estratto conto', function () {
    // Prima rata in bozza: il riporto di −50,00 di Verdi non è sul giornale. Verdi paga le rate 2–4 (600,00): posizione 0, fuori −50,00,
    // conguaglio +40,00, saldo −10,00.
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [2, 3, 4], ['Verdi' => [2 => null, 3 => null, 4 => null]]);
    cpcSpese($s);
    $verdi = cpcDi(cpcLeggi($this, $s), $s, 'Verdi', 'U3');

    expect(cpcColonne($verdi))->toMatchArray(['b' => 80000, 'conguaglio' => 4000, 'posizione' => 0, 'fuori' => -5000, 'saldo' => -1000])
        ->and($verdi['posizione'] + $verdi['fuori_dalle_rate'])->toBe(lcdEstratto($this, $s, 'Verdi'));
});

it('una rata del 2025 non pagata su una gestione che l\'esercizio non ha entra nel saldo come «altre gestioni»: il saldo resta l\'estratto conto più il conguaglio', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');
    $altre = $c['da_altre_gestioni']['persone'][$s['persone']['Verdi']->id][$s['unita']['U3']->id];

    // La rata di Verdi del 2025, € 200,00, sulla riga da altre gestioni; il conguaglio del 2026 +40,00: saldo +240,00.
    expect($verdi['posizione'])->toBe(0)->and($altre['posizione'])->toBe(20000)->and($verdi['saldo'] + $altre['saldo'])->toBe(24000)
        ->and($verdi['saldo'] + $altre['saldo'])->toBe(lcdEstratto($this, $s, 'Verdi') + $verdi['conguaglio']);
});

it('«non scritto» nella successione: la posizione resta com\'è, B divide la spesa per giorni con la cifra del registro', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-07-05 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U2']]), [
        'tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => $s['righe']['Bianchi'], 'decorrenza' => '2026-07-01', 'quota' => 100,
        'eredi' => [['anagrafica_id' => $s['persone']['Rossi']->id, 'quota' => 100]], 'arretrato' => \App\Models\Gestionale\Subentro::ARRETRATO_AL_DEFUNTO,
        'erede_di_riferimento' => $s['persone']['Rossi']->id, 'conguaglio' => \App\Models\Gestionale\Subentro::NON_SCRIVERE_IL_CONGUAGLIO,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Successione del 01/07, letto il pannello',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $passaggio = Subentro::where('immobile_id', $s['unita']['U2']->id)->latest('id')->firstOrFail();
    expect($passaggio->conguaglioNonScritto())->toBeTrue()->and(Saldo::where('subentro_id', $passaggio->id)->count())->toBe(0);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // Le rate del 2026 restano a nome di Bianchi, pagate: B 595,07 e 604,93, conguaglio +29,75 e +30,25, saldi +29,75 e +30,25.
    expect(cpcDi($c, $s, 'Bianchi', 'U2')['b'])->toMatchArray(['quote' => 120000, 'regolato' => -60493, 'totale' => 59507])
        ->and(cpcColonne(cpcDi($c, $s, 'Bianchi', 'U2')))->toMatchArray(['conguaglio' => 2975, 'posizione' => 0, 'fuori' => 0, 'saldo' => 2975])
        ->and(cpcColonne(cpcDi($c, $s, 'Rossi', 'U2')))->toMatchArray(['b' => 60493, 'conguaglio' => 3025, 'posizione' => 0, 'fuori' => 0, 'saldo' => 3025])
        ->and($c['gestioni'][$s['g']->id]['passaggi'][$passaggio->id]['come'])->toBe('non_scritto');
});

it('le bozze passate a chi entra: A non cambia, B sì, una volta sola nella quota di chi entra', function () {
    // Le rate 3 e 4 scadono dopo il rogito e sono in bozza al passaggio: passano a Rossi (2 × 300,00). La coppia sulle quote emesse e su quelle che
    // restano: 604,93 − 600,00 = 4,93. B Bianchi 600,00 − 4,93 = 595,07; Rossi 600,00 + 4,93 = 604,93. A come senza le bozze: 624,82 e 635,18.
    $s = cpcViaVerdi($this);
    DB::table('rate')->where('id', lcdRata($s, 3))->update(['data_scadenza' => '2026-07-15']);
    DB::table('rate')->where('id', lcdRata($s, 4))->update(['data_scadenza' => '2026-10-15']);
    $this->travelTo('2026-01-15 10:00:00');
    lcdEmetti($this, $s, [1, 2], '2026-01-15');
    $passaggio = cpcPassaggio($this, $s);
    expect(Saldo::where('subentro_id', $passaggio->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($v) => (int) $v)->all())
        ->toEqual([$s['persone']['Bianchi']->id => -493, $s['persone']['Rossi']->id => 493]);
    $this->travelTo('2026-07-20 10:00:00');
    lcdEmetti($this, $s, [3, 4], '2026-07-20');
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    expect(cpcDi($c, $s, 'Bianchi', 'U2')['b'])->toMatchArray(['quote' => 60000, 'coppie' => -493, 'totale' => 59507])
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['b'])->toMatchArray(['quote' => 60000, 'coppie' => 493, 'totale' => 60493])
        ->and([cpcDi($c, $s, 'Bianchi', 'U2')['a'], cpcDi($c, $s, 'Rossi', 'U2')['a']])->toBe([62482, 63518])
        ->and([cpcDi($c, $s, 'Bianchi', 'U2')['conguaglio'], cpcDi($c, $s, 'Rossi', 'U2')['conguaglio']])->toBe([2975, 3025]);
    foreach (['Bianchi', 'Rossi'] as $chi) {
        expect(cpcDi($c, $s, $chi, 'U2')['saldo'])->toBe(lcdEstratto($this, $s, $chi) + cpcDi($c, $s, $chi, 'U2')['conguaglio'], $chi);
    }
});

it('un saldo del 2026 scritto a mano con l\'origine nel 2025 e una rata del 2025 ancora aperta: la riga avvisa che la stessa cifra può contare due volte, e non toglie niente', function (bool $conRiporto) {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    if ($conRiporto) {
        cpcSaldoDaAnnoPrima($s, $e2025, 'Verdi', 'U3', 20000);
    }
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');
    $altre = $c['da_altre_gestioni']['persone'][$s['persone']['Verdi']->id][$s['unita']['U3']->id];

    if (! $conRiporto) {
        // Senza il saldo scritto a mano: altre gestioni 200,00, saldo 240,00, nessun avviso.
        expect($altre['posizione'])->toBe(20000)->and($verdi['saldo'] + $altre['saldo'])->toBe(24000)->and($verdi['avviso_anno_prima'])->toBeNull();

        return;
    }
    // Con il riporto: le cifre di oggi, non tolte (altre gestioni 200,00, fuori dalle rate 200,00, saldo 440,00), e l'avviso con le due cifre.
    expect($altre['posizione'])->toBe(20000)->and($verdi['fuori_dalle_rate'])->toBe(20000)->and($verdi['saldo'] + $altre['saldo'])->toBe(44000)
        ->and($verdi['avviso_anno_prima'])->toMatchArray(['altre_gestioni' => 20000, 'personali_esercizi_prima' => 0, 'saldi_con_origine' => 20000, 'esercizi_origine' => [$e2025->id]])
        ->and($verdi['avviso_anno_prima']['perche'])->toContain('due volte');
})->with(['senza il saldo scritto a mano' => [false], 'con il saldo scritto a mano' => [true]]);

it('un saldo del 2026 scritto a mano con l\'origine nel 2025 e una spesa personale del 2025 non chiesta: l\'avviso, con le cifre di oggi', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcSpesaPersonale(array_merge($s, ['g' => $g2025]), 'U3', 3000, '2025-11-20', $e2025);
    // Il foglio del 2025 dà a Verdi 30,00; l'amministratore li riporta a mano nei saldi del 2026.
    $c2025 = (new \App\Services\Gestionale\ConsuntivoPerPersona())->perEsercizio($e2025->fresh(), [], '2025-12-31');
    expect($c2025['persone'][$g2025->id][$s['persone']['Verdi']->id][$s['unita']['U3']->id]['saldo'])->toBe(3000);
    cpcSaldoDaAnnoPrima($s, $e2025, 'Verdi', 'U3', 3000);
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');
    $altre = $c['da_altre_gestioni']['persone'][$s['persone']['Verdi']->id][$s['unita']['U3']->id];

    // Personali 30,00 (nate prima, sulla riga da altre gestioni), fuori dalle rate 30,00, conguaglio 40,00 + 30,00: saldo 100,00.
    expect($altre['personali']['di_cui_esercizi_prima'])->toBe(3000)->and($verdi['fuori_dalle_rate'])->toBe(3000)->and($verdi['saldo'] + $altre['saldo'])->toBe(10000)
        ->and($verdi['avviso_anno_prima'])->toMatchArray(['altre_gestioni' => 0, 'personali_esercizi_prima' => 3000, 'saldi_con_origine' => 3000]);
});

it('due spese personali sulla stessa unità in due giorni con titolari diversi: ognuna al titolare del suo giorno', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U2', 8000, '2026-03-15');
    cpcSpesaPersonale($s, 'U2', 5000, '2026-09-15');
    $c = cpcLeggi($this, $s);

    expect(cpcDi($c, $s, 'Bianchi', 'U2')['personali']['non_chieste'])->toBe(8000)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['personali']['non_chieste'])->toBe(5000);
});

it('le posizioni lette a gruppi di una persona sono quelle lette tutte insieme, anche con un anticipo', function () {
    $s = cpcViaVerdi($this);
    $this->travelTo('2026-01-15 10:00:00');
    lcdEmetti($this, $s, [1, 2, 3, 4], '2026-01-15');
    $this->travelTo('2026-03-10 10:00:00');
    // Neri, la prima persona dei gruppi, versa le quattro rate; e ha un anticipo di € 70,00 sul conto «Anticipi», senza rata (un dato di prima,
    // come lo scrive la prova della lettura comune). Verdi versa la sua prima rata.
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 50000, lcdQuota($s, 'Neri', 2) => 50000, lcdQuota($s, 'Neri', 3) => 50000, lcdQuota($s, 'Neri', 4) => 50000], '2026-03-03');
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
        'data_registrazione' => '2026-03-05', 'data_competenza' => '2026-03-05', 'numero_protocollo' => 'INC-CPC-ANT', 'causale' => 'Bonifico',
        'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $s['cassa']->conto_contabile_id, 'anagrafica_id' => null, 'rata_id' => null, 'immobile_id' => null, 'tipo_riga' => 'dare', 'importo' => 7000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'anticipi_condomini')->value('id'),
            'anagrafica_id' => $s['persone']['Neri']->id, 'rata_id' => null, 'immobile_id' => $s['unita']['U1']->id, 'tipo_riga' => 'avere', 'importo' => 7000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    lcdIncassa($this, $s, 'Verdi', [lcdQuota($s, 'Verdi', 1) => (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'Verdi', 1))->value('importo')], '2026-03-04');
    $l = app(LetturaDebiti::class);
    $tutte = $l->posizioniPerUnita((int) $s['c']->id, '2026-12-31', null, 100000);

    // Neri: le rate pagate e l'anticipo di 70,00, a credito; Verdi le rate 2–4, 600,00.
    expect(array_sum(array_map('array_sum', $tutte[$s['persone']['Neri']->id])))->toBe(-7000)
        ->and(app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id, '2026-12-31')['anticipi'])->toBe(-7000)
        ->and(array_sum(array_map('array_sum', $tutte[$s['persone']['Verdi']->id])))->toBe(60000)
        ->and(cpcOrdinate($l->posizioniPerUnita((int) $s['c']->id, '2026-12-31', null, 1)))->toBe(cpcOrdinate($tutte));
});


<?php

/**
 * Decisione 57 (1.11.0-beta.43, D2): quando un usufrutto si estingue e sull'unità ce n'è un altro in corso, torna piena solo la
 * nuda dell'usufrutto che finisce, e chi esce non torna nudo di sé stesso sull'altra parte.
 *
 * Fino alla .42 tornavano pieni tutti i nudi in corso: alla fine dell'usufrutto di Ugo tornava piena anche la nuda di Carlo,
 * sotto l'usufrutto di Elsa ancora vivo (l'unità arrivava al 150 %), e il conguaglio di Ugo si divideva fra Mara e Carlo.
 *
 * Cosa copre, con le cifre fatte a mano (voce € 1.200,00 sull'«Usufruttuario», l'usufrutto di Ugo vale metà: 60000 l'anno; dal
 * 1/4, 275 giorni: 60000 × 275/365 = 45205,48 → 45205):
 * - **titolari censiti a mano**, nudi che valgono più dell'usufrutto: sceglie l'amministratore; senza scelta si rifiuta;
 * - **la seconda forma**: il nudo dell'altra metà è chi esce; l'unico altro nudo vale quanto l'usufrutto e torna pieno da sé;
 * - **un usufrutto nato da una costituzione**: il registro di quel passaggio dice qual è la nuda, anche dopo una sua vendita;
 * - **una nuda sola sotto due usufrutti** (due genitori donano con riserva, poi ne muore uno): consolidamento di legge, il figlio
 *   torna pieno per la parte dell'usufrutto che finisce e resta nudo del resto (la scelta dell'accrescimento arriva con la
 *   successione);
 * - **chi esce è anche nudo**, senza un altro usufrutto (la nuda comprata mentre era usufruttuario): torna pieno della sua
 *   parte, che resta a suo nome — nessuna coppia con sé stesso; un'unità dove è il solo nudo resta fuori dal conguaglio.
 */

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function edupPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)) . "{$s['unita']->id}-{$n}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'EDUPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

function edupRiga(array $s, Anagrafica $p, string $ruolo, float $quota): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * L'unità con due usufrutti sulle due metà, censiti a mano: Ugo usufruttuario e Mara nuda della prima, Elsa usufruttuaria e il
 * nudo dato della seconda. Voce sull'«Usufruttuario», rate emesse fino al 31/3.
 *
 * @return array{0: array, 1: array<string, Anagrafica>, 2: array<string, int>}
 */
function edupDueUsufrutti(?string $nudoDellaSeconda = 'Carlo Nudo'): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $p = ['ugo' => $s['v'], 'elsa' => $s['a'], 'mara' => edupPersona($s, 'Mara Nuda')];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $righe = ['ugo' => $s['rigaV'], 'mara' => edupRiga($s, $p['mara'], 'nuda_proprietario', 50), 'elsa' => edupRiga($s, $p['elsa'], 'usufruttuario', 50)];
    if ($nudoDellaSeconda === null) {
        $righe['ugo_nudo'] = edupRiga($s, $p['ugo'], 'nuda_proprietario', 50);
    } else {
        $p['carlo'] = edupPersona($s, $nudoDellaSeconda);
        $righe['carlo'] = edupRiga($s, $p['carlo'], 'nuda_proprietario', 50);
    }
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    return [$s, $p, $righe];
}

function edupEstinzione(array $s, int $rigaUsufrutto, ?array $nudi = null, string $dal = '2026-04-01'): array
{
    return ruPassaggio('estinzione', $rigaUsufrutto, null, $dal, 50) + ['ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, atto letto']
        + ($nudi !== null ? ['nudi_che_tornano' => $nudi] : []);
}

/** Le righe di una persona sull'unità: [ruolo, quota, dal, al]. */
function edupRighe(array $s, Anagrafica $p): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $p->id)->orderBy('id')->get()
        ->map(fn ($r) => [$r->tipologia, (float) $r->quota, substr((string) $r->data_inizio, 0, 10), $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10)])->all();
}

/** I nudi che tornano pieni secondo l'anteprima: da dove viene la scelta e le righe. */
function edupNudi(array $an): array
{
    return ['da' => $an['nudi']['da'], 'righe' => $an['nudi']['righe']];
}

function edupSaldo(Anagrafica $p): int
{
    return (int) DB::table('saldi')->whereNotNull('subentro_id')->where('anagrafica_id', $p->id)->sum('saldo_iniziale');
}

it('D2 — due usufrutti censiti a mano: senza la scelta dei nudi l\'estinzione si rifiuta, e la frase dice perché', function () {
    [$s, , $righe] = edupDueUsufrutti();

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo']))
        ->assertStatus(422)->assertJsonValidationErrors(['nudi_che_tornano'])
        ->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => str_contains($m, 'c\'è un altro usufrutto in corso') && str_contains($m, 'scegli quali tornano proprietari pieni'));
});

it('D2 — due usufrutti censiti a mano: l\'amministratore sceglie Mara; Mara torna piena e riceve tutto il conguaglio di Ugo, Carlo resta nudo proprietario', function () {
    [$s, $p, $righe] = edupDueUsufrutti();

    $an = ruAnteprima($this, $s, edupEstinzione($s, $righe['ugo'], [$righe['mara']]));
    ruRegistra($this, $s, edupEstinzione($s, $righe['ugo'], [$righe['mara']]));

    expect(edupNudi($an))->toBe(['da' => 'scelta', 'righe' => [$righe['mara']]])
        // Carlo: la sua metà è ancora in usufrutto a Elsa. Prima tornava pieno anche lui, e la somma faceva 150 %.
        ->and(edupRighe($s, $p['carlo']))->toBe([['nuda_proprietario', 50.0, '2019-01-01', null]])
        ->and(edupRighe($s, $p['mara']))->toBe([['nuda_proprietario', 50.0, '2019-01-01', '2026-03-31'], ['proprietario', 50.0, '2026-04-01', null]])
        // A mano: 60000 × 275/365 = 45205,48 → 45205, tutto da Mara. Prima Mara +22603 e Carlo +22602.
        ->and([edupSaldo($p['mara']), edupSaldo($p['carlo']), edupSaldo($p['ugo'])])->toBe([45205, 0, -45205]);
});

it('D2 — la scelta deve valere quanto l\'usufrutto: Mara e Carlo insieme (100 %) per un usufrutto del 50 % si rifiutano', function () {
    [$s, , $righe] = edupDueUsufrutti();

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo'], [$righe['mara'], $righe['carlo']]))
        ->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => str_contains($m, 'valgono il 100 %') && str_contains($m, 'il 50 %'));
});

it('D2, seconda forma — il nudo dell\'altra metà è Ugo stesso: Ugo non torna pieno di una metà ancora in usufrutto a Elsa, e non c\'è una coppia con sé stesso; Mara torna piena da sé', function () {
    [$s, $p, $righe] = edupDueUsufrutti(null);

    $an = ruAnteprima($this, $s, edupEstinzione($s, $righe['ugo']));
    ruRegistra($this, $s, edupEstinzione($s, $righe['ugo']));

    // L'unico altro nudo, Mara, vale quanto l'usufrutto che finisce: torna piena senza chiedere. Prima la fermata dei «più nudi»
    // nascondeva il guasto: Ugo diventava proprietario pieno della metà di Elsa.
    expect(edupNudi($an))->toBe(['da' => 'tutti', 'righe' => [$righe['mara']]])
        // Rilievo GT6: con un altro usufrutto in corso la nota sull'accrescimento c'è anche quando i nudi tornano da sé.
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('se l\'atto prevede che l\'usufrutto si accresca all\'altro usufruttuario')
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $p['ugo']->id)->where('tipologia', 'proprietario')->count())->toBe(0)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->where('anagrafica_id', $p['ugo']->id)->where('saldo_iniziale', '>', 0)->count())->toBe(0)
        ->and([edupSaldo($p['mara']), edupSaldo($p['ugo'])])->toBe([45205, -45205]);
});

it('D2 — un usufrutto nato da una costituzione: il registro dice qual è la nuda, anche dopo che è stata venduta; il modulo non chiede niente', function () {
    // Ugo e Bice proprietari al 50 %. Ugo costituisce l'usufrutto a Elsa, Bice a Carlo; poi Ugo vende la sua nuda a Fede.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    $bice = edupPersona($s, 'Bice Seconda');
    $carlo = edupPersona($s, 'Carlo Usufruttuario');
    $fede = edupPersona($s, 'Fede Compratrice');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaBice = edupRiga($s, $bice, 'proprietario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-01-31');
    $passa = fn (array $dati) => ruRegistra($this, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Atto letto, passaggio di prova']);
    $passa(ruPassaggio('costituzione', $s['rigaV'], $elsa, '2026-02-01', 50));
    $passa(ruPassaggio('costituzione', $rigaBice, $carlo, '2026-02-01', 50));
    $rigaNudaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    $passa(ruPassaggio('nuda', $rigaNudaUgo, $fede, '2026-03-01', 50));
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $elsa->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $rigaFede = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $fede->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');

    $an = ruAnteprima($this, $s, edupEstinzione($s, $rigaElsa, dal: '2026-06-01'));
    $passa(edupEstinzione($s, $rigaElsa, dal: '2026-06-01'));

    // La nuda dell'usufrutto di Elsa è quella che Ugo si è tenuto alla costituzione, oggi di Fede: torna piena Fede, non Bice.
    expect(edupNudi($an))->toBe(['da' => 'registro', 'righe' => [$rigaFede]])
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $fede->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('quota'))->toEqual(50)
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $bice->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->count())->toBe(1);
});

it('D2 — una nuda proprietà sola sotto due usufrutti (due genitori donano con riserva al figlio, poi muore il padre): consolidamento di legge, il figlio torna pieno per la metà del padre e resta nudo dell\'altra, sotto l\'usufrutto della madre', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = edupPersona($s, 'Mara Figlia');
    $rita = edupPersona($s, 'Rita Madre');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $rigaMara = edupRiga($s, $mara, 'nuda_proprietario', 100);
    edupRiga($s, $rita, 'usufruttuario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $an = ruAnteprima($this, $s, edupEstinzione($s, $s['rigaV']));
    ruRegistra($this, $s, edupEstinzione($s, $s['rigaV']));

    // Prima della .43 Mara diventava piena del 100 % mentre Rita restava usufruttuaria del 50 %: l'unità sommava 150 %.
    expect(edupNudi($an))->toBe(['da' => 'consolidamento', 'righe' => [$rigaMara]])
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('Mara Figlia risulterà proprietario pieno per il 50 % e resterà nudo proprietario dell\'altro 50 % dal 1 aprile 2026')
        ->and(edupRighe($s, $mara))->toBe([['nuda_proprietario', 100.0, '2019-01-01', '2026-03-31'], ['proprietario', 50.0, '2026-04-01', null], ['nuda_proprietario', 50.0, '2026-04-01', null]])
        ->and(edupRighe($s, $rita))->toBe([['usufruttuario', 50.0, '2019-01-01', null]])
        // A mano: l'usufrutto di Ugo vale metà della voce, 60000; dal 1/4, 275 giorni: 45205,48 → 45205, tutto a Mara.
        ->and([edupSaldo($mara), edupSaldo($s['v'])])->toBe([45205, -45205]);
});

it('D2, seconda forma con il box — sul box Ugo non torna nudo di sé stesso e non c\'è una coppia con sé stesso: anche lì torna piena solo Mara', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    $mara = edupPersona($s, 'Mara Nuda');
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $titolare = fn ($unita, Anagrafica $p, string $ruolo) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => 50, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    // Su tutte e due le unità: Ugo usufruttuario di metà (nuda di Mara), Elsa usufruttuaria dell'altra metà (nuda di Ugo).
    $titolare($s['unita'], $mara, 'nuda_proprietario');
    $titolare($s['unita'], $elsa, 'usufruttuario');
    $titolare($s['unita'], $ugo, 'nuda_proprietario');
    $titolare($box, $ugo, 'usufruttuario');
    $titolare($box, $mara, 'nuda_proprietario');
    $titolare($box, $elsa, 'usufruttuario');
    $titolare($box, $ugo, 'nuda_proprietario');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $dati = ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 50, [$box->id]) + ['ho_letto' => true, 'nota_cancello' => 'Estinzione con il box, atto letto'];
    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);

    // A mano, dal 1/4 (275 giorni): appartamento 108000 × 50 % × 275/365 = 40684,93 → 40685; box 12000 × 50 % × 275/365 =
    // 4520,55 → 4521. Tutto a Mara. Prima, sul box, il debito si divideva fra Mara e Ugo: Ugo −2260 e +2260, con sé stesso.
    $saldi = fn (int $u) => DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $u)->orderBy('id')->get(['anagrafica_id', 'saldo_iniziale'])
        ->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->saldo_iniziale])->all();
    expect($saldi($s['unita']->id))->toBe([[$ugo->id, -40685], [$mara->id, 40685]])
        ->and($saldi($box->id))->toBe([[$ugo->id, -4521], [$mara->id, 4521]])
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->not->toContain('a Venditore Ugo (50 %)')
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'proprietario')->count())->toBe(0);
});

it('D2 — un usufrutto sommato da una riserva, con un altro usufrutto in corso: il registro della riserva conosce solo metà della nuda, e allora sceglie l\'amministratore', function () {
    // Ugo pieno di un quarto e usufruttuario di un quarto (nuda di Bice); Rita usufruttuaria di metà (nuda di Carla). Il 1/4
    // Ugo vende con riserva il suo quarto pieno a Nora: il suo usufrutto diventa la metà, sommato. Il 1/9 si estingue.
    $s = ruScenario('prima_rata', 0, genera: false);
    $ugo = $s['v'];
    $bice = edupPersona($s, 'Bice Nuda');
    $rita = edupPersona($s, 'Rita Usufruttuaria');
    $carla = edupPersona($s, 'Carla Nuda');
    $nora = edupPersona($s, 'Nora Compratrice');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 25]);
    edupRiga($s, $ugo, 'usufruttuario', 25);
    $rigaBice = edupRiga($s, $bice, 'nuda_proprietario', 25);
    edupRiga($s, $rita, 'usufruttuario', 50);
    edupRiga($s, $carla, 'nuda_proprietario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');
    ruRegistra($this, $s, ruPassaggio('riserva', $s['rigaV'], $nora, '2026-04-01', 25) + ['ho_letto' => true, 'nota_cancello' => 'Riserva letta, atto di prova']);
    $usufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $rigaNora = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $nora->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');

    // Prima il registro della riserva dava solo Nora, e Bice restava nuda di un quarto su cui l'usufrutto era finito.
    $senza = ruPassaggio('estinzione', $usufrutto, null, '2026-09-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Estinzione letta, atto di prova'];
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $senza)
        ->assertStatus(422)->assertJsonValidationErrors(['nudi_che_tornano']);
    $an = ruAnteprima($this, $s, $senza + ['nudi_che_tornano' => [$rigaBice, $rigaNora]]);

    expect(edupNudi($an))->toBe(['da' => 'scelta', 'righe' => [$rigaBice, $rigaNora]]);
});

it('D2 — chi esce è anche il nudo proprietario dell\'unità, e non c\'è un altro usufrutto: all\'estinzione torna pieno, e non c\'è niente da conguagliare', function () {
    // Ugo usufruttuario di tutto e nudo di tutto (la nuda comprata mentre era usufruttuario). Nella .42 Ugo tornava pieno, ma il
    // conguaglio scriveva una coppia con sé stesso; la regola «chi esce non torna mai nudo di sé stesso», presa anche senza un
    // altro usufrutto, non faceva tornare pieno nessuno.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $rigaNuda = edupRiga($s, $s['v'], 'nuda_proprietario', 100);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $an = ruAnteprima($this, $s, edupEstinzione($s, $s['rigaV']) + ['quota' => 100]);
    ruRegistra($this, $s, edupEstinzione($s, $s['rigaV']) + ['quota' => 100]);

    expect(edupNudi($an))->toBe(['da' => 'tutti', 'righe' => [$rigaNuda]])
        ->and($an['rate']['conguaglio']['coppie'] ?? [])->toBe([])
        // Rilievo T1: l'apertura del blocco rate non annuncia un conguaglio «fra Venditore Ugo e Venditore Ugo».
        ->and(implode(' ', $an['rate']['frasi']))->toContain('Venditore Ugo torna proprietario pieno: le quote dal 1 aprile 2026 restano a suo nome, senza conguaglio.')
        ->not->toContain('fra Venditore Ugo e Venditore Ugo')
        ->and(edupRighe($s, $s['v']))->toBe([['usufruttuario', 100.0, '2019-01-01', '2026-03-31'], ['nuda_proprietario', 100.0, '2019-01-01', '2026-03-31'], ['proprietario', 100.0, '2026-04-01', null]])
        // Le quote dal 1/4 restano a Ugo, che le deve ora da proprietario pieno: nessuna coppia, nemmeno con sé stesso.
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0);
});

it('D2 — chi esce è nudo di metà e un altro nudo dell\'altra metà, nessun altro usufrutto: tornano pieni tutti e due, e il conguaglio passa solo la metà dell\'altro', function () {
    // Ugo usufruttuario di tutto, nudo di metà; Mara nuda dell'altra metà. Voce € 1.200,00 sull'«Usufruttuario», tutta di Ugo.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = edupPersona($s, 'Mara Nuda');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $rigaUgo = edupRiga($s, $s['v'], 'nuda_proprietario', 50);
    $rigaMara = edupRiga($s, $mara, 'nuda_proprietario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $an = ruAnteprima($this, $s, edupEstinzione($s, $s['rigaV']) + ['quota' => 100]);
    $passaggio = ruRegistra($this, $s, edupEstinzione($s, $s['rigaV']) + ['quota' => 100]);

    // A mano: dal 1/4, 275 giorni: 120000 × 275/365 = 90410,96 → 90411. Per quota, 50 e 50: 45205,5 ciascuno, il centesimo in
    // più al primo nudo (Ugo, a parità di resto vince chi viene prima). La parte di Ugo, 45206, resta a lui; a Mara 45205.
    expect(edupNudi($an))->toBe(['da' => 'tutti', 'righe' => [$rigaUgo, $rigaMara]])
        ->and(implode(' ', $an['rate']['frasi']))->toContain('Il conguaglio fra Venditore Ugo e Mara Nuda, che torna proprietario pieno,')
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->toContain('€ 452,06 restano a Venditore Ugo (50 %), € 452,05 a Mara Nuda (50 %); il credito di Venditore Ugo scende quindi a € 452,05.')
        ->and(edupRighe($s, $s['v']))->toBe([['usufruttuario', 100.0, '2019-01-01', '2026-03-31'], ['nuda_proprietario', 50.0, '2019-01-01', '2026-03-31'], ['proprietario', 50.0, '2026-04-01', null]])
        ->and(edupRighe($s, $mara))->toBe([['nuda_proprietario', 50.0, '2019-01-01', '2026-03-31'], ['proprietario', 50.0, '2026-04-01', null]])
        // Una coppia sola, con Mara: nessuna riga di Ugo con sé stesso (nella somma si compenserebbe, e non si vedrebbe).
        ->and(collect($an['rate']['conguaglio']['coppie'])->pluck('anagrafica_entrante_id')->all())->toBe([$mara->id])
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->orderBy('id')->get(['anagrafica_id', 'saldo_iniziale'])->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->saldo_iniziale])->all())
        ->toBe([[$s['v']->id, -45205], [$mara->id, 45205]])
        // Il passaggio nomina chi torna pieno: Mara, non chi esce.
        ->and((int) $passaggio->anagrafica_entrante_id)->toBe($mara->id);
});

it('D2 — con il box: sull\'appartamento torna piena Mara, sul box il nudo è Ugo stesso; il box resta fuori dal conguaglio, e la frase dice che lì le quote restano a Ugo', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $mara = edupPersona($s, 'Mara Nuda');
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $titolare = fn ($unita, Anagrafica $p, string $ruolo) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $titolare($s['unita'], $mara, 'nuda_proprietario');
    $titolare($box, $ugo, 'usufruttuario');
    $titolare($box, $ugo, 'nuda_proprietario');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $dati = ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100, [$box->id]) + ['ho_letto' => true, 'nota_cancello' => 'Estinzione con il box, atto letto'];
    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);

    // A mano, dal 1/4 (275 giorni): appartamento 108000 × 275/365 = 81369,86 → 81370, a Mara; box 12000 × 275/365 = 9041,10 →
    // 9041, che resta a Ugo, proprietario pieno del box dal 1/4.
    $saldi = fn (int $u) => DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $u)->orderBy('id')->get(['anagrafica_id', 'saldo_iniziale'])
        ->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->saldo_iniziale])->all();
    expect($saldi($s['unita']->id))->toBe([[$ugo->id, -81370], [$mara->id, 81370]])
        ->and($saldi($box->id))->toBe([])
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->toContain('Per Box 12 il nudo proprietario è Venditore Ugo: torna proprietario pieno, e le sue quote dal 1 aprile 2026 restano a suo nome, senza conguaglio.')
        // Il box non entra nel calcolo: nessuna frase con una coppia di Ugo con sé stesso.
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->not->toContain('debito € 90,41 a Venditore Ugo')
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $box->id)->where('anagrafica_id', $ugo->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('quota'))->toEqual(100);
});

it('D2 — un\'estinzione registrata dalla .42 aveva fatto tornare pieno anche chi esce, nudo dell\'altra metà ancora in usufrutto: la genealogia di un passaggio dopo dà la parte censita a Mara, come allora, e non si ferma', function () {
    [$s, $p, $righe] = edupDueUsufrutti(null);
    $fede = edupPersona($s, 'Fede Compratrice');
    ruRegistra($this, $s, edupEstinzione($s, $righe['ugo']));
    // La forma della .42: la nuda di Ugo (sotto l'usufrutto di Elsa) chiusa il 31/3 e una piena di Ugo dal 1/4, scritte anche nel
    // registro dell'estinzione, come le scriveva la regola «tornano pieni tutti i nudi».
    $estinzione = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->latest('id')->firstOrFail();
    DB::table('anagrafica_immobile')->where('id', $righe['ugo_nudo'])->update(['data_fine' => '2026-03-31']);
    $piena = edupRiga($s, $p['ugo'], 'proprietario', 50);
    DB::table('anagrafica_immobile')->where('id', $piena)->update(['data_inizio' => '2026-04-01']);
    $registro = $estinzione->registro;
    $registro['versione'] = 2;
    $registro['righe'][] = ['operazione' => 'chiusa', 'id' => $righe['ugo_nudo'], 'prima' => ['data_fine' => null], 'dopo' => ['data_fine' => '2026-03-31']];
    $registro['righe'][] = ['operazione' => 'aperta', 'id' => $piena, 'dopo' => ['anagrafica_id' => $p['ugo']->id, 'tipologia' => 'proprietario', 'quota' => 50]];
    $estinzione->update(['registro' => $registro]);
    $pienaMara = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p['mara']->id)->where('tipologia', 'proprietario')->value('id');

    $riparto = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('anagrafica_id', $p['ugo']->id)->where('ruolo_risolto', 'usufruttuario')
        ->get(['id', 'piano_rate_id', 'tipo', 'anagrafica_id', 'immobile_id', 'anagrafica_immobile_id', 'conto_id', 'tabella_id', 'ruolo_richiesto', 'ruolo_risolto', 'quota_possesso',
            'riga_fattura_id', 'competenza_dal', 'competenza_al', 'titolarita_dal', 'titolarita_al', 'created_at']);
    $primaQuota = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->min('rate_quote.id');
    $esiti = (new \App\Services\Subentro\GenealogiaDellaQuota())->calcola($p['mara']->id, [$s['unita']->id], [$s['unita']->id => $pienaMara], \Carbon\CarbonImmutable::parse('2026-09-01'),
        \App\Services\Subentro\GenealogiaDellaQuota::VENDITA, [], $riparto, [$s['piano']->id => ['prima_quota' => $primaQuota, 'dal' => '2026-01-01', 'natura' => 'ordinaria']]);

    // La riga d'usufrutto di Ugo, censita: prima senza la nuda di chi esce trova Mara, che vale proprio la parte censita (50), e la
    // parte arriva a Mara il 1/4. Con la nuda di Ugo insieme i nudi varrebbero 100 contro 50, e la riga sarebbe indecidibile.
    expect($riparto)->not->toBeEmpty()
        ->and(collect($esiti)->map(fn ($e) => $e['parti'] ?? $e)->values()->all())->toBe([[['frazione' => 1.0, 'arrivo' => '2026-04-01', 'esito' => null, 'nudi' => null]]]);
});

/** Un passaggio registrato dalla rotta, con il cancello letto. */
function edupPassa($test, array $s, array $dati): void
{
    ruRegistra($test, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Atto letto, passaggio di prova']);
}

/** Lo stato delle righe, delle coppie e dei passaggi dell'unità: deve restare uguale dopo un rifiuto. */
function edupFoto(array $s): array
{
    return [DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->orderBy('id')->get(['id', 'tipologia', 'quota', 'data_inizio', 'data_fine'])->map(fn ($r) => (array) $r)->all(),
        DB::table('saldi')->whereNotNull('subentro_id')->count(), DB::table('subentri')->count()];
}

it('decisione 61 — due usufrutti censiti; Mara vende la nuda della metà di Ugo a Ugo stesso: all\'estinzione la nuda di Ugo può essere quella che torna piena, e il programma si ferma, senza far tornare pieno Carlo', function () {
    [$s, $p, $righe] = edupDueUsufrutti();
    edupPassa($this, $s, ruPassaggio('nuda', $righe['mara'], $p['ugo'], '2026-03-01', 50));
    $prima = edupFoto($s);

    $risposta = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo']));
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo']));

    // Prima tornava pieno Carlo, nudo della metà ancora in usufrutto a Elsa, con la coppia di 45205 a suo carico.
    $risposta->assertStatus(422)->assertJsonPath('errors.estinzione.0', fn ($m) => str_contains($m, 'Venditore Ugo ha anche una nuda proprietà che può essere quella della parte su cui l\'usufrutto finisce')
        && str_contains($m, '«Modifica associazione»'));
    expect(edupFoto($s))->toBe($prima);
});

it('decisione 61 — la stessa forma dal registro: Ugo e Bice vendono con riserva, poi Mara rivende a Ugo la nuda che il registro lega all\'usufrutto di Ugo: il programma si ferma', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $bice = edupPersona($s, 'Bice Seconda');
    $mara = edupPersona($s, 'Mara Compratrice');
    $carlo = edupPersona($s, 'Carlo Compratore');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaBice = edupRiga($s, $bice, 'proprietario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-01-31');
    edupPassa($this, $s, ruPassaggio('riserva', $s['rigaV'], $mara, '2026-02-01', 50));
    edupPassa($this, $s, ruPassaggio('riserva', $rigaBice, $carlo, '2026-02-01', 50));
    $nudaMara = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $mara->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    edupPassa($this, $s, ruPassaggio('nuda', $nudaMara, $s['v'], '2026-03-01', 50));
    $usufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $usufrutto))
        ->assertStatus(422)->assertJsonValidationErrors(['estinzione']);
});

it('decisione 61 — con un altro usufrutto, i nudi possibili valgono meno dell\'usufrutto che finisce (Ugo usufruttuario 60 e nudo 60, Carlo nudo 40 sotto Elsa): il programma si ferma, senza far tornare pieno Carlo', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $elsa = $s['a'];
    $carlo = edupPersona($s, 'Carlo Nudo');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 60]);
    edupRiga($s, $s['v'], 'nuda_proprietario', 60);
    edupRiga($s, $elsa, 'usufruttuario', 40);
    edupRiga($s, $carlo, 'nuda_proprietario', 40);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $s['rigaV']) + ['quota' => 60])
        ->assertStatus(422)->assertJsonPath('errors.estinzione.0', fn ($m) => str_contains($m, 'valgono in tutto il 40 %, meno dell\'usufrutto di Venditore Ugo (60 %)')
            && str_contains($m, 'può essere una nuda di Venditore Ugo') && ! str_contains($m, 'non si trova fra le righe in corso'));
});

/** Due genitori usufruttuari al 50 % (Ugo e Rita) e due figli nudi dell'intera unità; voce € 1.200,00 sull'«Usufruttuario». */
function edupFigli(float $carlo, float $dora): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $p = ['ugo' => $s['v'], 'rita' => edupPersona($s, 'Rita Madre'), 'carlo' => edupPersona($s, 'Carlo Figlio'), 'dora' => edupPersona($s, 'Dora Figlia')];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $righe = ['ugo' => $s['rigaV'], 'rita' => edupRiga($s, $p['rita'], 'usufruttuario', 50), 'carlo' => edupRiga($s, $p['carlo'], 'nuda_proprietario', $carlo), 'dora' => edupRiga($s, $p['dora'], 'nuda_proprietario', $dora)];
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    return [$s, $p, $righe];
}

it('decisione 62 — la donazione congiunta: muore Ugo, e l\'amministratore sceglie «tutti i nudi, ciascuno per la sua quota»: Carlo e Dora pieni del 25 % e nudi dell\'altro 25 %, il conguaglio diviso per quota', function () {
    [$s, $p, $righe] = edupFigli(50, 50);

    $senza = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo']));
    $an = ruAnteprima($this, $s, edupEstinzione($s, $righe['ugo']) + ['nudi_per_quota' => true]);
    ruRegistra($this, $s, edupEstinzione($s, $righe['ugo']) + ['nudi_per_quota' => true]);

    // A mano: l'usufrutto di Ugo vale metà della voce, 60000; dal 1/4, 275 giorni: 45205,48 → 45205, diviso per quota 25 e 25:
    // 22602,5 ciascuno, il centesimo in più al primo (Carlo).
    $senza->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => str_contains($m, 'tutti, ciascuno per la sua quota'));
    expect($an['nudi'])->toBe(['da' => 'per_quota', 'righe' => [$righe['carlo'], $righe['dora']], 'consolida' => [(string) $righe['carlo'] => 25, (string) $righe['dora'] => 25]])
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('Carlo Figlio e Dora Figlia risulteranno proprietari pieni, ciascuno per la sua parte dell\'usufrutto che finisce (Carlo Figlio per il 25 % e Dora Figlia per il 25 %), e resteranno nudi proprietari del resto dal 1 aprile 2026.')
        ->toContain('se l\'atto prevede che l\'usufrutto si accresca all\'altro usufruttuario')
        ->and(edupRighe($s, $p['carlo']))->toBe([['nuda_proprietario', 50.0, '2019-01-01', '2026-03-31'], ['proprietario', 25.0, '2026-04-01', null], ['nuda_proprietario', 25.0, '2026-04-01', null]])
        ->and(edupRighe($s, $p['dora']))->toBe([['nuda_proprietario', 50.0, '2019-01-01', '2026-03-31'], ['proprietario', 25.0, '2026-04-01', null], ['nuda_proprietario', 25.0, '2026-04-01', null]])
        ->and(edupRighe($s, $p['rita']))->toBe([['usufruttuario', 50.0, '2019-01-01', null]])
        ->and([edupSaldo($p['carlo']), edupSaldo($p['dora']), edupSaldo($p['ugo'])])->toBe([22603, 22602, -45205]);
});

it('decisione 62 — nudi al 75 e al 25 sotto due usufrutti del 50: nessuna combinazione di nudi interi vale l\'usufrutto, il modulo non la promette, e «ciascuno per la sua quota» divide 37,5 e 12,5', function () {
    [$s, $p, $righe] = edupFigli(75, 25);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo']))
        ->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => str_contains($m, 'nessuna combinazione di nudi proprietari interi vale l\'usufrutto'));
    $an = ruAnteprima($this, $s, edupEstinzione($s, $righe['ugo']) + ['nudi_per_quota' => true]);

    expect($an['nudi']['consolida'])->toBe([(string) $righe['carlo'] => 37.5, (string) $righe['dora'] => 12.5]);
});

it('decisione 62 — il registro seguito nella somma: Ugo vende con riserva a Fede, poi Carlo vende a Fede la sua nuda, che si somma: all\'estinzione torna pieno Fede per il 50 % dell\'usufrutto, senza scelta, e resta nudo dell\'altro 25 %', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $elsa = $s['a'];
    $carlo = edupPersona($s, 'Carlo Nudo');
    $dora = edupPersona($s, 'Dora Nuda');
    $fede = edupPersona($s, 'Fede Compratrice');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    edupRiga($s, $elsa, 'usufruttuario', 50);
    $rigaCarlo = edupRiga($s, $carlo, 'nuda_proprietario', 25);
    edupRiga($s, $dora, 'nuda_proprietario', 25);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-01-31');
    edupPassa($this, $s, ruPassaggio('riserva', $s['rigaV'], $fede, '2026-02-01', 50));
    edupPassa($this, $s, ruPassaggio('nuda', $rigaCarlo, $fede, '2026-03-01', 25));
    $usufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $nudaFede = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $fede->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');

    $an = ruAnteprima($this, $s, edupEstinzione($s, $usufrutto, dal: '2026-04-01'));
    edupPassa($this, $s, edupEstinzione($s, $usufrutto, dal: '2026-04-01'));

    expect($an['nudi'])->toBe(['da' => 'registro', 'righe' => [$nudaFede], 'consolida' => [(string) $nudaFede => 50]])
        ->and(collect(edupRighe($s, $fede))->filter(fn ($r) => $r[3] === null)->values()->all())->toBe([['proprietario', 50.0, '2026-04-01', null], ['nuda_proprietario', 25.0, '2026-04-01', null]])
        ->and(edupRighe($s, $dora))->toBe([['nuda_proprietario', 25.0, '2019-01-01', null]]);
});

it('rilievo A5 — la nuda dell\'altra metà venduta il 1/6, poi l\'estinzione di Ugo registrata in ritardo dal 1/4 con la scelta di Mara: passa, e Carlo e Fede non si toccano; se si sceglie proprio la nuda chiusa dopo, si rifiuta ancora', function () {
    [$s, $p, $righe] = edupDueUsufrutti();
    $fede = edupPersona($s, 'Fede Compratrice');
    edupPassa($this, $s, ruPassaggio('nuda', $righe['carlo'], $fede, '2026-06-01', 50));
    $carloPrima = edupRighe($s, $p['carlo']);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo'], [$righe['carlo']]))
        ->assertStatus(422)->assertJsonValidationErrors(['decorrenza']);
    ruRegistra($this, $s, edupEstinzione($s, $righe['ugo'], [$righe['mara']]));

    // A mano: 60000 × 275/365 = 45205,48 → 45205, tutto a Mara. Prima si rifiutava con «quella nuda proprietà diventa piena», detto
    // della nuda di Carlo, che sta sotto l'usufrutto di Elsa e non diventa piena.
    expect(edupRighe($s, $p['mara']))->toBe([['nuda_proprietario', 50.0, '2019-01-01', '2026-03-31'], ['proprietario', 50.0, '2026-04-01', null]])
        ->and(edupRighe($s, $p['carlo']))->toBe($carloPrima)
        ->and([edupSaldo($p['mara']), edupSaldo($p['ugo'])])->toBe([45205, -45205]);
});

it('rilievo A6 — la nuda venduta e l\'usufrutto estinto lo stesso giorno, con il consolidamento: l\'anteprima si rifiuta già, come la registrazione, e non consiglia di cambiare la data dell\'atto', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = edupPersona($s, 'Mara Figlia');
    $fede = edupPersona($s, 'Fede Compratrice');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $rigaMara = edupRiga($s, $mara, 'nuda_proprietario', 100);
    edupRiga($s, edupPersona($s, 'Rita Madre'), 'usufruttuario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');
    edupPassa($this, $s, ruPassaggio('nuda', $rigaMara, $fede, '2026-04-01', 100));
    $prima = edupFoto($s);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $s['rigaV']))
        ->assertStatus(422)->assertJsonPath('errors.decorrenza.0', fn ($m) => str_starts_with($m, 'La nuda proprietà di Fede Compratrice è nata il giorno dell\'estinzione') && ! str_contains($m, 'un giorno dopo'));
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), edupEstinzione($s, $s['rigaV']))
        ->assertSessionHasErrors('decorrenza');
    expect(session('errors')->first('decorrenza'))->toContain('è nata il giorno dell\'estinzione')->not->toContain('un giorno dopo')
        ->and(edupFoto($s))->toBe($prima);
});

it('rilievo T11 — la scelta dei nudi resta scritta nel registro del passaggio: da dove viene e quali righe', function () {
    [$s, , $righe] = edupDueUsufrutti();

    $passaggio = ruRegistra($this, $s, edupEstinzione($s, $righe['ugo'], [$righe['mara']]));

    expect($passaggio->registro['nudi'] ?? null)->toBe(['da' => 'scelta', 'righe' => [$righe['mara']], 'consolida' => []]);
});

it('rilievo T14 — i nudi scelti valgono solo fra le righe di questa unità: una riga di un\'altra unità, anche con la quota giusta, si rifiuta e non cambia niente', function () {
    [$s, , $righe] = edupDueUsufrutti();
    $altra = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'ALTRA-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    $estranea = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => edupPersona($s, 'Nina Altrove')->id, 'immobile_id' => $altra->id, 'tipologia' => 'nuda_proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $prima = edupFoto($s);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo'], [$estranea]))
        ->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => ! str_contains($m, 'Nina Altrove'));
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo'], [$estranea]))
        ->assertSessionHasErrors(['nudi_che_tornano']);
    expect(edupFoto($s))->toBe($prima)
        ->and(DB::table('anagrafica_immobile')->where('id', $estranea)->value('data_fine'))->toBeNull();
});

it('rilievo T7 — i due genitori muoiono lo stesso giorno: dopo l\'estinzione di Ugo con il consolidamento, quella di Rita non riceve una ricetta che gira in tondo', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = edupPersona($s, 'Mara Figlia');
    $rita = edupPersona($s, 'Rita Madre');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    edupRiga($s, $mara, 'nuda_proprietario', 100);
    $rigaRita = edupRiga($s, $rita, 'usufruttuario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-08-31');
    ruRegistra($this, $s, edupEstinzione($s, $s['rigaV'], dal: '2026-09-01'));

    // Prima la frase diceva di annullare l'estinzione di Ugo, registrare questa e poi di nuovo quella: lo stesso rifiuto a parti
    // invertite.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $rigaRita, dal: '2026-09-01'))
        ->assertStatus(422)->assertJsonPath('errors.decorrenza.0', fn ($m) => str_contains($m, 'vengono da un\'altra estinzione dello stesso giorno')
            && str_contains($m, 'per questo caso il programma non indica una strada') && ! str_contains($m, 'registra questa estinzione e poi'));
});

it('rilievo G1 — Ugo usufruttuario di un quarto compra la nuda di quel quarto e poi allarga l\'usufrutto con una riserva: la nuda resta «comprata mentre era usufruttuario», e l\'estinzione si ferma', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $bice = edupPersona($s, 'Bice Nuda');
    $fede = edupPersona($s, 'Fede Compratrice');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    edupRiga($s, $ugo, 'usufruttuario', 25);
    $rigaBice = edupRiga($s, $bice, 'nuda_proprietario', 25);
    edupRiga($s, $s['a'], 'usufruttuario', 25);
    edupRiga($s, edupPersona($s, 'Carlo Nudo'), 'nuda_proprietario', 25);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-01-14');
    edupPassa($this, $s, ruPassaggio('nuda', $rigaBice, $ugo, '2026-01-15', 25));
    edupPassa($this, $s, ruPassaggio('riserva', $s['rigaV'], $fede, '2026-02-01', 50));
    $usufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $prima = edupFoto($s);

    // Prima la riga d'usufrutto riaperta dalla riserva (dal 1/2) faceva sembrare la nuda (dal 15/1) più vecchia dell'usufrutto:
    // tornava pieno Carlo, sotto l'usufrutto di Elsa, con una coppia di 22603.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $usufrutto) + ['quota' => 75])
        ->assertStatus(422)->assertJsonValidationErrors(['estinzione']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), edupEstinzione($s, $usufrutto) + ['quota' => 75]);
    expect(edupFoto($s))->toBe($prima);
});

it('rilievo G1 — la stessa forma con due riserve in fila: l\'inizio dell\'usufrutto si risale fino alla riga censita, e l\'estinzione si ferma', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $bice = edupPersona($s, 'Bice Nuda');
    $dino = edupPersona($s, 'Dino Pieno');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 25]);
    edupRiga($s, $ugo, 'usufruttuario', 25);
    $rigaBice = edupRiga($s, $bice, 'nuda_proprietario', 25);
    edupRiga($s, $s['a'], 'usufruttuario', 25);
    edupRiga($s, edupPersona($s, 'Carlo Nudo'), 'nuda_proprietario', 25);
    $rigaDino = edupRiga($s, $dino, 'proprietario', 25);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-01-14');
    edupPassa($this, $s, ruPassaggio('nuda', $rigaBice, $ugo, '2026-01-15', 25));
    edupPassa($this, $s, ruPassaggio('riserva', $s['rigaV'], edupPersona($s, 'Fede Compratrice'), '2026-02-01', 25));
    edupPassa($this, $s, ruPassaggio('vendita', $rigaDino, $ugo, '2026-02-15', 25));
    $pienaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');
    edupPassa($this, $s, ruPassaggio('riserva', $pienaUgo, edupPersona($s, 'Gino Compratore'), '2026-03-01', 25));
    $usufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $usufrutto) + ['quota' => 75])
        ->assertStatus(422)->assertJsonValidationErrors(['estinzione']);
});

it('rilievo G3 — il registro della riserva dice qual è la nuda dell\'usufrutto di Ugo; la nuda che Ugo compra dopo è per forza dell\'altra metà: torna piena Fede, senza fermarsi', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $fede = edupPersona($s, 'Fede Compratrice');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    edupRiga($s, $s['a'], 'usufruttuario', 50);
    $rigaCarlo = edupRiga($s, $carlo = edupPersona($s, 'Carlo Nudo'), 'nuda_proprietario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-01-31');
    edupPassa($this, $s, ruPassaggio('riserva', $s['rigaV'], $fede, '2026-02-01', 50));
    edupPassa($this, $s, ruPassaggio('nuda', $rigaCarlo, $ugo, '2026-03-01', 50));
    ruEmetti($s, '2026-03-31');
    $usufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $nudaFede = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $fede->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');

    $an = ruAnteprima($this, $s, edupEstinzione($s, $usufrutto));
    edupPassa($this, $s, edupEstinzione($s, $usufrutto));

    // A mano: l'usufrutto di Ugo vale metà della voce, 60000; dal 1/4, 275 giorni: 45205,48 → 45205 a Fede. Prima la nuda di
    // Ugo, comprata dopo la riserva, fermava l'estinzione anche se il registro diceva quale nuda torna piena.
    expect($an['nudi'])->toBe(['da' => 'registro', 'righe' => [$nudaFede], 'consolida' => []])
        ->and(collect(edupRighe($s, $fede))->filter(fn ($r) => $r[3] === null)->values()->all())->toBe([['proprietario', 50.0, '2026-04-01', null]])
        ->and(collect(edupRighe($s, $ugo))->filter(fn ($r) => $r[0] === 'nuda_proprietario' && $r[3] === null)->values()->all())->toBe([['nuda_proprietario', 50.0, '2026-03-01', null]])
        ->and(DB::table('saldi')->where('subentro_id', \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->latest('id')->value('id'))->get(['anagrafica_id', 'saldo_iniziale'])
            ->mapWithKeys(fn ($r) => [(int) $r->anagrafica_id => (int) $r->saldo_iniziale])->sortKeys()->all())->toBe(collect([$ugo->id => -45205, $fede->id => 45205])->sortKeys()->all());
});

it('rilievo G4 — terzi arrotondati: con «ciascuno per la sua quota» chi riceve tutta la sua quota torna pieno per intero, senza una nuda allo 0 %, e la frase non gli promette un resto', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $carlo = edupPersona($s, 'Carlo Figlio');
    $dora = edupPersona($s, 'Dora Figlia');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 33.33]);
    edupRiga($s, $s['a'], 'usufruttuario', 66.67);
    edupRiga($s, $s['v'], 'nuda_proprietario', 66.66);
    $rigaCarlo = edupRiga($s, $carlo, 'nuda_proprietario', 16.67);
    $rigaDora = edupRiga($s, $dora, 'nuda_proprietario', 16.67);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');
    $dati = edupEstinzione($s, $s['rigaV']) + ['quota' => 33.33, 'nudi_per_quota' => true];

    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);

    // A mano, in centesimi di punto: 3333 diviso per 1667 e 1667 → 1666,5 ciascuno, l'avanzo al primo: Carlo 16,67 (tutta la sua
    // riga), Dora 16,66. Prima Carlo riceveva anche una nuda allo 0 %.
    expect($an['nudi']['consolida'])->toEqual([(string) $rigaDora => 16.66])
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('Carlo Figlio per tutta la sua quota (16,67 %) e Dora Figlia per il 16,66 %), e Dora Figlia resterà nudo proprietario del resto')
        ->and(edupRighe($s, $carlo))->toBe([['nuda_proprietario', 16.67, '2019-01-01', '2026-03-31'], ['proprietario', 16.67, '2026-04-01', null]])
        ->and(edupRighe($s, $dora))->toBe([['nuda_proprietario', 16.67, '2019-01-01', '2026-03-31'], ['proprietario', 16.66, '2026-04-01', null], ['nuda_proprietario', 0.01, '2026-04-01', null]])
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('quota', '<=', 0)->count())->toBe(0);
});

it('rilievi GT12 e HT7 — quattro nudi al 20, 30, 15 e 35 sotto due usufrutti del 50: nessuno da solo vale l\'usufrutto, due insieme sì; la scelta di Carlo e Dora passa, e senza scelta il server chiede quali tornano', function () {
    [$s, $p, $righe] = edupFigli(20, 30);
    edupRiga($s, edupPersona($s, 'Elio Figlio'), 'nuda_proprietario', 15);
    edupRiga($s, edupPersona($s, 'Fede Figlia'), 'nuda_proprietario', 35);

    $an = ruAnteprima($this, $s, edupEstinzione($s, $righe['ugo'], [$righe['carlo'], $righe['dora']]));
    $senza = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo']));

    // 20 + 30 e 15 + 35 valgono 50; un controllo che guardasse solo i nudi da soli direbbe «nessuna combinazione».
    expect(edupNudi($an))->toBe(['da' => 'scelta', 'righe' => [$righe['carlo'], $righe['dora']]]);
    $senza->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => str_contains($m, 'scegli quali tornano proprietari pieni') && ! str_contains($m, 'nessuna combinazione'));
});

it('rilievo HT2 — Bice vende la piena a Mara e l\'usufrutto di Elsa si estingue sommandosi su quella piena, tutto il 1/9: all\'estinzione di Ugo dello stesso giorno la frase rimanda alla via a mano, non a una ricetta che si ferma di nuovo', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = edupPersona($s, 'Mara Nuda');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 30]);
    $rigaElsa = edupRiga($s, $s['a'], 'usufruttuario', 30);
    edupRiga($s, $mara, 'nuda_proprietario', 60);
    $rigaBice = edupRiga($s, edupPersona($s, 'Bice Piena'), 'proprietario', 40);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-08-31');
    edupPassa($this, $s, ruPassaggio('vendita', $rigaBice, $mara, '2026-09-01', 40));
    edupPassa($this, $s, edupEstinzione($s, $rigaElsa, dal: '2026-09-01') + ['quota' => 30]);

    // L'estinzione di Elsa ha portato la piena di Mara a 70 e le ha aperto una nuda di 30: le due righe che l'estinzione di Ugo
    // dovrebbe unire. Annullare l'estinzione di Elsa e la vendita e registrarle dopo questa darebbe di nuovo lo stesso rifiuto.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $s['rigaV'], dal: '2026-09-01') + ['quota' => 30])
        ->assertStatus(422)->assertJsonPath('errors.decorrenza.0', fn ($m) => str_contains($m, 'vengono da un\'altra estinzione dello stesso giorno')
            && str_contains($m, 'per questo caso il programma non indica una strada'));
});

it('rilievo HT3 — due nudi omonimi sul box e sulla cantina: l\'apertura del blocco rate li conta come due persone', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 800.0]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $titolare = fn ($unita, Anagrafica $p, string $ruolo) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $titolare($s['unita'], $ugo, 'nuda_proprietario');
    $pertinenze = [];
    foreach (['Box 12' => 'B12', 'Cantina 3' => 'C3'] as $nome => $interno) {
        $p = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => $nome, 'descrizione' => $nome, 'interno' => $interno, 'pertinenza_di_immobile_id' => $s['unita']->id]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $p->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
        $titolare($p, $ugo, 'usufruttuario');
        $titolare($p, edupPersona($s, 'Mario Rossi'), 'nuda_proprietario');
        $pertinenze[] = $p->id;
    }
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $an = ruAnteprima($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100, $pertinenze) + ['ho_letto' => true, 'nota_cancello' => 'Estinzione con box e cantina, atto letto']);

    // Due coppie, due persone con lo stesso nome: prima l'apertura ne nominava una sola, «che torna proprietario pieno».
    expect(collect($an['rate']['conguaglio']['coppie'])->pluck('anagrafica_entrante_id')->unique()->count())->toBe(2)
        ->and(implode(' ', $an['rate']['frasi']))->toContain('Mario Rossi e Mario Rossi, che tornano proprietari pieni,');
});

it('rilievo HT4 — la percentuale con l\'articolo arrotonda prima di sceglierlo: una somma in virgola mobile non dà «lo 1 %» né «il 80 %»', function () {
    expect(\App\Services\Subentro\NudiDellEstinzione::percentuale(0.3 + 0.6 + 0.1))->toBe('l\'1 %')
        ->and(\App\Services\Subentro\NudiDellEstinzione::percentuale(1.67 + 14.34 + 48.66 + 15.33))->toBe('l\'80 %')
        ->and(\App\Services\Subentro\NudiDellEstinzione::percentuale(16.66))->toBe('il 16,66 %');
});

it('rilievo GT7 — le due righe di Mara nate il 1/9 vengono da due vendite: la frase non parla di un\'altra estinzione dello stesso giorno', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = edupPersona($s, 'Mara Compratrice');
    $elsa = $s['a'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 30]);
    $rigaCarlo = edupRiga($s, edupPersona($s, 'Carlo Nudo'), 'nuda_proprietario', 30);
    $rigaElsa = edupRiga($s, $elsa, 'usufruttuario', 30);
    $rigaDora = edupRiga($s, edupPersona($s, 'Dora Nuda'), 'nuda_proprietario', 30);
    $rigaBice = edupRiga($s, edupPersona($s, 'Bice Piena'), 'proprietario', 40);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-08-31');
    edupPassa($this, $s, ruPassaggio('nuda', $rigaCarlo, $mara, '2026-09-01', 30));
    edupPassa($this, $s, ruPassaggio('vendita', $rigaBice, $mara, '2026-09-01', 40));
    edupPassa($this, $s, edupEstinzione($s, $rigaElsa, [$rigaDora], dal: '2026-10-01') + ['quota' => 30]);
    $nudaMara = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $mara->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $s['rigaV'], [$nudaMara], dal: '2026-09-01') + ['quota' => 30])
        ->assertStatus(422)->assertJsonPath('errors.decorrenza.0', fn ($m) => str_contains($m, 'da due righe nate lo stesso giorno') && ! str_contains($m, 'un\'altra estinzione dello stesso giorno'));
});

it('rilievo GT1 — sull\'appartamento il solo nudo è Ugo, sul box c\'è Mara: l\'apertura del blocco rate nomina Mara e la coppia del box, e non dice «senza conguaglio» per tutto', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $mara = edupPersona($s, 'Mara Nuda');
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $titolare = fn ($unita, Anagrafica $p, string $ruolo) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $titolare($s['unita'], $ugo, 'nuda_proprietario');
    $titolare($box, $ugo, 'usufruttuario');
    $titolare($box, $mara, 'nuda_proprietario');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    $an = ruAnteprima($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100, [$box->id]) + ['ho_letto' => true, 'nota_cancello' => 'Estinzione con il box, atto letto']);

    // A mano: box 12000 × 275/365 = 9041,10 → 9041 a Mara; l'appartamento resta a Ugo, che torna pieno.
    expect(implode(' ', $an['rate']['frasi']))->toContain('Il conguaglio fra Venditore Ugo e Mara Nuda, che torna proprietario pieno,')
        ->not->toContain('Le rate già emesse non si toccano. Venditore Ugo torna proprietario pieno: le quote')
        ->and(collect($an['rate']['conguaglio']['coppie'])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe([['Mara Nuda', 9041]]);
});


it('rilievo G6 — l\'estinzione registrata in ritardo: il modulo riceve anche la nuda di Carlo chiusa dopo, per guardarla il giorno dell\'estinzione, e la frase non manda a ricaricare la pagina', function () {
    [$s, $p, $righe] = edupDueUsufrutti();
    $fede = edupPersona($s, 'Fede Compratrice');
    edupPassa($this, $s, ruPassaggio('nuda', $righe['carlo'], $fede, '2026-06-01', 50));
    $nudaFede = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $fede->id)->where('tipologia', 'nuda_proprietario')->value('id');

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.passaggi.create', [$s['c'], $s['unita'], 'tipo' => 'usufrutto']))->viewData('page')['props'];

    // Le righe per il giorno dell'estinzione: anche quella di Carlo, chiusa il 31/5, che il 1/4 era in corso.
    expect(collect($pagina['righeDellEstinzione'])->pluck('id')->all())->toContain($righe['carlo'], $righe['mara'], $righe['elsa'], $nudaFede)
        ->and(collect($pagina['righeDellEstinzione'])->firstWhere('id', $righe['carlo'])['data_fine'])->toBe('2026-05-31')
        ->and(collect($pagina['titolari'])->pluck('id')->all())->not->toContain($righe['carlo']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), edupEstinzione($s, $righe['ugo'], [$nudaFede]))
        ->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', 'Uno dei nudi proprietari che hai indicato non è fra quelli in corso su questa unità il 1 aprile 2026: le righe possono essere cambiate dopo l\'apertura della pagina. Ricaricala e scegli di nuovo.');
});

it('«Chi resta obbligato» con la nuda che torna piena solo in parte: nell\'anteprima e nello storico chi torna proprietario pieno lo è della parte dell\'usufrutto che finisce, non risponde di tutte le spese dell\'unità', function () {
    [$s, $p, $righe] = edupFigli(50, 50);
    $dati = edupEstinzione($s, $righe['ugo']) + ['nudi_per_quota' => true];

    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);
    $storico = app(\App\Services\Subentro\FrasiObbligati::class)->daSubentro(\App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->latest('id')->first());

    // Prima la frase diceva «Carlo Figlio (50 %) e Dora Figlia (50 %) tornano proprietari pieni e rispondono di tutte le spese
    // dell'unità», con Rita ancora usufruttuaria della sua metà.
    $attesa = 'Dal 1 aprile 2026 Carlo Figlio (per il 25 %) e Dora Figlia (per il 25 %) tornano proprietari pieni della parte dell\'usufrutto che finisce e ne rispondono, ciascuno per la sua parte; per il resto dell\'unità rispondono, come prima, i titolari di quella parte (il nudo proprietario e l\'usufruttuario in solido, art. 67 ult. co. disp. att. c.c.).';
    expect(implode("\n", $an['obbligati']['frasi']))->toContain($attesa)->not->toContain('tutte le spese')
        ->and(implode("\n", $storico))->toContain($attesa)->not->toContain('tutte le spese');
});

it('«Chi resta obbligato» con un altro usufrutto in corso: torna piena per intero la nuda di Mara, ma l\'unità ha ancora Elsa usufruttuaria e Carlo nudo, e la frase lo dice', function () {
    [$s, $p, $righe] = edupDueUsufrutti();
    $dati = edupEstinzione($s, $righe['ugo'], [$righe['mara']]);

    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);
    $storico = app(\App\Services\Subentro\FrasiObbligati::class)->daSubentro(\App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->latest('id')->first());

    $attesa = 'Dal 1 aprile 2026 Mara Nuda torna proprietario pieno della parte dell\'usufrutto che finisce e ne risponde; per il resto dell\'unità rispondono, come prima, i titolari di quella parte (il nudo proprietario e l\'usufruttuario in solido, art. 67 ult. co. disp. att. c.c.).';
    expect(implode("\n", $an['obbligati']['frasi']))->toContain($attesa)
        ->and(implode("\n", $storico))->toContain($attesa);
});

it('«Chi resta obbligato» sull\'unità mista: Ugo pieno di metà e usufruttuario dell\'altra, Bice nuda; finisce l\'usufrutto, e Bice risponde della sua metà, non di tutta l\'unità (Ugo resta pieno dell\'altra)', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaU = edupRiga($s, $s['v'], 'usufruttuario', 50);
    edupRiga($s, edupPersona($s, 'Bice Nuda'), 'nuda_proprietario', 50);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');
    $dati = edupEstinzione($s, $rigaU);

    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);
    $storico = app(\App\Services\Subentro\FrasiObbligati::class)->daSubentro(\App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->latest('id')->first());

    // Prima: «Bice Nuda torna proprietario pieno e risponde di tutte le spese dell'unità», con Ugo ancora pieno dell'altra metà.
    $attesa = 'Dal 1 aprile 2026 Bice Nuda torna proprietario pieno della parte dell\'usufrutto che finisce e ne risponde; per il resto dell\'unità rispondono, come prima, i titolari di quella parte.';
    expect(implode("\n", $an['obbligati']['frasi']))->toContain($attesa)->not->toContain('tutte le spese')
        ->and(implode("\n", $storico))->toContain($attesa);
});

it('«Chi resta obbligato», rilievi V1 e V7 — dove sull\'unità non resta nessun altro titolare la frase è quella di sempre: il nudo solo al 100 %, il pieno di metà che torna pieno al 100 %, le quote arrotondate', function (string $forma) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => $forma === 'pieno di metà' ? 50 : 100]);
    $attesa = match ($forma) {
        'nudo solo' => (function () use ($s) {
            edupRiga($s, edupPersona($s, 'Bice Nuda'), 'nuda_proprietario', 100);

            return 'Dal 1 aprile 2026 Bice Nuda torna proprietario pieno e risponde di tutte le spese dell\'unità.';
        })(),
        'pieno di metà' => (function () use ($s) {
            $carlo = edupPersona($s, 'Carlo Erede');
            edupRiga($s, $carlo, 'proprietario', 50);
            edupRiga($s, $carlo, 'nuda_proprietario', 50);

            return 'Dal 1 aprile 2026 Carlo Erede torna proprietario pieno e risponde di tutte le spese dell\'unità.';
        })(),
        'quote arrotondate' => (function () use ($s) {
            edupRiga($s, edupPersona($s, 'Rita Coniuge'), 'nuda_proprietario', 33.33);
            foreach (['Anna', 'Bruno', 'Ciro', 'Dario'] as $n) {
                edupRiga($s, edupPersona($s, $n . ' Figlio'), 'nuda_proprietario', 16.66);
            }

            return 'Dal 1 aprile 2026 Rita Coniuge (33,33 %), Anna Figlio (16,66 %), Bruno Figlio (16,66 %), Ciro Figlio (16,66 %) e Dario Figlio (16,66 %) tornano proprietari pieni e rispondono di tutte le spese dell\'unità, ciascuno per la sua quota.';
        })(),
    };
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');
    $dati = edupEstinzione($s, $s['rigaV']) + ['quota' => $forma === 'pieno di metà' ? 50 : 100];

    $an = ruAnteprima($this, $s, $dati);
    ruRegistra($this, $s, $dati);
    $storico = app(\App\Services\Subentro\FrasiObbligati::class)->daSubentro(\App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->latest('id')->first());

    expect(implode("\n", $an['obbligati']['frasi']))->toContain($attesa)
        ->and(implode("\n", $storico))->toContain($attesa);
})->with(['nudo solo', 'pieno di metà', 'quote arrotondate']);

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 746 — le letture di fine anno dei sottocontatori di una voce (decisioni 693, 694 e 734 (3), la bozza approvata).
 *
 * Il caso: voce «Energia elettrica», € 3.000,00 nell'anno, tre tabelle al preventivo 40/20/40: Ascensore (Interno 1), Impianto termico (Interno 2),
 * Luci scale (Interno 3), una unità per tabella perché si legga chi paga. Le letture del 31/12: contatore generale 10.000 kWh, ascensore 5.000,
 * impianto termico 2.000, luci per differenza (3.000): 50/20/30. Le rate non cambiano; il conguaglio sposta € 300,00 dalle luci all'ascensore.
 * La catena: la voce → le letture → il motore del consuntivo → il conguaglio per persona → il controllo nella scheda «Controlli» → la stampa del
 * consuntivo per tabella.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite); la pagina (la prova a video).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Gestionale\ControlliDelConguaglio;
use App\Services\Gestionale\LettureDiFineAnno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il condominio del caso, con il preventivo generato e la spesa dell'anno sul giornale. */
function b15lfScenario($test, int $speso = 300000): array
{
    static $seq = 0;
    $seq++;
    $test->travelTo('2026-01-10 10:00:00');
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $unita = [];
    $persone = [];
    foreach (['U1' => 'Neri', 'U2' => 'Bianchi', 'U3' => 'Verdi'] as $u => $nome) {
        $unita[$u] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "LF{$seq}-{$u}", 'nome' => $u, 'interno' => substr($u, 1)]);
        $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-lf{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'LF' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $seq, 11, '0', STR_PAD_LEFT)]);
        $persone[$nome]->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s = ['c' => $c, 'e' => $e, 'g' => $g, 'condominio' => $c, 'esercizio' => $e, 'gestione' => $g, 'pianoConto' => $pc, 'unita' => $unita, 'persone' => $persone];
    $s['ascensore'] = cpmTabella($s, 'Ascensore', ['U1' => 1000]);
    $s['termico'] = cpmTabella($s, 'Impianto termico', ['U2' => 1000]);
    $s['luci'] = cpmTabella($s, 'Luci scale', ['U3' => 1000]);
    $s['voce'] = cpmVoce($s, 'Energia elettrica', [[$s['ascensore'], 40], [$s['termico'], 20], [$s['luci'], 40]], 'spesa', 300000);
    $s['giornale'] = cpmGiornale($s);
    $s['piano'] = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 4, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-01-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);
    cpmSpesa($s, $s['giornale'], $s['voce'], $speso);

    return $s;
}

/** Il corpo delle letture del caso, con le modifiche date per tabella. */
function b15lfCorpo(array $s, array $per = [], ?float $generale = 10000): array
{
    $tabelle = [];
    foreach (['ascensore' => ['modo' => 'lettura', 'lettura' => 5000], 'termico' => ['modo' => 'lettura', 'lettura' => 2000], 'luci' => ['modo' => 'differenza']] as $chiave => $riga) {
        $tabelle[] = ['tabella_id' => $s[$chiave]->id] + ($per[$chiave] ?? $riga);
    }

    return ['unita' => 'kWh', 'generale' => $generale, 'tabelle' => $tabelle];
}

function b15lfRotta(array $s, string $azione): string
{
    return route("admin.gestionale.esercizi.piani-conti.conti.letture-di-fine-anno.{$azione}", [$s['c'], $s['e'], $s['pianoConto'], $s['voce']]);
}

/** Le rate del preventivo per persona: [nome => centesimi]. */
function b15lfRate(array $s): array
{
    $esito = [];
    foreach ($s['persone'] as $nome => $p) {
        $esito[$nome] = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
            ->where('rate_quote.anagrafica_id', $p->id)->sum('rate_quote.importo');
    }

    return $esito;
}

/** La spesa A e il conguaglio di ognuno, dal consuntivo per persona: [nome => [a, conguaglio]]. */
function b15lfConguaglio($test, array $s): array
{
    $test->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $esito = [];
    foreach (['Neri' => 'U1', 'Bianchi' => 'U2', 'Verdi' => 'U3'] as $nome => $u) {
        $r = $c['persone'][$s['g']->id][$s['persone'][$nome]->id][$s['unita'][$u]->id];
        $esito[$nome] = [(int) $r['a'], (int) $r['conguaglio']];
    }

    return $esito;
}

it('Coda 746 — il caso: le letture 10.000 / 5.000 / 2.000 e le luci per differenza danno 50/20/30; le rate restano, il conguaglio sposta € 300,00 dalle luci all\'ascensore', function () {
    $s = b15lfScenario($this);
    $rate = b15lfRate($s);
    expect($rate)->toBe(['Neri' => 120000, 'Bianchi' => 60000, 'Verdi' => 120000])
        ->and(b15lfConguaglio($this, $s))->toBe(['Neri' => [120000, 0], 'Bianchi' => [60000, 0], 'Verdi' => [120000, 0]]);

    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors()->assertRedirect();

    expect(b15lfRate($s))->toBe($rate)
        ->and(b15lfConguaglio($this, $s))->toBe(['Neri' => [150000, 30000], 'Bianchi' => [60000, 0], 'Verdi' => [90000, -30000]]);
});

it('Coda 746 — la voce aperta nella pagina legge le letture: il preventivo, le letture salvate, la differenza delle luci e le percentuali del consuntivo', function () {
    $s = b15lfScenario($this);
    $vuota = $this->actingAs($this->user)->getJson(b15lfRotta($s, 'show'))->assertOk();
    expect($vuota->json('salvate'))->toBeFalse()->and(collect($vuota->json('tabelle'))->pluck('preventivo')->all())->toBe([40, 20, 40]);

    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertRedirect();
    $r = $this->actingAs($this->user)->getJson(b15lfRotta($s, 'show'))->assertOk();

    expect($r->json('salvate'))->toBeTrue()
        ->and($r->json('generale'))->toEqual(10000)
        ->and($r->json('differenza_lettura'))->toEqual(3000)
        ->and($r->json('data_fine'))->toBe('2026-12-31')
        ->and(collect($r->json('tabelle'))->map(fn ($t) => [$t['nome'], $t['modo'], $t['consuntivo']])->all())
        ->toEqual([['Ascensore', 'lettura', 50], ['Impianto termico', 'lettura', 20], ['Luci scale', 'differenza', 30]]);
});

it('Coda 746 — la percentuale scritta vale com\'è, con le altre per lettura e una per differenza', function () {
    $s = b15lfScenario($this);
    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s, ['termico' => ['modo' => 'percentuale', 'percentuale' => 25]]))->assertSessionHasNoErrors();

    // Ascensore 50 %, impianto termico 25 % scritto, luci il resto: 25 %.
    expect(LettureDiFineAnno::coefficienti([$s['voce']->id], $s['e']->id))->toBe([$s['voce']->id => [$s['ascensore']->id => 50.0, $s['termico']->id => 25.0, $s['luci']->id => 25.0]])
        ->and(b15lfConguaglio($this, $s))->toBe(['Neri' => [150000, 30000], 'Bianchi' => [75000, 15000], 'Verdi' => [75000, -45000]]);
});

it('Coda 746 — le letture si rifiutano, sul campo e con la frase: oltre il contatore generale, la differenza che non è di nessuno, due tabelle per differenza, il generale che manca, una tabella che manca', function (Closure $corpo, string $frase) {
    $s = b15lfScenario($this);

    $r = $this->actingAs($this->user)->from('/piano')->put(b15lfRotta($s, 'update'), $corpo($s));

    $r->assertRedirect('/piano')->assertSessionHasErrors('letture');
    expect(session('errors')->get('letture')[0])->toContain($frase)
        ->and(DB::table('letture_consuntivo')->count())->toBe(0);
})->with([
    'oltre il generale' => [fn ($s) => b15lfCorpo($s, ['termico' => ['modo' => 'lettura', 'lettura' => 6000]]), 'Le letture delle tabelle fanno 11.000 kWh e superano il contatore generale, 10.000 kWh'],
    'la differenza di nessuno' => [fn ($s) => b15lfCorpo($s, ['luci' => ['modo' => 'lettura', 'lettura' => 2500]]), '500 kWh non sono di nessuna tabella. Scegli una tabella «per differenza»'],
    'due per differenza' => [fn ($s) => b15lfCorpo($s, ['termico' => ['modo' => 'differenza']]), 'Al più una tabella si calcola «per differenza»'],
    'senza il generale' => [fn ($s) => b15lfCorpo($s, generale: null), 'Manca la lettura del contatore generale'],
    'le percentuali non fanno 100' => [fn ($s) => b15lfCorpo($s, ['ascensore' => ['modo' => 'percentuale', 'percentuale' => 40], 'termico' => ['modo' => 'percentuale', 'percentuale' => 20],
        'luci' => ['modo' => 'percentuale', 'percentuale' => 39.99]]), 'Le percentuali fanno 99,99 %, non 100 %'],
    'una tabella che manca' => [fn ($s) => ['unita' => 'kWh', 'generale' => 10000, 'tabelle' => array_slice(b15lfCorpo($s)['tabelle'], 0, 2)], 'Le tabelle delle letture devono essere quelle della voce, tutte'],
]);

it('Coda 746 — si scrivono anche con il piano approvato, che blocca le tabelle della voce: non toccano le rate', function () {
    $s = b15lfScenario($this);
    // Il piano approvato tiene la voce con la sua riga: le tabelle e i coefficienti della voce non si cambiano.
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['voce']->id, 'importo' => 300000, 'created_at' => now(), 'updated_at' => now()]);
    expect(\App\Services\Gestionale\BloccoDellaVoce::rifiutoPerLaVoce($s['voce']->fresh()))->not->toBeNull();

    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors();

    expect(DB::table('letture_consuntivo')->where('conto_id', $s['voce']->id)->count())->toBe(4);
});

it('Coda 746 — due «Salva» nello stesso momento: il secondo trova le righe del primo sull\'indice unico e torna al modulo con la frase, non con una pagina d\'errore', function () {
    $s = b15lfScenario($this);
    // L'altra finestra scrive la sua riga fra la cancellazione e la scrittura di questa: su MySQL è il secondo di due salvataggi contemporanei.
    $una = true;
    \App\Models\Gestionale\LetturaConsuntivo::creating(function ($riga) use (&$una) {
        if ($una && $riga->tabella_id !== null) {
            $una = false;
            DB::table('letture_consuntivo')->insert(['conto_id' => $riga->conto_id, 'tabella_id' => $riga->tabella_id, 'esercizio_id' => $riga->esercizio_id,
                'lettura' => 1, 'per_differenza' => false, 'unita' => 'kWh', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->actingAs($this->user)->from('/piano')->put(b15lfRotta($s, 'update'), b15lfCorpo($s))
        ->assertRedirect('/piano')->assertSessionHasErrors(['letture' => LettureDiFineAnno::SALVATE_INSIEME]);
    expect(LettureDiFineAnno::SALVATE_INSIEME)->toBe('Le letture di questa voce sono state salvate anche da un\'altra finestra nello stesso momento: '
        . 'riapri la voce, controlla quelle che vedi e salvale di nuovo se servono.');
});

it('Coda 746 — in un esercizio chiuso le letture non si scrivono né si tolgono: niente si scrive nell\'anno chiuso', function () {
    $s = b15lfScenario($this);
    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors();
    $prima = DB::table('letture_consuntivo')->orderBy('id')->get()->toArray();
    $s['e']->update(['stato' => 'chiuso']);

    $this->actingAs($this->user)->from('/piano')->put(b15lfRotta($s, 'update'), b15lfCorpo($s, ['termico' => ['modo' => 'percentuale', 'percentuale' => 25]]))
        ->assertRedirect('/piano')->assertSessionHasErrors(['letture' => LettureDiFineAnno::ESERCIZIO_CHIUSO]);
    $this->actingAs($this->user)->from('/piano')->delete(b15lfRotta($s, 'destroy'))->assertRedirect('/piano')->assertSessionHasErrors('letture');

    expect(DB::table('letture_consuntivo')->orderBy('id')->get()->toArray())->toEqual($prima);
});

it('Coda 746 — tolte le letture, il conguaglio torna ai coefficienti della voce', function () {
    $s = b15lfScenario($this);
    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors();

    $this->actingAs($this->user)->delete(b15lfRotta($s, 'destroy'))->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('letture_consuntivo')->count())->toBe(0)
        ->and(b15lfConguaglio($this, $s))->toBe(['Neri' => [120000, 0], 'Bianchi' => [60000, 0], 'Verdi' => [120000, 0]]);
});

it('Coda 746 — una tabella aggiunta alla voce dopo le letture: le letture non valgono più, il conguaglio usa i coefficienti e la pagina dice di riscriverle', function () {
    $s = b15lfScenario($this);
    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors();
    $altra = cpmTabella($s, 'Cancello', ['U1' => 1000]);
    DB::table('conto_tabella_millesimale')->insert(['conto_id' => $s['voce']->id, 'tabella_id' => $altra->id, 'coefficiente' => 0.01, 'created_at' => now(), 'updated_at' => now()]);

    expect(LettureDiFineAnno::coefficienti([$s['voce']->id], $s['e']->id))->toBe([])
        ->and($this->actingAs($this->user)->getJson(b15lfRotta($s, 'show'))->json('da_riscrivere'))->toBeTrue();
});

it('Coda 746, la catena — la scheda «Controlli» lo dice, il controllo per unità torna con le letture, la stampa per tabella le usa', function () {
    $s = b15lfScenario($this);
    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors();
    $this->travelTo('2026-12-31 12:00:00');
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e']->fresh(), [], true);
    $controlli = collect(ControlliDelConguaglio::tutti($letto, ['capitoli_netti' => 300000], ['esito' => 'quadra', 'nota' => '']))->keyBy('id');

    expect($controlli['CB']['esito'])->toBe('segnalazione')
        ->and($controlli['CB']['breve'])->toBe('letture di fine anno')
        ->and($controlli['CB']['nota'])->toContain('Energia elettrica divisa con le letture del 31/12. Ascensore 50 % invece di 40 %, Luci scale 30 % invece di 40 %: il conguaglio sposta € 300,00 da «Luci scale» a «Ascensore».')
        ->and($controlli['CU']['esito'])->toBe('quadra')
        ->and($controlli['CG']['esito'])->toBe('quadra');

    $stampa = app(ConguaglioDelRendiconto::class)->perTabella($s['c'], $s['e']->fresh(), $letto);
    expect($stampa['gestioni'][0]['totali'])->toBe([$s['ascensore']->id => 150000, $s['termico']->id => 60000, $s['luci']->id => 90000]);
});

it('Coda 746 — senza letture la scheda «Controlli» non dice niente delle letture', function () {
    $s = b15lfScenario($this);
    $this->travelTo('2026-12-31 12:00:00');
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e']->fresh());

    expect(ControlliDelConguaglio::frasiDelleLetture($letto))->toBe([]);
});

it('Coda 746 — la voce di un altro condominio non si legge né si scrive dall\'indirizzo di questo: 404', function () {
    $s = b15lfScenario($this);
    $altro = Condominio::factory()->create();
    $rotta = fn (string $azione) => route("admin.gestionale.esercizi.piani-conti.conti.letture-di-fine-anno.{$azione}", [$altro, $s['e'], $s['pianoConto'], $s['voce']]);

    expect($this->actingAs($this->user)->getJson($rotta('show'))->status())->toBe(404)
        ->and($this->actingAs($this->user)->put($rotta('update'), b15lfCorpo($s))->status())->toBe(404)
        ->and(DB::table('letture_consuntivo')->count())->toBe(0);
});

it('Coda 746 — le letture salvate e tolte sono due gesti nel registro delle modifiche, con la voce e l\'esercizio, e le righe contate nel lotto', function () {
    $s = b15lfScenario($this);
    $this->actingAs($this->user)->put(b15lfRotta($s, 'update'), b15lfCorpo($s))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->delete(b15lfRotta($s, 'destroy'))->assertSessionHasNoErrors();

    $gesti = \App\Models\RegistroModifica::query()->where('azione', 'gesto')->where('riepilogo', 'like', 'Letture di fine anno%')->orderBy('id')->get();
    expect($gesti->pluck('riepilogo')->all())->toBe([
        'Letture di fine anno della voce «' . $s['voce']->nome . '» salvate, esercizio «' . $s['e']->nome . '»',
        'Letture di fine anno della voce «' . $s['voce']->nome . '» tolte, esercizio «' . $s['e']->nome . '»',
    ])->and(\App\Services\RegistroModifiche\CatalogoDelRegistro::SOLO_GESTO)->toHaveKey(\App\Models\Gestionale\LetturaConsuntivo::class);
});

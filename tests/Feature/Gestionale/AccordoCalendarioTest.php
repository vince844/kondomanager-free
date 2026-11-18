<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'accordo di rientro con il calendario e la regola di decadenza, sul caso di Neri (`AccordoCalendarioScenario.php`).
 *
 * - Il calendario: le rate dell'accordo con il giorno e l'importo; la somma deve fare il debito riconosciuto, e un calendario che non torna non si
 *   registra; «fino al» è il giorno dell'ultima rata.
 * - Gli interessi e le spese registrati sono rate del piano degli addebiti, e l'accordo li copre come le altre; anche quelli che scadono il giorno
 *   dell'accordo (registrati la mattina).
 * - Versata o no: quello che va all'accordo copre le rate del calendario in ordine, la più vecchia prima; un centesimo scoperto lascia la rata «non
 *   versata». La regola di decadenza segnala il primo giorno in cui si verifica e il programma lo conserva; la decadenza la registra l'amministratore.
 *
 * Le cifre, rifatte a mano: 225,92 / 3 = 75,3066… → 75,31 + 75,31 + 75,30. Versamento dell'accordo del 31/07, € 75,31: interessi 0,92, sollecito 25,00,
 * rata 1 € 49,39 (75,31 − 0,92 − 25,00), residuo della rata 1 € 150,61. Il 30/09 € 75,30 sulla rata 1: residuo € 75,31. Versati all'accordo 0,92 + 25,00 +
 * (200,00 − 75,31) = 150,61: rata 1 versata, rata 2 versati € 75,30 di € 75,31, rata 3 non versata. La forma «15 giorni» il 16/09 (31/08 + 16), la forma
 * «2 rate» il 01/10 (la rata 3 scade il 30/09).
 */

use App\Models\Gestionale\AccordoRientro;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\CalendarioAccordo;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('il calendario 75,31 + 75,31 + 75,30 si registra con «fino al» all\'ultima rata; una somma diversa dal debito riconosciuto è rifiutata con le due cifre', function () {
    [$s, $interessi, $sollecito] = aclNeri($this);

    // € 225,91: un centesimo in meno.
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario(['75,31', '75,30', '75,30']))
        ->assertStatus(422)->assertJsonValidationErrors(['scadenze' => CalendarioAccordo::sommaCheNonTorna([7531, 7530, 7530], 22592)]);
    // I giorni in ordine, non prima dell'accordo; ogni rata più di zero.
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario(['75,31', '75,31', '75,30'], ['2026-08-31', '2026-07-31', '2026-09-30']))
        ->assertStatus(422)->assertJsonValidationErrors('scadenze');
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario(['75,31', '75,31', '75,30'], ['2026-07-10', '2026-08-31', '2026-09-30']))
        ->assertStatus(422)->assertJsonValidationErrors('scadenze');
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario(['225,92', '0,00'], ['2026-07-31', '2026-08-31']))
        ->assertStatus(422)->assertJsonValidationErrors('scadenze');
    // La regola: le rate non più delle rate del calendario.
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario(), '2026-07-15', 4, 15)->assertStatus(422)->assertJsonValidationErrors('decadenza_rate');
    expect(AccordoRientro::count())->toBe(0);

    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario())->assertCreated();
    $a = AccordoRientro::with('scadenze')->sole();
    expect($a->scadenze->map(fn ($r) => [$r->numero, $r->data->format('Y-m-d'), $r->importo])->all())->toBe([
        [1, '2026-07-31', 7531], [2, '2026-08-31', 7531], [3, '2026-09-30', 7530],
    ])->and($a->fino_al->format('Y-m-d'))->toBe('2026-09-30')
        ->and([$a->decadenza_rate, $a->decadenza_giorni, $a->segnali, $a->decadenza_registrata_il])->toBe([2, 15, null, null]);

    // La finestra lo legge: il calendario, la regola, e per la persona dopo la regola di questo condominio come quella di serie.
    $letto = aclLetto($this, $s);
    expect(aclRate($letto))->toBe([[1, 0, 7531, false], [2, 0, 7531, false], [3, 0, 7530, false]])
        ->and($letto['accordi'][0]['regola'])->toBe(['rate' => 2, 'giorni' => 15])
        ->and($letto['regola_di_serie'])->toBe(['rate' => 2, 'giorni' => 15]);
});

it('gli interessi e le spese registrati si coprono come le altre rate: € 225,92 riconosciuti, senza «oltre il residuo»', function () {
    [$s, $interessi, $sollecito] = aclNeri($this);
    $this->travelTo('2026-07-15 10:00:00');

    // Le rate che l'accordo può coprire il 15/07: la rata 1 con € 200,00, gli interessi € 0,92, il sollecito € 25,00.
    $possibili = collect(aclLetto($this, $s)['rate'])->mapWithKeys(fn ($r) => [$r['rata_id'] => $r['residuo']])->all();
    expect($possibili)->toBe([lcdRata($s, 1) => 20000, $interessi => 92, $sollecito => 2500]);

    // Oltre il residuo resta rifiutato, con la frase che non dice più che il programma non li registra.
    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito, ['200,00', '0,93', '25,00']), aclCalendario(['75,31', '75,31', '75,31']))
        ->assertStatus(422)->assertJsonValidationErrors(['rate' => \App\Http\Controllers\Gestionale\Movimenti\AccordiRientroController::OLTRE_IL_RESIDUO]);
    expect(\App\Http\Controllers\Gestionale\Movimenti\AccordiRientroController::OLTRE_IL_RESIDUO)->not->toContain('non li registra');

    aclRegistra($this, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario())->assertCreated();
    $letto = aclLetto($this, $s);
    expect($letto['accordi'][0])->toMatchArray(['riconosciuti' => 22592, 'oggi' => 22592, 'versati_oltre' => 0]);
});

it('gli interessi registrati la mattina dell\'accordo si coprono; una rata del piano che scade quel giorno no', function () {
    $s = accViaVerdi($this);
    // La rata 2 scade il 15/07, emessa: il giorno della sua scadenza non è scaduta.
    DB::table('rate')->where('id', lcdRata($s, 2))->update(['data_scadenza' => '2026-07-15']);
    DB::table('rate_quote')->where('rata_id', lcdRata($s, 2))->update(['data_scadenza' => '2026-07-15']);
    lcdEmetti($this, $s, [2], '2026-01-15');
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');

    // Il 15/07, la mattina: gli interessi al 15/07, € 300,00 × 1,60 % × 30/365 + € 200,00 × 1,60 % × 76/365 = 0,394521 + 0,666301 = 1,060822 → € 1,06.
    $this->travelTo('2026-07-15 09:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['totale'])->toBe(106);
    $interessi = (int) accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    expect(DB::table('rate')->where('id', $interessi)->value('data_scadenza'))->toStartWith('2026-07-15');

    $possibili = collect(aclLetto($this, $s)['rate'])->mapWithKeys(fn ($r) => [$r['rata_id'] => $r['residuo']])->all();
    expect($possibili)->toBe([lcdRata($s, 1) => 20000, $interessi => 106]);

    $u1 = (int) $s['unita']['U1']->id;
    aclRegistra($this, $s, [['rata_id' => lcdRata($s, 1), 'immobile_id' => $u1, 'importo' => '200,00'], ['rata_id' => $interessi, 'immobile_id' => $u1, 'importo' => '1,06']],
        [['data' => '2026-08-31', 'importo' => '201,06']], '2026-07-15', 1, null)->assertCreated();
    // La rata 2, che scade il 15/07, non entra.
    aclRegistra($this, $s, [['rata_id' => lcdRata($s, 2), 'immobile_id' => $u1, 'importo' => '300,00']], [['data' => '2026-08-31', 'importo' => '300,00']], '2026-07-15')
        ->assertStatus(422)->assertJsonValidationErrors(['rate' => \App\Http\Controllers\Gestionale\Movimenti\AccordiRientroController::RATA_NON_COPRIBILE]);
});

it('versata o no, in ordine: dopo il 30/09 rata 1 versata, rata 2 versati € 75,30 di € 75,31, rata 3 no; i segnali del 16/09 e del 01/10 restano dopo un versamento che salda', function () {
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    $rata1 = lcdQuota($s, 'Neri', 1);
    aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, $rata1 => 4939], '2026-07-31');
    aclVersa($this, $s, [$rata1 => 7530], '2026-09-30');

    // Il 20/09 la forma «15 giorni» c'è già (16/09), l'altra no; la decadenza non è registrata.
    $this->travelTo('2026-09-20 10:00:00');
    $letto = aclLetto($this, $s)['accordi'][0];
    expect($letto['segnali']['giorni']['il'])->toBe('2026-09-16')->and($letto['segnali']['rate'])->toBeNull()
        ->and(aclRate(['accordi' => [$letto]]))->toBe([[1, 7531, 0, true], [2, 0, 7531, false], [3, 0, 7530, false]]);

    $this->travelTo('2026-10-02 10:00:00');
    $letto = aclLetto($this, $s)['accordi'][0];
    expect(aclRate(['accordi' => [$letto]]))->toBe([[1, 7531, 0, true], [2, 7530, 1, false], [3, 0, 7530, false]])
        ->and($letto['segnali'])->toBe([
            'rate' => ['il' => '2026-10-01', 'rate' => [
                ['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531, 'versato' => 7530],
                ['numero' => 3, 'data' => '2026-09-30', 'importo' => 7530, 'versato' => 0],
            ], 'smentito' => false],
            'giorni' => ['il' => '2026-09-16', 'rate' => [['numero' => 2, 'data' => '2026-08-31', 'importo' => 7531, 'versato' => 0]], 'smentito' => false],
        ])
        ->and($letto['oggi'])->toBe(22592 - 15061)
        ->and($letto['decadenza_registrata_il'])->toBeNull()->and($letto['chiuso_il'])->toBeNull();
    // Conservati nell'accordo.
    expect(AccordoRientro::find($a->id)->segnali['giorni']['il'])->toBe('2026-09-16');

    // Il 05/10 Neri versa il resto, € 75,31, con la data del 10/09: tutte le rate versate; i segnali restano quelli di prima, con le loro date e le
    // cifre di quei giorni, e dicono che il giornale di oggi li smentisce: con il versamento del 10/09 il 16/09 la rata 2 era versata (75,31 + 75,31),
    // e il 01/10 tutte e tre.
    $this->travelTo('2026-10-05 10:00:00');
    lcdIncassa($this, $s, 'Neri', [$rata1 => 7531], '2026-09-10');
    $letto = aclLetto($this, $s)['accordi'][0];
    expect(array_column($letto['calendario'], 'versata'))->toBe([true, true, true])
        ->and($letto['segnali']['giorni']['il'])->toBe('2026-09-16')->and($letto['segnali']['rate']['il'])->toBe('2026-10-01')
        ->and($letto['segnali']['giorni']['rate'][0]['versato'])->toBe(0)
        ->and([$letto['segnali']['giorni']['smentito'], $letto['segnali']['rate']['smentito']])->toBe([true, true]);

    // La seconda fonte: su rate coperte per intero, quello che i versamenti hanno dato all'accordo, giorno per giorno, è i riconosciuti meno la parte in
    // accordo della lettura comune a ogni data.
    $servizio = app(AccordiRientro::class);
    foreach (['2026-07-15', '2026-07-31', '2026-09-09', '2026-09-10', '2026-09-30', '2026-10-05'] as $giorno) {
        $lettura = app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id, $giorno)['rate'];
        $segno = $servizio->segni(AccordoRientro::with(['rate', 'scadenze'])->whereKey($a->id)->get(), $lettura, $giorno, false)['accordi'][0];
        expect($servizio->versatoAlGiorno(AccordoRientro::with('rate')->find($a->id), $giorno))->toBe($segno['riconosciuti'] - $segno['oggi'], "il {$giorno}");
    }
});

it('a una data passata la lettura non mostra un segnale che viene dopo', function () {
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');
    $this->travelTo('2026-10-02 10:00:00');
    aclLetto($this, $s);

    $lettura = app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id, '2026-09-20')['rate'];
    $segno = app(AccordiRientro::class)->segni(AccordoRientro::with(['rate', 'scadenze'])->whereKey($a->id)->get(), $lettura, '2026-09-20', false)['accordi'][0];
    expect($segno['segnali']['giorni']['il'])->toBe('2026-09-16')->and($segno['segnali']['rate'])->toBeNull();
});

it('la decadenza la registra l\'amministratore, con la data e una nota: l\'accordo si chiude quel giorno e le rate tornano nelle lettere', function () {
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    $this->travelTo('2026-10-02 10:00:00');
    $rotta = route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]);

    // Il programma non la registra da sé: i segnali ci sono, la decadenza no.
    expect(aclLetto($this, $s)['accordi'][0])->toMatchArray(['decadenza_registrata_il' => null, 'chiuso_il' => null, 'in_corso' => true]);

    $this->actingAs($this->user)->postJson($rotta, ['data' => '2026-07-10'])->assertStatus(422)->assertJsonValidationErrors('data');
    $this->actingAs($this->user)->postJson($rotta, ['data' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors('data');

    $r = $this->actingAs($this->user)->postJson($rotta, ['data' => '2026-10-02', 'nota' => 'Comunicata con la raccomandata del 02/10'])->assertOk();
    expect($r->json('messaggio'))->toBe('Decadenza registrata: da oggi le rate dell\'accordo tornano nelle lettere proposte.');
    $a = $a->fresh();
    expect([$a->decadenza_registrata_il?->format('Y-m-d'), $a->chiuso_il?->format('Y-m-d'), $a->nota_chiusura])->toBe(['2026-10-02', '2026-10-02', 'Comunicata con la raccomandata del 02/10'])
        ->and($a->chiuso_da)->toBe($this->user->id);
    $letto = aclLetto($this, $s)['accordi'][0];
    expect($letto)->toMatchArray(['decadenza_registrata_il' => '2026-10-02', 'chiuso_il' => '2026-10-02', 'in_corso' => false]);

    // Una volta sola; chiuso, non si chiude di nuovo.
    $this->actingAs($this->user)->postJson($rotta, ['data' => '2026-10-02'])->assertStatus(422)->assertJsonValidationErrors('data');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => $a->id]), ['chiuso_il' => '2026-10-02'])
        ->assertStatus(422);

    // Nessuna parola che il programma scriverebbe come un fatto.
    expect(json_encode(aclLetto($this, $s), JSON_UNESCAPED_UNICODE))->not->toMatch('/\b(decadut[oai]|dovut[oai]|esigibil[ei]|moros[oai])\b/iu');
});

it('la decadenza di un accordo di un altro condominio fa 404', function () {
    [$s, , , $a] = aclAccordo($this);
    $altro = accViaVerdi($this);
    $this->travelTo('2026-10-02 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $altro['c']->id, 'accordo' => $a->id]), ['data' => '2026-10-02'])
        ->assertNotFound();
    expect($a->fresh()->chiuso_il)->toBeNull();
});

it('un accordo registrato senza calendario resta come era: nessun calendario, nessun segnale', function () {
    [$s, $interessi, $sollecito] = aclNeri($this);
    $this->travelTo('2026-07-15 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'fino_al' => '2026-12-15', 'autorizzato_assemblea' => '0',
        'rate' => aclCoperte($s, $interessi, $sollecito),
    ])->assertCreated();
    $this->travelTo('2026-12-20 10:00:00');
    $letto = aclLetto($this, $s)['accordi'][0];
    expect($letto['calendario'])->toBeNull()->and($letto['segnali'])->toBe(['rate' => null, 'giorni' => null])->and($letto['fino_al'])->toBe('2026-12-15');
});

it('la decadenza registrata il 20/09, prima di «fino al», rimette le rate nelle lettere proposte da quel giorno; prima la lettera le lasciava fuori', function () {
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');
    $this->travelTo('2026-09-20 10:00:00');
    $anteprima = fn () => $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'tutte' => 1, 'filtri' => [], 'testo' => 'Gentile [nome], risultano da versare [totale].', 'termine' => 15,
    ])->assertOk()->json();

    // Neri ha solo rate in accordo (€ 150,61 della rata 1): non è nella lettera proposta.
    expect(collect($anteprima()['persone'])->firstWhere('nome', 'Neri'))->toBeNull()
        ->and($anteprima()['solo_in_accordo'])->toContain('Neri');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]), ['data' => '2026-09-20'])->assertOk();
    expect(collect($anteprima()['persone'])->firstWhere('nome', 'Neri'))->toMatchArray(['totale' => 15061, 'in_accordo' => 0]);
});

it('una regola sola dopo «fino al»: il 20/09 la lettera e l\'incasso lasciano fuori le rate coperte; il 05/10 la lettera le chiede e l\'incasso le rimette nell\'ordine', function () {
    [$s] = aclAccordo($this, rata2: true);
    $neri = (int) $s['persone']['Neri']->id;
    $lettera = fn () => collect($this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'tutte' => 1, 'filtri' => [], 'testo' => 'Gentile [nome], risultano da versare [totale].', 'termine' => 15,
    ])->assertOk()->json('persone'))->firstWhere('nome', 'Neri');
    $nellOrdine = fn () => (int) collect(app(\App\Services\Gestionale\Incassi\OrdineImputazione::class)->rateAperte((int) $s['c']->id, $neri))->sum('in_accordo');
    $riquadro = fn () => $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $s['c']->id, 'anagrafica_id' => $neri]))->json('accordo');

    // Prima di «fino al» (30/09): la lettera chiede la sola rata 2 (€ 300,00), l'incasso tiene fuori i € 225,92 e ha il riquadro dell'accordo.
    $this->travelTo('2026-09-20 10:00:00');
    expect($lettera())->toMatchArray(['totale' => 30000, 'in_accordo' => 22592])->and($nellOrdine())->toBe(22592)->and($riquadro())->not->toBeNull();

    // Dopo «fino al»: la lettera chiede anche le rate coperte, e l'incasso le rimette nell'ordine, senza il riquadro.
    $this->travelTo('2026-10-05 10:00:00');
    expect($lettera())->toMatchArray(['totale' => 52592, 'in_accordo' => 0])->and($nellOrdine())->toBe(0)->and($riquadro())->toBeNull();
});

it('un accordo chiuso si legge al giorno prima della chiusura: dopo la decadenza del 02/10 il versamento del 05/10 non è del calendario', function () {
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    $this->travelTo('2026-10-02 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]), ['data' => '2026-10-02'])->assertOk();
    aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 20000], '2026-10-05', false);
    $letto = aclLetto($this, $s)['accordi'][0];
    expect($letto['rate_non_versate'])->toBe(3)->and(array_column($letto['calendario'], 'versata'))->toBe([false, false, false]);
});

it('una regola di decadenza senza il calendario non si registra, e lo dice', function () {
    [$s, $interessi, $sollecito] = aclNeri($this);
    $this->travelTo('2026-07-15 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'fino_al' => '2026-12-15', 'autorizzato_assemblea' => '0',
        'rate' => aclCoperte($s, $interessi, $sollecito), 'decadenza_giorni' => 15,
    ])->assertStatus(422)->assertJsonValidationErrors(['decadenza_rate' => \App\Http\Controllers\Gestionale\Movimenti\AccordiRientroController::REGOLA_SENZA_CALENDARIO]);
    expect(AccordoRientro::count())->toBe(0);
});

it('finché l\'accordo è in corso la proposta non mette interessi sui giorni dell\'accordo, solo su quelli prima (€ 0,13); registrata la decadenza, la proposta torna: € 0,82 dal 30/06', function () {
    [$s, , , $a] = aclAccordo($this);
    $this->travelTo('2026-09-30 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri');
    // La rata 1 (€ 200,00) è nell'accordo dal 15/07: la riga tiene i giorni prima, dal 30/06 (il giorno dopo gli interessi registrati) al 14/07, e la
    // proposta dice l'accordo accanto alla persona. € 200,00 × 1,60 % × 15/365 = 0,131507 → € 0,13 (decisione 438; con la 433 la riga non c'era).
    expect(array_map(fn ($r) => [$r['dal'], $r['al'], $r['accordo_dal'], $r['interessi']], $neri['righe']))->toBe([['2026-06-30', '2026-07-14', '2026-07-15', 13]])
        ->and($neri['totale'])->toBe(13)->and($neri['in_accordo'])->toBe(20000);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]), ['data' => '2026-09-30'])->assertOk();
    // Gli interessi registrati arrivano al 29/06: dal 30/06 al 30/09, 93 giorni: € 200,00 × 1,60 % × 93/365 = 0,815342 → € 0,82.
    $neri = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri');
    expect($neri['totale'])->toBe(82)->and($neri['in_accordo'])->toBe(0);
});

it('quello che abbassa il residuo senza essere un versamento non è versato all\'accordo: una scrittura di € 100,00 sulla rata 1 che non è un incasso', function () {
    [$s, , , $a] = aclAccordo($this);
    $this->travelTo('2026-07-20 10:00:00');
    // Una scrittura che chiude € 100,00 della rata 1 di Neri senza denaro (la forma di uno stralcio): il residuo scende, il calendario no.
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    $altro = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->value('id');
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'numero_protocollo' => 'PROVA-1', 'data_registrazione' => '2026-07-20', 'data_competenza' => '2026-07-20', 'causale' => 'Prova', 'tipo_movimento' => 'stralcio_credito', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $sc, 'conto_contabile_id' => $crediti, 'anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U1']->id, 'rata_id' => lcdRata($s, 1),
            'tipo_riga' => 'avere', 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $sc, 'conto_contabile_id' => $altro, 'anagrafica_id' => null, 'immobile_id' => null, 'rata_id' => null,
            'tipo_riga' => 'dare', 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $letto = aclLetto($this, $s)['accordi'][0];
    expect(aclRate(['accordi' => [$letto]])[0])->toBe([1, 0, 7531, false])
        // Nell'accordo resta quello che il residuo dice: € 100,00 della rata 1, con gli interessi e il sollecito.
        ->and($letto['oggi'])->toBe(10000 + 92 + 2500);
});

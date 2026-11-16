<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La finestra «Emetti le rate» del cassetto della tesoreria, dal lato del server.
 *
 * - I suoi dati (`emissioneRapida`) arrivano solo quando la finestra li chiede, con un caricamento parziale della dashboard: i piani approvati con rate non
 *   emesse, e di ognuno tutte le rate non emesse con la scadenza, la cifra e i condòmini; le rate fino a 30 giorni sono quelle dell'azione, con la stessa cifra.
 *   Un piano in bozza, il piano degli addebiti, una rata già a giornale: fuori. Una rata senza tutte le persone del piano porta le sue.
 * - L'emissione va alla rotta della pagina del piano (`EmissioneRateController::store`) con `da=dashboard`: emette le rate scelte con la data, la descrizione e
 *   l'avviso scelti, e torna alla dashboard (con la scheda dell'Inbox che c'era); un caricamento parziale riporta la tesoreria, dove l'azione non ha più
 *   le rate emesse e sparisce quando non ne restano, l'Inbox operativa e i sei mesi, non la copertura né i dati della finestra.
 * - Ogni rifiuto (il piano non approvato, il piano degli addebiti, la delibera cambiata, un passaggio da seguire) arriva uguale dalla dashboard e dalla pagina
 *   del piano, e non emette niente; gli errori della richiesta hanno le loro frasi, e una rata di un altro piano è rifiutata.
 * - Dalla pagina del piano tutto come prima: si torna alla pagina da cui si è emesso, anche con gli errori.
 */

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** Quattro persone (rossi € 400,00 a rata, bianchi € 300,00, verdi € 200,00, neri € 100,00), 12 rate mensili dal 05/01/2026; emesse quelle date; oggi il 20/05/2026. */
function erpCondominio($test, array $emesse): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata', [], 1200000);
    if ($emesse !== []) {
        lcdEmetti($test, $s, $emesse, '2026-01-02');
    }
    $test->travelTo('2026-05-20 10:00:00');

    return $s;
}

function erpRotta(array $s): string
{
    return route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]);
}

function erpDashboard(array $s): string
{
    return route('admin.gestionale.index', $s['c']);
}

function erpPaginaDelPiano(array $s): string
{
    return route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]);
}

/** Le intestazioni di un caricamento parziale della dashboard, con le prop chieste. */
function erpParziale(string $prop): array
{
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione, 'X-Inertia-Partial-Component' => 'gestionale/dashboard/Dashboard', 'X-Inertia-Partial-Data' => $prop];
}

/** I dati della finestra, come li chiede la finestra. */
function erpFinestra($test, array $s): array
{
    $r = $test->actingAs($test->user)->get(erpDashboard($s), erpParziale('emissioneRapida'));
    $r->assertOk();

    return $r->json('props.emissioneRapida');
}

/** Le scritture dell'emissione del condominio. */
function erpEmissioni(array $s): int
{
    return DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->count();
}

/** Un secondo piano del condominio con una rata del 01/03/2026 per rossi e bianchi (€ 50,00 ognuno), senza l'esercizio salvato. */
function erpSecondoPiano(array $s, string $nome, string $stato = 'approvato', ?string $natura = null): array
{
    $id = DB::table('piani_rate')->insertGetId(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => null, 'nome' => $nome, 'stato' => $stato,
        'tipo' => 'ordinario', 'natura' => $natura, 'numero_rate' => 1, 'giorno_scadenza' => 1, 'data_prima_scadenza' => '2026-03-01', 'metodo_distribuzione' => 'prima_rata',
        'created_at' => now(), 'updated_at' => now()]);
    $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $id, 'numero_rata' => 1, 'data_scadenza' => '2026-03-01', 'importo_totale' => 10000, 'stato' => 'bozza',
        'created_at' => now(), 'updated_at' => now()]);
    foreach (['rossi' => 'A', 'bianchi' => 'B'] as $chi => $unita) {
        $modello = (array) DB::table('rate_quote')->where('id', lcdQuota($s, $chi, 7))->first();
        unset($modello['id']);
        DB::table('rate_quote')->insert(array_merge($modello, ['rata_id' => $rata, 'importo' => 5000, 'importo_pagato' => 0, 'scrittura_contabile_id' => null,
            'data_scadenza' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()]));
    }

    return ['id' => $id, 'rata' => $rata];
}

/** Lo stato che fa rifiutare l'emissione, e la frase che lo dice. */
function erpRifiuto($test, array $s, string $caso): string
{
    switch ($caso) {
        case 'non approvato':
            DB::table('piani_rate')->where('id', $s['piano']->id)->update(['stato' => 'bozza']);

            return 'Devi approvare il piano rate prima di poter emettere le rate.';
        case 'addebiti':
            DB::table('piani_rate')->where('id', $s['piano']->id)->update(['natura' => PianoRate::NATURA_ADDEBITI]);

            return '«Preventivo 2026» ' . PianoRate::FRASE_DEGLI_ADDEBITI;
        case 'delibera':
            // Uno straordinario con le righe calcolate sulla delibera del 10/01, e il piano che ora registra quella del 12/02.
            DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria']);
            DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => '2026-02-12']);
            DB::table('righe_riparto')->insert(['piano_rate_id' => $s['piano']->id, 'tipo' => 'riparto', 'anagrafica_id' => null, 'immobile_id' => null, 'conto_nome' => 'Facciata',
                'conto_radice_nome' => 'Facciata', 'importo' => 100000, 'competenza_dal' => '2026-01-10', 'competenza_al' => '2026-01-10', 'gradino_competenza' => 'delibera',
                'versione_calcolo' => 'prova', 'created_at' => now(), 'updated_at' => now()]);

            return 'Le quote di questo piano sono state calcolate con la delibera del 10 gennaio 2026, ma il piano registra una delibera del 12 febbraio 2026: ricalcola il piano prima di emettere.';
        default:
            // Rossi vende l'interno A dal 01/02/2026 a Elsa, dopo il calcolo del piano: le sue quote vanno ricalcolate prima di emettere.
            $elsa = Anagrafica::forceCreate(['nome' => 'Elsa Acquirente', 'email' => "erp-elsa{$s['c']->id}@test.it", 'indirizzo' => 'Via Roma 1',
                'codice_fiscale' => 'ERPACQUIRENTE' . str_pad((string) $s['c']->id, 3, '0', STR_PAD_LEFT)]);
            $elsa->condomini()->syncWithoutDetaching([$s['c']->id]);
            $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['immobili']['A']->id)->where('anagrafica_id', $s['persone']['rossi']->id)->value('id');
            $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['immobili']['A']]), [
                'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $elsa->id, 'decorrenza' => '2026-02-01', 'quota' => 100, 'tipologia' => 'proprietario',
                'copia_autentica' => true, 'copia_autentica_il' => '2026-02-01', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true,
                'nota_cancello' => 'Rogito letto, piano da ricalcolare',
            ])->assertSessionHasNoErrors();

            return 'Le quote di questo piano sono state calcolate prima di questo passaggio:';
    }
}

it('i dati della finestra arrivano solo quando la finestra li chiede: tutte le rate non emesse del piano, e fino a 30 giorni le rate dell\'azione con la stessa cifra', function () {
    $s = erpCondominio($this, [1, 2, 3]);

    // La pagina intera non li calcola.
    $props = $this->actingAs($this->user)->get(erpDashboard($s))->assertOk()->viewData('page')['props'];
    expect($props)->not->toHaveKey('emissioneRapida');

    $f = erpFinestra($this, $s);
    expect($f['oggi'])->toBe('2026-05-20')->and($f['fino'])->toBe('2026-06-19')->and($f['piani'])->toHaveCount(1);
    $p = $f['piani'][0];
    expect($p)->toMatchArray(['pianoId' => $s['piano']->id, 'piano' => 'Preventivo 2026', 'esercizioId' => $s['e']->id, 'persone' => 4])
        ->and(array_column($p['rate'], 'numero'))->toBe([4, 5, 6, 7, 8, 9, 10, 11, 12])
        ->and(array_column($p['rate'], 'rataId'))->toBe(array_map(fn ($n) => lcdRata($s, $n), range(4, 12)))
        ->and(array_column($p['rate'], 'scadenza'))->toBe(['2026-04-05', '2026-05-05', '2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05', '2026-12-05'])
        ->and(array_unique(array_column($p['rate'], 'cifra')))->toBe([100000])
        ->and(array_unique(array_column($p['rate'], 'persone')))->toBe([4])
        ->and(array_unique(array_column($p['rate'], 'chi'), SORT_REGULAR))->toBe([null]);

    // Le rate fino al 19/06 sono quelle dell'azione «Emetti le rate» della tesoreria, con la stessa cifra.
    $azione = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['datiDelleAzioni']['rateDaEmettere'][0];
    $finoA30 = array_values(array_filter($p['rate'], fn ($r) => $r['scadenza'] <= $f['fino']));
    expect(array_column($finoA30, 'rataId'))->toBe(array_column($azione['rate'], 'rataId'))
        ->and(array_sum(array_column($finoA30, 'cifra')))->toBe($azione['cifra'])->toBe(300000);
});

it('nella finestra non ci sono il piano in bozza, il piano degli addebiti, le rate già a giornale; una rata senza tutte le persone del piano porta le sue', function () {
    $s = erpCondominio($this, [1, 2, 3]);
    $secondo = erpSecondoPiano($s, 'Scale 2026');
    erpSecondoPiano($s, 'Ascensore 2026', 'bozza');
    erpSecondoPiano($s, 'Addebiti 2026', 'approvato', PianoRate::NATURA_ADDEBITI);
    // Neri non ha la rata 7; rossi ha già versato € 150,00 della rata 8.
    DB::table('rate_quote')->where('id', lcdQuota($s, 'neri', 7))->delete();
    DB::table('rate_quote')->where('id', lcdQuota($s, 'rossi', 8))->update(['importo_pagato' => 15000]);

    $f = erpFinestra($this, $s);
    // L'ordine è quello della prima scadenza da emettere: «Scale 2026» (01/03) prima di «Preventivo 2026» (05/04). Senza l'esercizio salvato, quello della gestione.
    expect(array_column($f['piani'], 'piano'))->toBe(['Scale 2026', 'Preventivo 2026'])
        ->and($f['piani'][0])->toMatchArray(['pianoId' => $secondo['id'], 'esercizioId' => $s['e']->id, 'persone' => 2])
        ->and($f['piani'][0]['rate'])->toBe([['rataId' => $secondo['rata'], 'numero' => 1, 'scadenza' => '2026-03-01', 'cifra' => 10000, 'persone' => 2, 'chi' => null]]);
    $rate = collect($f['piani'][1]['rate'])->keyBy('numero');
    $tre = collect([$s['persone']['rossi']->id, $s['persone']['bianchi']->id, $s['persone']['verdi']->id])->sort()->values()->all();
    expect($rate->keys()->all())->toBe([4, 5, 6, 7, 8, 9, 10, 11, 12])
        ->and($rate[7])->toMatchArray(['cifra' => 90000, 'persone' => 3, 'chi' => $tre])
        ->and($rate[8])->toMatchArray(['cifra' => 100000 - 15000, 'persone' => 4, 'chi' => null])
        ->and($f['piani'][1]['persone'])->toBe(4);
});

it('l\'emissione dalla finestra emette le rate scelte con la data, la descrizione e l\'avviso scelti, e torna alla dashboard con la scheda dell\'Inbox', function () {
    $s = erpCondominio($this, [1, 2, 3]);
    $dashboard = erpDashboard($s) . '?inbox=scaduti';
    // Le scadenze delle rate nell'area dei condòmini, come le crea l'approvazione del piano.
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user,
        \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));

    $r = $this->actingAs($this->user)->from($dashboard)->post(erpRotta($s), [
        'rate_ids' => [lcdRata($s, 4), lcdRata($s, 5)], 'data_emissione' => '2026-05-18', 'descrizione_personalizzata' => 'Rate di primavera', 'invia_notifiche' => true, 'da' => 'dashboard',
    ]);
    $r->assertRedirect($dashboard)->assertSessionHasNoErrors();
    expect($r->getSession()->get('message'))->toBe(['type' => 'success', 'message' => 'Rate emesse e notificate correttamente ai condòmini.'])
        ->and($r->getSession()->get('esito_emissione'))->toBe(['titolo' => 'Emissione completata', 'testo' => 'Sono state emesse 2 rate.']);
    $scritture = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->where('data_competenza', 'like', '2026-05-18%')->get();
    expect($scritture)->toHaveCount(2)
        ->and($scritture->pluck('causale')->unique()->values()->all())->toBe(['Rate di primavera'])
        ->and(DB::table('rate')->whereIn('id', [lcdRata($s, 4), lcdRata($s, 5)])->pluck('stato')->unique()->values()->all())->toBe(['emessa'])
        ->and(DB::table('rate')->where('id', lcdRata($s, 6))->value('stato'))->toBe('bozza');
    // L'avviso acceso: la scadenza della rata si vede nell'area dei condòmini.
    $eventi = DB::table('eventi')->where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->get()
        ->filter(fn ($e) => (int) (json_decode((string) $e->meta, true)['context']['rata_id'] ?? 0) === lcdRata($s, 4));
    expect($eventi)->not->toBeEmpty()
        ->and($eventi->pluck('visibility')->unique()->values()->all())->toBe(['private'])
        ->and($eventi->map(fn ($e) => json_decode((string) $e->meta, true)['is_published'])->unique()->values()->all())->toBe([true]);

    // Spento: la rata va a giornale nascosta, e il messaggio lo dice.
    $r = $this->actingAs($this->user)->from($dashboard)->post(erpRotta($s), [
        'rate_ids' => [lcdRata($s, 6)], 'data_emissione' => '2026-05-20', 'descrizione_personalizzata' => '', 'invia_notifiche' => false, 'da' => 'dashboard',
    ]);
    $r->assertRedirect($dashboard);
    expect($r->getSession()->get('message')['message'])->toBe('Rate emesse in modalità silenziosa. I condòmini non vedranno gli importi finché non li pubblicherai.')
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->where('data_competenza', 'like', '2026-05-20%')->value('causale'))
        ->toBe('Emissione ' . DB::table('rate')->where('id', lcdRata($s, 6))->value('descrizione'));
    $nascoste = DB::table('eventi')->where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->get()
        ->filter(fn ($e) => (int) (json_decode((string) $e->meta, true)['context']['rata_id'] ?? 0) === lcdRata($s, 6));
    expect($nascoste)->not->toBeEmpty()->and($nascoste->pluck('visibility')->unique()->values()->all())->toBe(['hidden']);
});

it('dopo l\'emissione dalla finestra la dashboard riporta la tesoreria, l\'Inbox e i sei mesi: l\'azione non ha più le rate emesse, e sparisce quando non ne restano', function () {
    $s = erpCondominio($this, [1, 2, 3]);
    $this->withHeaders(erpParziale('widgets,flash,inboxTasks,inboxSchede,inboxScaduti,seiMesi,compitiSeiMesi'));

    $r = $this->actingAs($this->user)->from(erpDashboard($s))->post(erpRotta($s), [
        'rate_ids' => [lcdRata($s, 4), lcdRata($s, 5)], 'data_emissione' => '2026-05-20', 'invia_notifiche' => true, 'da' => 'dashboard',
    ]);
    $r->assertRedirect(erpDashboard($s));
    $props = $this->actingAs($this->user)->get($r->headers->get('Location'))->assertOk()->json('props');
    expect($props)->toHaveKeys(['widgets', 'flash', 'inboxTasks', 'inboxSchede', 'inboxScaduti', 'seiMesi', 'compitiSeiMesi'])
        ->not->toHaveKeys(['copertura', 'fattureScoperte', 'pianiDisallineati', 'emissioneRapida'])
        ->and($props['flash']['message'])->toBe(['type' => 'success', 'message' => 'Rate emesse e notificate correttamente ai condòmini.'])
        ->and($props['flash']['esito_emissione']['testo'])->toBe('Sono state emesse 2 rate.');
    $azione = $props['widgets']['treasury_guardian']['datiDelleAzioni']['rateDaEmettere'];
    expect(array_column($azione[0]['rate'], 'rataId'))->toBe([lcdRata($s, 6)])->and($azione[0]['cifra'])->toBe(100000);

    $r = $this->actingAs($this->user)->from(erpDashboard($s))->post(erpRotta($s), ['rate_ids' => [lcdRata($s, 6)], 'data_emissione' => '2026-05-20', 'invia_notifiche' => true, 'da' => 'dashboard']);
    $props = $this->actingAs($this->user)->get($r->headers->get('Location'))->assertOk()->json('props');
    expect($props['widgets']['treasury_guardian']['datiDelleAzioni']['rateDaEmettere'])->toBe([]);
});

it('ogni rifiuto dell\'emissione arriva uguale dalla finestra e dalla pagina del piano, e non emette niente', function (string $caso) {
    $s = erpCondominio($this, []);
    $atteso = erpRifiuto($this, $s, $caso);
    $corpo = ['rate_ids' => [lcdRata($s, 4), lcdRata($s, 5)], 'data_emissione' => '2026-05-20', 'invia_notifiche' => true];

    $dalPiano = $this->actingAs($this->user)->from(erpPaginaDelPiano($s))->post(erpRotta($s), $corpo);
    $dalPiano->assertRedirect(erpPaginaDelPiano($s));
    $frase = $dalPiano->getSession()->get('message');
    $this->flushSession();
    $dallaFinestra = $this->actingAs($this->user)->from(erpDashboard($s))->post(erpRotta($s), $corpo + ['da' => 'dashboard']);
    $dallaFinestra->assertRedirect(erpDashboard($s));

    expect($frase['type'])->toBe('error')
        ->and($frase['message'])->toContain($atteso)
        ->and($dallaFinestra->getSession()->get('message'))->toBe($frase)
        ->and(erpEmissioni($s))->toBe(0)
        ->and(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(0);
})->with(['non approvato', 'addebiti', 'delibera', 'passaggio']);

it('una rata di un altro piano è rifiutata, e gli errori della richiesta hanno le loro frasi, uguali dalla finestra e dalla pagina del piano', function () {
    $s = erpCondominio($this, [1, 2, 3]);
    $altro = erpSecondoPiano($s, 'Scale 2026');
    $casi = [
        [['rate_ids' => [lcdRata($s, 4), $altro['rata']], 'data_emissione' => '2026-05-20'], ['rate_ids.1' => 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.']],
        [['rate_ids' => [], 'data_emissione' => '2026-05-20'], ['rate_ids' => 'Scegli almeno una rata da emettere.']],
        [['rate_ids' => [lcdRata($s, 4)], 'data_emissione' => ''], ['data_emissione' => 'Scrivi la data di emissione.']],
    ];
    foreach ($casi as [$corpo, $errori]) {
        $this->flushSession();
        $this->actingAs($this->user)->from(erpPaginaDelPiano($s))->post(erpRotta($s), $corpo)
            ->assertRedirect(erpPaginaDelPiano($s))->assertSessionHasErrors($errori);
        $this->flushSession();
        $this->actingAs($this->user)->from(erpDashboard($s))->post(erpRotta($s), $corpo + ['da' => 'dashboard'])
            ->assertRedirect(erpDashboard($s))->assertSessionHasErrors($errori);
    }
    expect(DB::table('rate')->where('id', $altro['rata'])->value('stato'))->toBe('bozza')
        ->and(DB::table('rate')->where('id', lcdRata($s, 4))->value('stato'))->toBe('bozza')
        ->and(erpEmissioni($s))->toBe(3);
});

it('il ritorno alla dashboard: senza la pagina di prima, o da un\'altra pagina, la dashboard del condominio; un altro valore torna alla pagina di prima', function () {
    $s = erpCondominio($this, [1, 2, 3]);
    $altro = erpCondominio($this, []);
    $estratto = route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['rossi']]);
    $corpo = fn (int $n) => ['rate_ids' => [lcdRata($s, $n)], 'data_emissione' => '2026-05-20', 'invia_notifiche' => true];

    // Senza la pagina di prima (`from()` la lascia nelle richieste dopo: questa viene prima).
    $this->actingAs($this->user)->post(erpRotta($s), $corpo(6) + ['da' => 'dashboard'])->assertRedirect(erpDashboard($s));
    // Da un'altra pagina del gestionale, o dalla dashboard di un altro condominio: la dashboard di questo, senza scelte.
    $this->actingAs($this->user)->from($estratto)->post(erpRotta($s), $corpo(4) + ['da' => 'dashboard'])->assertRedirect(erpDashboard($s));
    $this->actingAs($this->user)->from(erpDashboard($altro) . '?inbox=scaduti')->post(erpRotta($s), $corpo(5) + ['da' => 'dashboard'])->assertRedirect(erpDashboard($s));
    // Un valore che non è «dashboard», o un indirizzo, non porta altrove: si torna alla pagina di prima, come dalla pagina del piano.
    $this->actingAs($this->user)->from($estratto)->post(erpRotta($s), $corpo(7) + ['da' => 'https://altro.example/x'])->assertRedirect($estratto);
    expect(DB::table('rate')->whereIn('id', array_map(fn ($n) => lcdRata($s, $n), [4, 5, 6, 7]))->pluck('stato')->unique()->values()->all())->toBe(['emessa']);
});

it('dalla pagina del piano tutto come prima: l\'emissione torna alla pagina da cui si è emesso, con il messaggio e l\'esito', function () {
    $s = erpCondominio($this, [1, 2, 3]);

    $r = $this->actingAs($this->user)->from(erpPaginaDelPiano($s))->post(erpRotta($s), ['rate_ids' => [lcdRata($s, 4)], 'data_emissione' => '2026-05-20', 'descrizione_personalizzata' => '', 'invia_notifiche' => true]);
    $r->assertRedirect(erpPaginaDelPiano($s))->assertSessionHasNoErrors();
    expect($r->getSession()->get('message'))->toBe(['type' => 'success', 'message' => 'Rate emesse e notificate correttamente ai condòmini.'])
        ->and($r->getSession()->get('esito_emissione'))->toBe(['titolo' => 'Emissione completata', 'testo' => 'È stata emessa 1 rata.'])
        ->and(DB::table('rate')->where('id', lcdRata($s, 4))->value('stato'))->toBe('emessa');

    // La stessa rata di nuovo (la pagina aperta in due schede): nessuna rata emessa, l'avviso, e la pagina del piano.
    $r = $this->actingAs($this->user)->from(erpPaginaDelPiano($s))->post(erpRotta($s), ['rate_ids' => [lcdRata($s, 4)], 'data_emissione' => '2026-05-20', 'invia_notifiche' => true]);
    $r->assertRedirect(erpPaginaDelPiano($s));
    expect($r->getSession()->get('message'))->toBe(['type' => 'warning', 'message' => 'La rata 4 era già emessa.'])
        ->and($r->getSession()->get('esito_emissione'))->toBe(['titolo' => 'Rate già emesse', 'testo' => 'La rata 4 era già emessa.'])
        ->and(erpEmissioni($s))->toBe(4);
});

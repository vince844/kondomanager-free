<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La lista dello studio dei sei mesi per agire: nell'Action inbox, i sei mesi e i rendiconti in assemblea di tutti i condomini (o del condominio scelto), le
 * date più vicine prima. La pagina porta solo i condomini da calcolare; ogni condominio arriva dopo, con una richiesta sua (`admin.dashboard.sei-mesi`), che
 * pesa quanto un condominio e non scrive niente. La lista dice le stesse cose del riquadro di ogni condominio: le stesse persone aperte, le stesse cifre, lo stesso compito,
 * dal riassunto leggero di `SeiMesiPerAgire` (senza lettere, accordi, crediti e prossima azione, che non cambiano chi è aperto).
 *
 * Qui il server; l'ordine fra i condomini, la regola delle 8 e le righe grigie mentre carica sono della pagina (`SeiMesiDelloStudio.test.ts`,
 * `ActionInbox.test.ts`).
 */

use App\Models\Condominio;
use App\Models\Gestionale\RendicontoVersione;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

afterEach(function () {
    SeiMesiPerAgire::$sogliaQuote = 100_000;
});

/** Il condominio nella lista dello studio, dalla richiesta della pagina. */
function smsStudio($test, array $s)
{
    return $test->actingAs($test->user)->getJson(route('admin.dashboard.sei-mesi', ['condominio' => $s['c']->id]));
}

/** Una versione del rendiconto, fermata o approvata. */
function smsVersione(array $s, $e, string $stato, string $calcolato, ?string $delibera = null): RendicontoVersione
{
    return RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'numero' => RendicontoVersione::where('esercizio_id', $e->id)->count() + 1,
        'stato' => $stato, 'forma' => 1, 'cifre' => [], 'calcolato_il' => $calcolato, 'data_delibera' => $delibera, 'numero_verbale' => $delibera === null ? null : '3']);
}

it('l\'Action inbox porta i condomini da calcolare, in ordine di nome, e nessuna riga: le righe arrivano dopo, un condominio per richiesta', function () {
    $s = smaViaVerdi($this);
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    // L'Action inbox chiede «Visualizza scadenze in agenda»: servono i permessi veri.
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $studio = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $studio->assignRole(\App\Enums\Role::AMMINISTRATORE->value);

    $props = $this->actingAs($studio)->get(route('admin.inbox'))->assertOk()->viewData('page')['props'];

    expect($props['seiMesiCondomini'])->toBe([['id' => $altro->id, 'nome' => 'Corso Italia 3'], ['id' => $s['c']->id, 'nome' => 'Via Verdi 12']])
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('sei-mesi-')
        // Le cifre dei sei mesi arrivano con le righe, non con la pagina (che ha le sue, quelle dei compiti).
        ->and(json_encode($props['seiMesiCondomini'], JSON_UNESCAPED_UNICODE))->not->toContain('€');
});

it('le righe di un condominio, in ordine di data, con la data, i giorni, le persone aperte, la cifra, il compito; poi il rendiconto in assemblea', function () {
    $s = smaViaVerdi($this);

    $r = smsStudio($this, $s)->assertOk()->json();

    expect($r['condominio'])->toBe(['id' => $s['c']->id, 'nome' => 'Via Verdi 12'])
        ->and($r['oggi'])->toBe('2026-05-20')
        ->and($r['troppe'])->toBeNull()
        ->and(collect($r['righe'])->map(fn ($x) => [$x['chiave'], $x['esercizio']['nome'], $x['data'], $x['giorni'], $x['colore'], $x['quante'], $x['cifra'], $x['compito']])->all())->toBe([
            ['sei-mesi-' . $s['e2025']->id, 'Esercizio 2025', '2026-06-30', 41, 'vicina', 4, 80000, true],
            ['sei-mesi-' . $s['e']->id, 'Esercizio 2026', '2027-06-30', 406, 'lontana', 4, 120000, false],
        ])
        ->and(collect($r['rendiconti'])->map(fn ($x) => [$x['chiave'], $x['esercizio']['nome'], $x['data'], $x['giorni'], $x['colore'], $x['stato'], $x['breve'], $x['compito']])->all())->toBe([
            ['rendiconto-' . $s['e2025']->id, 'Esercizio 2025', '2026-06-29', 40, 'vicina', 'bozza', 'Bozza', true],
        ])
        // Il cassetto della riga: le persone aperte con la cifra di questo esercizio, la più alta prima (a pari cifra per nome), come nel riquadro.
        ->and(collect($r['righe'][0]['persone'])->map(fn ($p) => [$p['nome'], $p['cifra']])->all())->toBe([['Bianchi', 20000], ['Neri', 20000], ['Rossi', 20000], ['Verdi', 20000]])
        ->and($r['righe'][0]['chiuse'])->toBe(['agito' => 0, 'dispensa' => 0])
        ->and($r['righe'][0]['risposta'])->toBeNull()
        // Né le lettere né la prossima azione: quelle si vedono nel riquadro.
        ->and($r['righe'][0]['persone'][0])->toHaveKeys(['anagrafica_id', 'nome', 'cifra'])
        ->and($r['righe'][0]['persone'][0])->not->toHaveKey('passo');
});

it('la sezione del rendiconto: entro 60 giorni o passata e non approvato; il fermato con la sua frase breve, l\'approvato e il lontano no', function () {
    $s = smaViaVerdi($this);
    \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2024', 'data_inizio' => '2024-01-01', 'data_fine' => '2024-12-31', 'stato' => 'aperto']);
    smsVersione($s, $s['e2025'], RendicontoVersione::PRESENTATA, '2026-05-12 09:00:00');

    $r = smsStudio($this, $s)->assertOk()->json('rendiconti');

    expect(collect($r)->map(fn ($x) => [$x['esercizio']['nome'], $x['giorni'], $x['colore'], $x['breve'], $x['compito']])->all())->toBe([
        ['Esercizio 2024', -325, 'passata', 'Bozza', false],
        ['Esercizio 2025', 40, 'fermato', 'Fermato il 12/05/2026', false],
    ]);

    // Lontano: il 10/01/2026 il 2025 è finito da poco e la sua data è fra 170 giorni.
    $this->travelTo('2026-01-10 10:00:00');
    expect(collect(smsStudio($this, $s)->json('rendiconti'))->pluck('esercizio.nome')->all())->toBe(['Esercizio 2024']);

    // Approvato: non c'è più. Il riquadro lo mostra in verde fino alla data, la lista no.
    $this->travelTo('2026-05-20 10:00:00');
    smsVersione($s, $s['e2025'], RendicontoVersione::APPROVATA, '2026-05-15 09:00:00', '2026-05-18');
    expect(collect(smsStudio($this, $s)->json('rendiconti'))->pluck('esercizio.nome')->all())->toBe(['Esercizio 2024'])
        ->and(collect(smaRiquadro($s)['rendiconti'])->pluck('stato', 'esercizio.nome')->all())->toMatchArray(['Esercizio 2025' => 'approvata']);
});

it('i condomini senza righe non ci sono: nessuna rata scaduta, o tutte le persone chiuse (dispensa, «hai agito»)', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $vuoto = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    expect(smsStudio($this, $vuoto)->assertOk()->json())->toMatchArray(['righe' => [], 'rendiconti' => [], 'troppe' => null]);

    $s = smaViaVerdi($this);
    smaDispensa($s, [$s['e2025'], $s['e']], ['neri']);
    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id,
        'persone' => [$s['persone']['rossi']->id, $s['persone']['bianchi']->id, $s['persone']['verdi']->id], 'giorno' => '2026-05-19', 'nota' => 'pratiche al legale'])
        ->assertSessionHasNoErrors();
    $r = smsStudio($this, $s)->json();

    // La riga del 2025 ha tutte le persone chiuse: nel riquadro c'è («Nessuna persona da seguire»), nella lista no.
    expect(collect($r['righe'])->pluck('esercizio.nome')->all())->toBe(['Esercizio 2026'])
        ->and($r['righe'][0]['quante'])->toBe(3)
        ->and(smaRiga(smaRiquadro($s), 'Esercizio 2025')['quante'])->toBe(0);
});

it('il riassunto leggero dice le stesse cose del riquadro su tre condomini: le persone aperte, le cifre, il compito, la risposta', function () {
    // Via Verdi 12: una diffida spedita, un accordo, «hai agito» per Rossi nel 2025, Neri dispensato in tutti e due gli esercizi.
    $a = smaViaVerdi($this);
    smaLettera($a, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    smaAccordo($a, 'verdi', [1, 2]);
    smaDispensa($a, [$a['e2025'], $a['e']], ['neri']);
    smaRispondi($this, $a, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $a['e2025']->id, 'anagrafica_id' => $a['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'pratica data all\'avv. Gallo'])->assertSessionHasNoErrors();
    // Il secondo: «Non serve» sulla riga del 2025, «Ricordamelo» sul rendiconto.
    $b = smaViaVerdi($this);
    $b['c']->update(['nome' => 'Corso Italia 3']);
    smaRispondi($this, $b, ['tipo' => 'non_serve', 'argomento' => 'sei_mesi', 'esercizio_id' => $b['e2025']->id, 'motivo' => 'assemblea del 20/05 informata'])->assertSessionHasNoErrors();
    smaRispondi($this, $b, ['tipo' => 'ricordamelo', 'argomento' => 'rendiconto', 'esercizio_id' => $b['e2025']->id, 'giorno' => '2026-06-10', 'nota' => ''])->assertSessionHasNoErrors();
    // Il terzo: la dispensa sulla sola data del 2025 sposta le rate 1 e 2 nella riga del 2026; Rossi ha agito nel 2026 e poi torna con una rata nuova.
    $c = smaViaVerdi($this);
    $c['c']->update(['nome' => 'Piazza Duomo 1']);
    smaDispensa($c, [$c['e2025']]);
    smaRispondi($this, $c, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $c['e']->id, 'anagrafica_id' => $c['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'telefonata'])->assertSessionHasNoErrors();
    // Il 10/06 la rata 6 (05/06/2026) è scaduta: Rossi torna; Bianchi registra quel giorno, con la rata 6 già dentro, e resta chiuso.
    $this->travelTo('2026-06-10 10:00:00');
    smaRispondi($this, $c, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $c['e']->id, 'anagrafica_id' => $c['persone']['bianchi']->id,
        'giorno' => '2026-06-09', 'nota' => 'telefonata'])->assertSessionHasNoErrors();

    $servizio = app(SeiMesiPerAgire::class);
    foreach ([$a, $b, $c] as $s) {
        $riquadro = $servizio->perCondominio($s['c']);
        $riassunto = $servizio->riassunto($s['c']);
        $delRiquadro = collect($riquadro['righe'])->map(fn ($x) => [$x['chiave'], $x['data'], $x['giorni'], $x['colore'], $x['quante'], $x['cifra'],
            collect($x['tutte'])->map(fn ($p) => [$p['anagrafica_id'], $p['nome'], $p['cifra']])->all(), $x['compito'], $x['compito_tornato'],
            $x['risposta']['stato'] ?? null, $x['risposta']['frase'] ?? null,
            ['agito' => collect($x['chiuse'])->where('motivo', 'agito')->count(), 'dispensa' => collect($x['chiuse'])->where('motivo', 'dispensa')->count()]])->all();
        $delRiassunto = collect($riassunto['righe'])->map(fn ($x) => [$x['chiave'], $x['data'], $x['giorni'], $x['colore'], $x['quante'], $x['cifra'],
            collect($x['persone'])->map(fn ($p) => [$p['anagrafica_id'], $p['nome'], $p['cifra']])->all(), $x['compito'], $x['compito_tornato'],
            $x['risposta']['stato'] ?? null, $x['risposta']['frase'] ?? null, $x['chiuse']])->all();

        expect($delRiassunto)->toBe($delRiquadro)
            ->and(collect($riassunto['rendiconti'])->map(fn ($x) => [$x['esercizio']['id'], $x['compito']])->all())
            ->toBe(collect($riquadro['rendiconti'])->map(fn ($x) => [$x['esercizio']['id'], $x['compito']])->all());
    }

    // I casi ci sono davvero: Rossi chiuso nel 2025 di Via Verdi, Neri dispensato; il «Non serve» spegne il compito; Rossi torna, Bianchi no.
    $ra = $servizio->riassunto($a['c']);
    $rb = $servizio->riassunto($b['c']);
    $rc = $servizio->riassunto($c['c']);
    expect(collect($ra['righe'][0]['persone'])->pluck('nome')->all())->toBe(['Bianchi', 'Verdi'])
        ->and($ra['righe'][0]['chiuse'])->toBe(['agito' => 1, 'dispensa' => 1])
        ->and($ra['righe'][0]['compito'])->toBeTrue()
        ->and($rb['righe'][0]['compito'])->toBeFalse()
        ->and($rb['righe'][0]['risposta']['stato'])->toBe('non_serve')
        ->and(collect($rc['righe'])->pluck('esercizio.nome')->all())->toBe(['Esercizio 2026'])
        ->and(collect($rc['righe'][0]['persone'])->pluck('nome')->sort()->values()->all())->toBe(['Neri', 'Rossi', 'Verdi'])
        ->and($rc['righe'][0]['chiuse'])->toBe(['agito' => 1, 'dispensa' => 0]);
});

it('la richiesta di un condominio che non c\'è è un 404; chi non ha il pannello non la apre; la richiesta non scrive niente', function () {
    $s = smaViaVerdi($this);

    $this->actingAs($this->user)->getJson(route('admin.dashboard.sei-mesi', ['condominio' => 999999]))->assertNotFound();

    $senzaPannello = \App\Models\User::factory()->create();
    $this->actingAs($senzaPannello)->getJson(route('admin.dashboard.sei-mesi', ['condominio' => $s['c']->id]))->assertForbidden();

    $prima = [DB::table('risposte_promemoria')->count(), DB::table('registro_invii')->count(), DB::table('eventi')->count()];
    smsStudio($this, $s)->assertOk();
    expect([DB::table('risposte_promemoria')->count(), DB::table('registro_invii')->count(), DB::table('eventi')->count()])->toBe($prima);
});

it('sopra la soglia delle quote scadute il condominio non si calcola, e la lista lo dice con la frase del riquadro; i rendiconti restano', function () {
    $s = smaViaVerdi($this);
    SeiMesiPerAgire::$sogliaQuote = 3;

    $r = smsStudio($this, $s)->assertOk()->json();

    expect($r['troppe'])->toBe(['frase' => SeiMesiPerAgire::FRASE_TROPPE])
        ->and($r['righe'])->toBe([])
        ->and(collect($r['rendiconti'])->pluck('esercizio.nome')->all())->toBe(['Esercizio 2025']);
});

it('la risposta della riga arriva com\'è nel riquadro: «Ricordamelo» con il giorno, «Non serve»', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'ricordamelo', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'giorno' => '2026-06-15', 'nota' => ''])->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'assemblea già convocata'])->assertSessionHasNoErrors();

    $r = smsStudio($this, $s)->json();

    expect($r['righe'][0])->toMatchArray(['compito' => false,
        'risposta' => ['stato' => 'ricordamelo', 'ricordami_il' => '2026-06-15', 'frase' => 'Ti ricordo il 15/06/2026.']])
        ->and($r['rendiconti'][0])->toMatchArray(['compito' => false,
            'risposta' => ['stato' => 'non_serve', 'ricordami_il' => null, 'frase' => 'Non serve: «assemblea già convocata» (20/05/2026, Vincenzo).']])
        ->and($r['righe'][0]['risposta']['frase'])->toBe(smaRiga(smaRiquadro($s), 'Esercizio 2025')['risposta']['frase']);
});

it('le parole della lista: niente parole vietate, niente «passo»', function () {
    $s = smaViaVerdi($this);
    smaDispensa($s, [$s['e2025'], $s['e']], ['neri']);
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'telefonata'])->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e']->id, 'motivo' => 'assemblea informata'])->assertSessionHasNoErrors();
    // Le frasi: ogni testo con uno spazio (i nomi delle chiavi e dei casi non ne hanno).
    $frasi = [];
    $raccogli = function (array $r) use (&$frasi) {
        array_walk_recursive($r, function ($v) use (&$frasi) {
            if (is_string($v) && str_contains($v, ' ')) {
                $frasi[] = $v;
            }
        });
    };
    $raccogli(smsStudio($this, $s)->json());
    SeiMesiPerAgire::$sogliaQuote = 3;
    $raccogli(smsStudio($this, $s)->json());
    $testo = mb_strtolower(implode(' | ', $frasi));

    $vietate = ['errore', 'in ritardo', 'deve agire contro', 'esigibile', 'ingiungibil', 'moros', 'diffidat', 'ingiunzion', 'decadut', 'termine scaduto', 'consegnata',
        'notificata', 'transazione'];
    expect($frasi)->not->toBe([])
        ->and(array_values(array_filter($vietate, fn ($p) => str_contains($testo, $p))))->toBe([])
        ->and(preg_match('/\bpass[oi]\b/u', $testo))->toBe(0);
});

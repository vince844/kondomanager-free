<?php

/**
 * Code 316, 324, 325 e 326 — il codice tributo dell'ente non commerciale, la causale CU, le parole delle provvigioni e gli
 * articoli delle ritenute dal 01/01/2027.
 *
 * Le regole da cui vengono gli attesi:
 * - 316: risoluzione 19/E del 5/2/2007 dell'Agenzia delle entrate, 1019 per i percipienti soggetti IRPEF e 1020 per i
 *   soggetti IRES; l'ente non commerciale (art. 73 c. 1 lett. c TUIR) e la cooperativa (lett. a) sono soggetti IRES
 *   (decisione 153.6).
 * - 324: istruzioni della CU 2026, lavoro autonomo e provvigioni: la A presuppone l'esercizio abituale di arte o professione;
 *   per le provvigioni la lettera dipende dal rapporto e dall'abitualità, che il regime non dice (decisione 153.6: A al
 *   lavoro autonomo, null alle due provvigioni).
 * - 325: art. 25-bis c. 2 DPR 600/1973 (dal 2027 art. 39 c. 2 D.Lgs. 33/2025): la ritenuta è commisurata al 50 % delle
 *   provvigioni, al 20 % se il percipiente dichiara di avvalersi in via continuativa dell'opera di dipendenti o di terzi;
 *   con l'aliquota del 23 % fanno l'11,5 % e il 4,6 %.
 * - Etichette dei regimi (decisione 154.4): dal 01/01/2027 gli artt. 23–30 del DPR 600/1973 sono abrogati (art. 241 c. 1 lett. b del
 *   D.Lgs. 33/2025): le etichette che l'amministratore legge nella scheda del fornitore e nel modale dell'XML non citano l'articolo; il
 *   riferimento, con la data, resta nel calcolo (`TipoRitenuta::riferimentoNormativo()`).
 * - 326: art. 243 c. 1 D.Lgs. 33/2025, come modificato dall'art. 4 c. 4 del D.L. 31 dicembre 2025, n. 200 (convertito dalla
 *   legge 27 febbraio 2026, n. 26): il testo unico si applica dal 1° gennaio 2027, e da quella data abroga gli artt. 23–30
 *   del DPR 600/1973 (art. 241 c. 1 lett. b). Gli articoli nuovi: 33 (lavoro dipendente), 38 c. 1 (lavoro autonomo), 38 c. 2
 *   (non residenti), 39 (provvigioni), 40 (condominio).
 */

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\TipoRitenuta;
use App\Models\Gestionale\DelegaF24;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('amministratore');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Il corpo che la scheda del fornitore manda al salvataggio, con il regime d'appalto e la natura scelta. */
function b59rSchedaAppalto(string $natura): array
{
    return [
        'ragione_sociale' => 'Fornitore Test Srl',
        'stato' => 'attivo',
        'modalita_pagamento_default' => 'bonifico',
        'giorni_scadenza' => 30,
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => $natura,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'codice_tributo' => '',
        'certificazione_iso' => false,
        'residente_fiscale' => true,
        'regime_forfetario' => false,
        'provvigioni_base_ridotta' => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Coda 316
// ─────────────────────────────────────────────────────────────────────────────

test('Coda 316 — dalla scheda del fornitore all\'F24, il codice tributo dell\'appalto al 4 % segue l\'imposta del percipiente: 1020 ai soggetti IRES, 1019 ai soggetti IRPEF', function (string $natura, string $codice) {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $ctx = setupPagamentiService();

    $this->actingAs($this->admin)
        ->put(route('admin.fornitori.update', $ctx[3]), b59rSchedaAppalto($natura))
        ->assertSessionHasNoErrors();
    $ctx[3]->refresh();
    expect($ctx[3]->natura_percipiente?->value ?? $ctx[3]->natura_percipiente)->toBe($natura);

    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-03-10'])
    );

    $this->actingAs($this->admin)
        ->from(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->post(route('admin.gestionale.f24.genera', $ctx[0]->id))
        ->assertSessionHasNoErrors();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();

    // € 1.000,00 × 4 % = € 40,00: l'importo non dipende dalla natura, il codice sì.
    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.f24.show', [$ctx[0]->id, $delega->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('delega.righe.0.codice_tributo', $codice)
            ->where('delega.righe.0.importo_debito', 4000)
        );
})->with([
    'ente non commerciale (IRES, art. 73 c. 1 lett. c TUIR)' => ['ente_non_commerciale', '1020'],
    'cooperativa, soggetto IRES (art. 73 c. 1 lett. a TUIR)' => ['soggetto_ires', '1020'],
    'ditta individuale (IRPEF)' => ['persona_fisica_irpef', '1019'],
    'società di persone (IRPEF)' => ['societa_persone_irpef', '1019'],
]);

test('Coda 316 — ogni natura del percipiente, scritta per esteso, è IRPEF o IRES: un case nuovo obbliga a una scelta', function () {
    $attesa = [
        'persona_fisica_irpef' => false,
        'societa_persone_irpef' => false,
        'soggetto_ires' => true,
        'ente_non_commerciale' => true,
    ];

    expect(array_map(fn (NaturaPercipiente $n) => $n->value, NaturaPercipiente::cases()))->toEqual(array_keys($attesa));

    foreach (NaturaPercipiente::cases() as $natura) {
        expect($natura->ires())->toBe($attesa[$natura->value], "natura {$natura->value}")
            ->and(TipoRitenuta::APPALTO_4->codiceTributo($natura))->toBe($attesa[$natura->value] ? '1020' : '1019');
    }
});

test('Coda 316 — nel modulo del fornitore ogni natura del percipiente dice se è IRPEF o IRES, e l\'ente non commerciale dice IRES', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.fornitori.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('natureRecipiente', fn ($nature) => collect($nature)->every(
                fn ($n) => str_contains($n['label'], NaturaPercipiente::from($n['value'])->ires() ? 'IRES' : 'IRPEF')
            ))
            ->where('natureRecipiente', fn ($nature) => collect($nature)->firstWhere('value', 'ente_non_commerciale')['label'] === 'Ente non commerciale (IRES)')
        );
});

test('Coda 316 — una fattura registrata con il codice 1019 di un ente non commerciale lo conserva, e la delega rifatta dice 1020: la scheda della fattura dice che quello è il codice della registrazione', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $ctx = setupPagamentiService();

    $this->actingAs($this->admin)
        ->put(route('admin.fornitori.update', $ctx[3]), b59rSchedaAppalto('ente_non_commerciale'))
        ->assertSessionHasNoErrors();

    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    // La fattura di una versione precedente alla 1.11.0-beta.59 portava il codice congelato 1019 (l'ente non commerciale era IRPEF).
    $dati = $fattura->dati_extra;
    $dati['fiscal']['ritenuta_details']['codice_tributo'] = '1019';
    $fattura->update(['dati_extra' => $dati]);

    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-03-10'])
    );
    $this->actingAs($this->admin)
        ->from(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->post(route('admin.gestionale.f24.genera', $ctx[0]->id))
        ->assertSessionHasNoErrors();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.fatture.show', [$ctx[0]->id, $fattura->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('fattura.dati_extra.fiscal.ritenuta_details.codice_tributo', '1019')
        );

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.f24.show', [$ctx[0]->id, $delega->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('delega.righe.0.codice_tributo', '1020'));
});

// ─────────────────────────────────────────────────────────────────────────────
// Coda 324
// ─────────────────────────────────────────────────────────────────────────────

test('Coda 324 — causaleCU() per ogni regime, scritta per esteso: W all\'appalto, A al lavoro autonomo, null alle due provvigioni, ai non residenti e al lavoro dipendente', function () {
    $attesa = [
        'appalto_4' => 'W',
        'lavoro_autonomo_20' => 'A',
        'provvigioni_base_50' => null,
        'provvigioni_base_20' => null,
        'non_residente_30' => null,
        'lavoro_dipendente' => null,
    ];

    // Un case nuovo che non compare qui fa diventare rosso il test: la sua lettera va scelta, non ereditata dal `default`.
    expect(array_map(fn (TipoRitenuta $t) => $t->value, TipoRitenuta::cases()))->toEqual(array_keys($attesa));

    foreach (TipoRitenuta::cases() as $tipo) {
        expect($tipo->causaleCU())->toBe($attesa[$tipo->value], "regime {$tipo->value}");
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Coda 325
// ─────────────────────────────────────────────────────────────────────────────

test('Coda 325 — l\'elenco dei regimi del fornitore chiama le provvigioni con le parole della legge, nella pagina nuova e in quella di modifica', function () {
    $fornitore = App\Models\Fornitore::create([
        'ragione_sociale' => 'Mediazioni Immobiliari Test',
        'stato' => 'attivo',
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta' => false,
        'perc_imponibile_ritenuta' => 100,
    ]);

    $attese = [
        'provvigioni_base_50' => 'Provvigioni — base 50%, 11,5%',
        'provvigioni_base_20' => 'Provvigioni — base ridotta al 20% con la dichiarazione del percipiente, 4,6%',
    ];

    foreach ([route('admin.fornitori.create'), route('admin.fornitori.edit', $fornitore)] as $pagina) {
        $this->actingAs($this->admin)
            ->get($pagina)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tipiRitenuta', fn ($tipi) => collect($tipi)->firstWhere('value', 'provvigioni_base_50')['label'] === $attese['provvigioni_base_50'])
                ->where('tipiRitenuta', fn ($tipi) => collect($tipi)->firstWhere('value', 'provvigioni_base_20')['label'] === $attese['provvigioni_base_20'])
            );
    }

    // Le percentuali delle etichette sono quelle del calcolo: 23 % sul 50 % e sul 20 % dell'ammontare.
    $data = Carbon::parse('2026-06-01');
    expect(TipoRitenuta::PROVVIGIONI_BASE_50->aliquota($data) * TipoRitenuta::PROVVIGIONI_BASE_50->percentualeBase() / 100)->toBe(11.5)
        ->and(TipoRitenuta::PROVVIGIONI_BASE_20->aliquota($data) * TipoRitenuta::PROVVIGIONI_BASE_20->percentualeBase() / 100)->toBe(4.6);
});

// ─────────────────────────────────────────────────────────────────────────────
// Le etichette dei regimi (decisione 154.4)
// ─────────────────────────────────────────────────────────────────────────────

/** Le etichette dei sei regimi, scritte per esteso: né l'articolo del DPR 600/1973 né quello del D.Lgs. 33/2025. */
function b59rEtichette(): array
{
    return [
        'appalto_4' => 'Appalto di opere/servizi — 4%',
        'lavoro_autonomo_20' => 'Lavoro autonomo — 20%',
        'provvigioni_base_50' => 'Provvigioni — base 50%, 11,5%',
        'provvigioni_base_20' => 'Provvigioni — base ridotta al 20% con la dichiarazione del percipiente, 4,6%',
        'non_residente_30' => "Compenso a non residente — 30% a titolo d'imposta",
        'lavoro_dipendente' => 'Lavoro dipendente (fuori scope: nessun motore paghe)',
    ];
}

test('Decisione 154.4 — le etichette dei sei regimi, scritte per esteso, non citano l\'articolo del DPR 600/1973, abrogato dal 01/01/2027', function () {
    $attese = b59rEtichette();

    // Un case nuovo che non compare qui fa diventare rosso il test: la sua etichetta va scritta, non ereditata.
    expect(array_map(fn (TipoRitenuta $t) => $t->value, TipoRitenuta::cases()))->toEqual(array_keys($attese));

    foreach (TipoRitenuta::cases() as $tipo) {
        expect($tipo->label())->toBe($attese[$tipo->value], "regime {$tipo->value}")
            ->and($tipo->label())->not->toMatch('/\bart\.|DPR|D\.Lgs/i', "regime {$tipo->value}");
    }
});

test('Decisione 154.4 — la scheda del fornitore, nuova e di modifica, offre i sei regimi con le etichette senza articolo', function () {
    $fornitore = App\Models\Fornitore::create([
        'ragione_sociale' => 'Impresa Test',
        'stato' => 'attivo',
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta' => false,
        'perc_imponibile_ritenuta' => 100,
    ]);

    foreach ([route('admin.fornitori.create'), route('admin.fornitori.edit', $fornitore)] as $pagina) {
        $this->actingAs($this->admin)
            ->get($pagina)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tipiRitenuta', fn ($tipi) => collect($tipi)->pluck('label', 'value')->all() === b59rEtichette())
            );
    }
});

test('Decisione 154.4 — le due voci del modale che crea il fornitore dall\'XML dicono come le dice la scheda del fornitore', function () {
    $sorgente = file_get_contents(resource_path('js/components/gestionale/movimenti/fatture/ModalCreaFornitoreDaXml.vue'));

    preg_match('/const REGIMI_PROPONIBILI = \[(.*?)\];/s', $sorgente, $blocco);
    preg_match_all("/label: '([^']*)', value: '([a-z0-9_]+)'/", $blocco[1] ?? '', $voci, PREG_SET_ORDER);
    $trovate = collect($voci)->mapWithKeys(fn ($v) => [$v[2] => $v[1]])->all();

    expect($trovate)->not->toBeEmpty('l\'elenco dei regimi del modale non è stato trovato: il test sarebbe verde senza guardare')
        ->and($trovate)->toBe([
            'appalto_4' => TipoRitenuta::APPALTO_4->label(),
            'lavoro_autonomo_20' => TipoRitenuta::LAVORO_AUTONOMO_20->label(),
        ]);
});

test('Decisione 154.4 — le due voci della domanda sulla ritenuta nella registrazione della fattura dicono come le dice la scheda del fornitore', function () {
    $sorgente = file_get_contents(resource_path('js/pages/gestionale/movimenti/fatture/FatturaRegisterNew.vue'));

    preg_match('/const REGIMI_PROPONIBILI = \[(.*?)\];/s', $sorgente, $blocco);
    preg_match_all("/\{ value: '([a-z0-9_]+)', label: '([^']*)' \}/", $blocco[1] ?? '', $voci, PREG_SET_ORDER);
    $trovate = collect($voci)->mapWithKeys(fn ($v) => [$v[1] => $v[2]])->all();

    expect($trovate)->not->toBeEmpty('l\'elenco dei regimi della pagina non è stato trovato: il test sarebbe verde senza guardare')
        ->and($trovate)->toBe([
            'appalto_4' => TipoRitenuta::APPALTO_4->label(),
            'lavoro_autonomo_20' => TipoRitenuta::LAVORO_AUTONOMO_20->label(),
        ]);
});

test('Coda 325 — la frase della casella della dichiarazione cita il regime con le stesse parole della voce della tendina', function (string $pagina) {
    $sorgente = preg_replace('/\s+/', ' ', file_get_contents(resource_path("js/pages/fornitori/{$pagina}.vue")));

    expect($sorgente)->toContain('il regime è «'.TipoRitenuta::PROVVIGIONI_BASE_20->label().'»');
})->with([
    'la pagina nuova' => 'FornitoriNew',
    'la pagina di modifica' => 'FornitoriEdit',
]);

// ─────────────────────────────────────────────────────────────────────────────
// Coda 326
// ─────────────────────────────────────────────────────────────────────────────

test('Coda 326 — il riferimento normativo di ogni regime, scritto per esteso: l\'articolo del DPR 600/1973 fino al 31/12/2026, quello del D.Lgs. 33/2025 dal 01/01/2027', function () {
    $attesa = [
        'appalto_4' => ['art. 25-ter DPR 600/1973', 'art. 40 D.Lgs. 33/2025'],
        'lavoro_autonomo_20' => ['art. 25 DPR 600/1973', 'art. 38, comma 1, D.Lgs. 33/2025'],
        'provvigioni_base_50' => ['art. 25-bis DPR 600/1973', 'art. 39 D.Lgs. 33/2025'],
        'provvigioni_base_20' => ['art. 25-bis DPR 600/1973 (dichiarazione percipiente)', 'art. 39 D.Lgs. 33/2025 (dichiarazione percipiente)'],
        'non_residente_30' => ['art. 25 DPR 600/1973 (non residenti)', 'art. 38, comma 2, D.Lgs. 33/2025 (non residenti)'],
        'lavoro_dipendente' => ['art. 23 DPR 600/1973', 'art. 33 D.Lgs. 33/2025'],
    ];

    expect(array_map(fn (TipoRitenuta $t) => $t->value, TipoRitenuta::cases()))->toEqual(array_keys($attesa));

    foreach (TipoRitenuta::cases() as $tipo) {
        [$prima, $dopo] = $attesa[$tipo->value];

        // Il fatto generatore è il pagamento: un pagamento del 31/12/2026 e uno del 01/01/2027.
        expect($tipo->riferimentoNormativo(Carbon::parse('2026-12-31')))->toBe($prima, "regime {$tipo->value} al 31/12/2026")
            ->and($tipo->riferimentoNormativo(Carbon::parse('2027-01-01')))->toBe($dopo, "regime {$tipo->value} al 01/01/2027");
    }
});

test('Carbon — un istante a mezzogiorno non è minore o uguale della mezzanotte dello stesso giorno, e toDateString() dà il giorno', function () {
    // Il fatto su cui poggia la correzione di `riferimentoNormativo()`: confrontare istanti con una data «fino_al» a mezzanotte
    // lascia fuori l'ultimo giorno dalle 00:00:01 in poi.
    expect(Carbon::parse('2026-12-31 12:00:00')->lessThanOrEqualTo(Carbon::parse('2026-12-31')))->toBeFalse()
        ->and(Carbon::parse('2026-12-31 12:00:00')->toDateString())->toBe('2026-12-31')
        ->and(Carbon::parse('2027-01-01 00:00:00')->toDateString())->toBe('2027-01-01');
});

test('Coda 326 — il riferimento normativo è quello del giorno, anche se la data ha un\'ora: il 31/12/2026 a qualunque ora vale il DPR, dal 01/01/2027 il D.Lgs. 33/2025', function () {
    $attesa = [
        'appalto_4' => ['art. 25-ter DPR 600/1973', 'art. 40 D.Lgs. 33/2025'],
        'lavoro_autonomo_20' => ['art. 25 DPR 600/1973', 'art. 38, comma 1, D.Lgs. 33/2025'],
        'provvigioni_base_50' => ['art. 25-bis DPR 600/1973', 'art. 39 D.Lgs. 33/2025'],
        'provvigioni_base_20' => ['art. 25-bis DPR 600/1973 (dichiarazione percipiente)', 'art. 39 D.Lgs. 33/2025 (dichiarazione percipiente)'],
        'non_residente_30' => ['art. 25 DPR 600/1973 (non residenti)', 'art. 38, comma 2, D.Lgs. 33/2025 (non residenti)'],
        'lavoro_dipendente' => ['art. 23 DPR 600/1973', 'art. 33 D.Lgs. 33/2025'],
    ];

    foreach (TipoRitenuta::cases() as $tipo) {
        [$prima, $dopo] = $attesa[$tipo->value];

        foreach (['2026-12-31 00:00:00', '2026-12-31 12:00:00', '2026-12-31 23:59:59'] as $istante) {
            expect($tipo->riferimentoNormativo(Carbon::parse($istante)))->toBe($prima, "regime {$tipo->value} al {$istante}");
        }

        foreach (['2027-01-01 00:00:00', '2027-01-01 08:30:00'] as $istante) {
            expect($tipo->riferimentoNormativo(Carbon::parse($istante)))->toBe($dopo, "regime {$tipo->value} al {$istante}");
        }
    }
});

test('Coda 326 — il calcolo della ritenuta di una fattura con una data con l\'ora il 31/12/2026 non si ferma: cita il DPR', function () {
    $fornitore = App\Models\Fornitore::create([
        'ragione_sociale' => 'Impresa Test',
        'stato' => 'attivo',
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'persona_fisica_irpef',
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
    ]);

    // € 1.000,00 × 4 % = € 40,00; il fatto è la data del documento, a mezzogiorno dell'ultimo giorno del DPR.
    $calcolo = app(App\Services\Gestionale\RitenutaService::class)->calcola(
        $fornitore, [['importo_imponibile' => 100000]], 100000, true, Carbon::parse('2026-12-31 12:00:00'),
    );

    expect($calcolo->importo)->toBe(4000)
        ->and($calcolo->riferimentoNormativo)->toBe('art. 25-ter DPR 600/1973');
});

test('Coda 326 — la descrizione del codice tributo 1040 cita anche l\'articolo del testo unico dal 2027', function () {
    $descrizione = config('fiscale.codici_tributo.1040.descrizione');

    expect($descrizione)->toContain('art. 25 DPR 600/1973')
        ->and($descrizione)->toContain('dal 1° gennaio 2027 art. 38 D.Lgs. 33/2025');
});

test('Coda 326 — la descrizione del codice tributo 1040 nomina anche le provvigioni, che dal 2017 si versano con il 1040, con l\'articolo del DPR e quello del testo unico', function () {
    // Il 1038 è soppresso dal 2017 e le provvigioni (art. 25-bis; dal 2027 art. 39 D.Lgs. 33/2025) vanno al 1040 come il lavoro autonomo.
    expect(config('fiscale.codici_tributo.1038.soppresso_dal'))->toBe('2017-01-01')
        ->and(TipoRitenuta::PROVVIGIONI_BASE_50->codiceTributo(null))->toBe('1040')
        ->and(TipoRitenuta::PROVVIGIONI_BASE_20->codiceTributo(null))->toBe('1040');

    expect(config('fiscale.codici_tributo.1040.descrizione'))
        ->toContain('provvigioni (art. 25-bis DPR 600/1973; dal 1° gennaio 2027 art. 39 D.Lgs. 33/2025)');
});

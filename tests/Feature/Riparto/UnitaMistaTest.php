<?php

/**
 * # L'unità mista: Coda 170, decisione 31 (1.11.0-beta.41)
 *
 * Un'unità in parte in piena proprietà e in parte in nuda proprietà più usufrutto: Bice proprietaria piena al 50 %, e
 * sull'altra metà Ugo usufruttuario al 50 % ed Elsa nuda proprietaria al 50 %. Fino alla beta.40 il motore divideva
 * ogni voce sulle sole quote del ruolo che trovava: con la voce sul «Proprietario» trovava solo Bice, e il suo 50 %
 * diventava il 100 % dell'unità; sull'«Usufruttuario» trovava solo Ugo. Denaro sulla persona sbagliata, con i totali
 * del piano perfetti.
 *
 * **La regola (31.1).** Fra Bice e l'altra metà decidono le quote registrate: ogni comproprietario contribuisce secondo
 * la sua quota, ed è la legge. Fra usufruttuario e nudo proprietario della stessa metà decide la voce, cioè
 * l'amministratore. Nel motore: il ruolo della voce si unisce al suo **gemello** nell'unità — il proprietario pieno con
 * il nudo proprietario (il capitale), l'usufruttuario con il proprietario pieno (il godimento) — e la voce si divide
 * sulle quote di tutti e due.
 *
 * **Quando non vale.** Solo se ruolo e gemello sono in vigore insieme (altrimenti l'unità non è mista: è un passaggio,
 * e lo gestisce la cascata sui giorni) e se le quote dell'insieme fanno 100 in ogni tratto. Con quote che non tornano
 * resta la regola della Coda 58 (chi è registrato paga la quota dell'unità): i dati incompleti non si indovinano.
 *
 * **Il perimetro (31.2).** Le voci, la spesa di una sola unità (addebito diretto), il ripiego sui giorni scoperti, i
 * saldi pregressi.
 *
 * **Cosa NON copre.** I saldi pregressi di un'unità diventata mista **con un passaggio**: si calcolano senza periodo, una
 * riga chiusa conta ancora (Coda 174), le quote fanno più di 100 e il pregresso resta com'era — qui si provano solo le
 * unità miste senza passaggi. Un'unità con più persone sullo stesso ruolo e sulla stessa metà (due usufruttuari al 25 %)
 * non ha un caso suo. La tolleranza sulle quote (0,01) non ha un caso al limite. Le stampe del riparto e il dettaglio
 * ricostruito per i piani anteriori alla beta.29 non si guardano: si guarda il riparto del motore e le righe scritte.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\GenerateSaldiAction;
use App\Models\{Anagrafica, Condominio, Esercizio, Gestione, Immobile, Saldo, Tabella};
use App\Models\Gestionale\{Conto, PianoConto, PianoRate, RataQuote};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Due unità da 500 millesimi, una spesa ordinaria da € 1.000,00 sulla tabella al 100 %, con le ripartizioni per ruolo
 * date. L'unità 1 ha gli intestatari passati (chiave → [ruolo, quota, data_fine]); l'unità 2 un proprietario unico.
 * Genera il piano e restituisce, per chiave, quanto paga ciascuno sull'unità 1.
 *
 * Nome lungo di proposito: Pest carica tutti i file nello stesso spazio dei nomi.
 *
 * Ogni intestatario è [ruolo, quota, data_fine?, data_inizio?, persona?]: la quinta voce riusa la persona di un'altra
 * chiave (la stessa persona con due righe, come chi torna proprietario pieno all'estinzione). `$passaggi` sono le righe di
 * `subentri` da scrivere sull'unità 1, [tipologia, tipo_passaggio, decorrenza]: servono a D7 per far valere una data
 * d'inizio senza un predecessore della stessa tipologia.
 *
 * @param array<string, array{0: string, 1: float, 2?: ?string, 3?: ?string, 4?: string}> $intestatari
 * @return array<string, int>
 */
function umQuoteUnitaMista(array $ripartizioni, array $intestatari, array $passaggi = []): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'descrizione' => 'mista',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31',
    ]);
    $gestione->esercizi()->syncWithoutDetaching([$esercizio->id => ['attiva' => true]]);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC']);
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'Spesa', 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => 100000,
    ]);
    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($ripartizioni as $soggetto => $perc) {
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $soggetto,
            'percentuale' => $perc, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $unita = [];
    foreach ([1, 2] as $n) {
        $unita[$n] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento',
            'codice_immobile' => "UM{$n}", 'nome' => "Unità {$n}", 'interno' => (string) $n,
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $unita[$n]->id,
            'valore' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $persone = [];
    foreach ($intestatari as $chiave => $i) {
        if (isset($i[4])) {
            $id = $persone[$i[4]];
        } else {
            $id = Anagrafica::factory()->create(['nome' => "Mista {$chiave}"])->id;
            $persone[$chiave] = $id;
        }
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $id, 'immobile_id' => $unita[1]->id,
            'tipologia' => $i[0], 'quota' => $i[1], 'attivo' => true,
            'data_inizio' => $i[3] ?? '2019-01-01', 'data_fine' => $i[2] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    foreach ($passaggi as [$tipologia, $tipo, $decorrenza]) {
        DB::table('subentri')->insert([
            'condominio_id' => $condominio->id, 'immobile_id' => $unita[1]->id, 'tipologia' => $tipologia,
            'tipo_passaggio' => $tipo, 'decorrenza' => $decorrenza, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $altro = Anagrafica::factory()->create(['nome' => 'Proprietario unità 2']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $altro->id, 'immobile_id' => $unita[2]->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $piano = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano', 'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: true, notaScoperti: 'mista', accettaDestinatari: true, notaDestinatari: 'mista', esercizio: $esercizio);

    $quote = RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->where('immobile_id', $unita[1]->id)
        ->selectRaw('anagrafica_id, SUM(importo) as totale')->groupBy('anagrafica_id')->pluck('totale', 'anagrafica_id');
    $perChiave = [];
    foreach ($persone as $chiave => $id) {
        $importo = (int) ($quote[$id] ?? 0);
        if ($importo !== 0) {
            $perChiave[$chiave] = $importo;
        }
    }
    ksort($perChiave);

    $GLOBALS['umRuoliDettaglio'] = DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('immobile_id', $unita[1]->id)
        ->get(['anagrafica_id', 'ruolo_risolto'])
        ->mapWithKeys(fn ($r) => [array_search((int) $r->anagrafica_id, $persone, true) => $r->ruolo_risolto])->sortKeys()->all();

    return $perChiave;
}

/**
 * Un pregresso da € 1.000,00 intestato all'unità mista (anagrafica nulla), nella gestione del tipo dato. Stesso impianto
 * di `scenarioSolidale()` (SaldoSolidaleRuoloTest), ricostruito qui perché quella funzione sta in un file di test che
 * eseguito da solo questo file non carica.
 */
function umSaldoUnitaMista(string $tipoGestione, float $quotaNuda = 50, ?array $righe = null): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Mista ' . Str::random(6), 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione mista',
        'data_inizio' => '2026-01-01', 'tipo' => $tipoGestione, 'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);
    $immobile = Immobile::forceCreate([
        'condominio_id' => $condominio->id, 'nome' => 'Int mista', 'descrizione' => 'Appartamento', 'interno' => '1',
    ]);
    // `$righe`: [chiave, ruolo, quota, dal?, al?]; la stessa chiave due volte è la stessa persona su due righe.
    $righe ??= [['B', 'proprietario', 50], ['U', 'usufruttuario', 50], ['E', 'nuda_proprietario', $quotaNuda]];
    $persone = [];
    foreach ($righe as $r) {
        [$chiave, $ruolo, $quota] = $r;
        $persone[$chiave] ??= Anagrafica::factory()->create();
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $persone[$chiave]->id, 'immobile_id' => $immobile->id,
            'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => $r[3] ?? '2026-01-01', 'data_fine' => $r[4] ?? null,
        ]);
    }
    $saldo = Saldo::forceCreate([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id, 'immobile_id' => $immobile->id,
        'anagrafica_id' => null, 'saldo_iniziale' => 100000,
        'descrizione' => 'Pregresso dell\'unità mista', 'is_applicato' => false,
    ]);
    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'Piano mista', 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => $tipoGestione === 'straordinaria' ? 'straordinario' : 'ordinario',
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'immobile', 'saldo', 'piano', 'persone');
}

$mista = ['B' => ['proprietario', 50], 'U' => ['usufruttuario', 50], 'E' => ['nuda_proprietario', 50]];

it('Coda 170 — l\'unità mista si divide sulle quote di ciascuno: la proprietaria piena paga la sua metà, l\'altra metà va al nudo proprietario o all\'usufruttuario secondo la voce', function (array $ripartizioni, array $attesi) use ($mista) {
    expect(umQuoteUnitaMista($ripartizioni, $mista))->toBe($attesi);
})->with([
    // Unità da € 500,00. Fino alla beta.40: Bice € 500,00, Elsa niente.
    'voce sul «Proprietario»: capitale, Bice ed Elsa' => [['proprietario' => 100], ['B' => 25000, 'E' => 25000]],
    // Fino alla beta.40: Ugo € 500,00, Bice niente.
    'voce sull\'«Usufruttuario»: godimento, Bice e Ugo' => [['usufruttuario' => 100], ['B' => 25000, 'U' => 25000]],
    'voce sull\'«Inquilino» senza inquilino: la cascata arriva all\'usufruttuario, e Bice paga la sua metà' => [['inquilino' => 100], ['B' => 25000, 'U' => 25000]],
    'voce divisa fra «Proprietario» e «Inquilino»' => [['proprietario' => 50, 'inquilino' => 50], ['B' => 25000, 'E' => 12500, 'U' => 12500]],
]);

it('Coda 170 — il dettaglio del riparto scrive per ciascuno il suo ruolo, non quello cercato: le stampe distinguono il proprietario pieno dal nudo proprietario', function () use ($mista) {
    umQuoteUnitaMista(['proprietario' => 100], $mista);

    expect($GLOBALS['umRuoliDettaglio'])->toBe(['B' => 'proprietario', 'E' => 'nuda_proprietario']);
});

it('Coda 170 — con quote che non fanno 100 la mista non si indovina: resta la regola della Coda 58, chi è registrato sul ruolo paga la quota dell\'unità', function (array $intestatari, array $ripartizioni, array $attesi) {
    expect(umQuoteUnitaMista($ripartizioni, $intestatari))->toBe($attesi);
})->with([
    'nudo proprietario al 30: capitale a 80' => [
        ['B' => ['proprietario', 50], 'U' => ['usufruttuario', 50], 'E' => ['nuda_proprietario', 30]],
        ['proprietario' => 100], ['B' => 50000],
    ],
    'tutti al 100: capitale a 200' => [
        ['B' => ['proprietario', 100], 'U' => ['usufruttuario', 100], 'E' => ['nuda_proprietario', 100]],
        ['proprietario' => 100], ['B' => 50000],
    ],
    // Sullo stesso dato il godimento fa 100 (Bice 50 + Ugo 50): lì la mista si divide.
    'nudo proprietario al 30, voce sull\'«Usufruttuario»: il godimento fa 100' => [
        ['B' => ['proprietario', 50], 'U' => ['usufruttuario', 50], 'E' => ['nuda_proprietario', 30]],
        ['usufruttuario' => 100], ['B' => 25000, 'U' => 25000],
    ],
]);

it('Coda 170 — un\'unità che non è mista resta identica: proprietario unico, usufrutto pieno, proprietario al 50 da solo (Coda 58)', function (array $intestatari, array $ripartizioni, array $attesi) {
    expect(umQuoteUnitaMista($ripartizioni, $intestatari))->toBe($attesi);
})->with([
    'usufrutto pieno, voce sul «Proprietario»' => [['U' => ['usufruttuario', 100], 'E' => ['nuda_proprietario', 100]], ['proprietario' => 100], ['E' => 50000]],
    'usufrutto pieno, voce sull\'«Usufruttuario»' => [['U' => ['usufruttuario', 100], 'E' => ['nuda_proprietario', 100]], ['usufruttuario' => 100], ['U' => 50000]],
    'un solo proprietario al 50' => [['B' => ['proprietario', 50]], ['proprietario' => 100], ['B' => 50000]],
    'due proprietari pieni' => [['B' => ['proprietario', 50], 'C' => ['proprietario', 50]], ['usufruttuario' => 100], ['B' => 25000, 'C' => 25000]],
]);

it('Coda 170 (31.2) — i giorni scoperti: l\'inquilino esce il 30/06, e dal 1/07 la sua parte va a usufruttuario e proprietaria piena per le loro quote, non al solo usufruttuario', function () use ($mista) {
    $quote = umQuoteUnitaMista(['inquilino' => 100], $mista + ['I' => ['inquilino', 100, '2026-06-30']]);

    // 181 giorni su 365 all'inquilino: € 500,00 × 181 / 365 = € 247,9452; a Ugo e a Bice € 126,0274 ciascuno per i
    // 184 giorni dal 1/07. Resti maggiori: i due centesimi vanno ai due 0,74 → 247,94 / 126,03 / 126,03.
    // Fino alla beta.40: dal 1/07 tutto a Ugo, e Bice niente.
    expect($quote)->toBe(['B' => 12603, 'I' => 24794, 'U' => 12603]);
});

it('Coda 170 (31.2) — i saldi pregressi dell\'unità: l\'ordinaria a usufruttuario e proprietaria piena, la straordinaria a nudo proprietario e proprietaria piena, per le loro quote', function (string $tipo, array $attesi) {
    $s = umSaldoUnitaMista($tipo);
    $distribuzione = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    $perChiave = [];
    foreach ($s->persone as $chiave => $persona) {
        $importo = $distribuzione[$persona->id][$s->immobile->id]['importo'] ?? 0;
        if ($importo !== 0) {
            $perChiave[$chiave] = $importo;
        }
    }
    ksort($perChiave);

    expect($perChiave)->toBe($attesi);

    // Nello storico del saldo resta il ruolo di ciascuno, non solo il primo della catena.
    $ruoli = collect($s->persone)->filter(fn ($p) => isset($distribuzione[$p->id]))
        ->map(fn ($p) => $distribuzione[$p->id][$s->immobile->id]['meta_storico'][0]['ruolo_risolto'])->sortKeys()->all();
    expect($ruoli)->toBe($tipo === 'ordinaria' ? ['B' => 'proprietario', 'U' => 'usufruttuario'] : ['B' => 'proprietario', 'E' => 'nuda_proprietario']);

    // L'anteprima della creazione del piano dice la stessa cosa (decisione 31.3: la proposta si vede, con i nomi).
    $anteprima = app(GenerateSaldiAction::class)->anteprimaSolidale($s->saldo, $s->gestione);
    $mostrato = collect($anteprima['quote'])->pluck('importo', 'anagrafica_id')->all();
    $atteso = collect($attesi)->mapWithKeys(fn ($v, $k) => [$s->persone[$k]->id => $v])->all();
    ksort($mostrato);
    ksort($atteso);
    expect($mostrato)->toBe($atteso)
        ->and($anteprima['ruolo_label'])->toBe($tipo === 'ordinaria' ? 'Usufruttuario e proprietario' : 'Nudo proprietario e proprietario');
})->with([
    'gestione ordinaria' => ['ordinaria', ['B' => 50000, 'U' => 50000]],
    'gestione straordinaria' => ['straordinaria', ['B' => 50000, 'E' => 50000]],
]);

it('Coda 170 (31.2) — la spesa di una sola unità, su un\'unità mista, la pagano proprietaria piena e nudo proprietario per le loro quote', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobile1] = setupContabile();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tab->id, 'immobile_id' => $immobile1, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobile1, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01']);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $capitolo->id, 'tabella_id' => $tab->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $mistaUnita = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '9', 'nome' => 'App 9', 'descrizione' => 'Unità mista']);
    $persone = [];
    foreach (['B' => ['proprietario', 50], 'U' => ['usufruttuario', 50], 'E' => ['nuda_proprietario', 50]] as $chiave => [$ruolo, $quota]) {
        $persone[$chiave] = Anagrafica::factory()->create()->id;
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chiave], 'immobile_id' => $mistaUnita->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => '2019-01-01']);
    }
    $fattura = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-MISTA', 'data_documento' => now()->format('Y-m-d'), 'data_scadenza' => now()->addDays(30)->format('Y-m-d'), 'is_pregresso' => false, 'importo_imponibile' => 0, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 0, 'netto_a_pagare' => 0, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert([
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Facciata', 'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $mistaUnita->id, 'descrizione' => 'Balcone interno 9', 'aliquota_iva' => 0, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Straordinario mista', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 1]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 110000]);

    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'mista');

    $perPersona = RataQuote::whereIn('rata_id', $piano->fresh()->rate()->pluck('id'))->where('immobile_id', $mistaUnita->id)
        ->selectRaw('anagrafica_id, SUM(importo) as totale')->groupBy('anagrafica_id')->pluck('totale', 'anagrafica_id')
        ->map(fn ($v) => (int) $v)->all();
    ksort($perPersona);
    $atteso = [$persone['B'] => 5000, $persone['E'] => 5000];
    ksort($atteso);

    // Fino alla beta.40: Bice € 100,00, il primo ruolo trovato fra proprietario, nudo proprietario e usufruttuario.
    expect($perPersona)->toBe($atteso);
});

it('Coda 170 (31.2) — un pregresso su un\'unità con quote che non fanno 100 resta com\'era: lo paga il primo ruolo della catena', function () {
    $s = umSaldoUnitaMista('straordinaria', 30);
    $distribuzione = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    // Capitale a 80 (Bice 50 + Elsa 30): non si indovina, e la straordinaria resta tutta al nudo proprietario come prima.
    expect($distribuzione[$s->persone['E']->id][$s->immobile->id]['importo'] ?? 0)->toBe(100000)
        ->and($distribuzione)->not->toHaveKey($s->persone['B']->id);
});

it('Coda 170 — la metà piena cambia mano a metà anno: Carla fino al 30/06, Bice dal 1/07; l\'altra metà resta all\'usufruttuario o al nudo proprietario, e i giorni contano anche sul ruolo gemello', function (array $ripartizioni, string $altro) {
    $quote = umQuoteUnitaMista($ripartizioni, [
        'U' => ['usufruttuario', 50], 'E' => ['nuda_proprietario', 50],
        'C' => ['proprietario', 50, '2026-06-30'], 'B' => ['proprietario', 50, null, '2026-07-01'],
    ]);

    // € 500,00 sull'unità: metà all'altro, l'altra metà a Carla per 181 giorni (€ 123,97) e a Bice per 184 (€ 126,03).
    // Misurando i giorni sul solo ruolo che paga, il cambio sul gemello non si vede e i tre pagano un terzo a testa.
    expect($quote)->toBe(collect(['B' => 12603, 'C' => 12397, $altro => 25000])->sortKeys()->all());
})->with([
    'voce sull\'«Usufruttuario»' => [['usufruttuario' => 100], 'U'],
    'voce sul «Proprietario»' => [['proprietario' => 100], 'E'],
]);

it('Coda 170 (31.2) — i giorni scoperti con un usufrutto che si estingue: l\'inquilino esce il 30/06, l\'usufrutto di Ugo finisce il 30/09 ed Elsa torna proprietaria piena dal 1/10', function () {
    $quote = umQuoteUnitaMista(['inquilino' => 100], [
        'B' => ['proprietario', 50],
        'U' => ['usufruttuario', 50, '2026-09-30'],
        'E' => ['nuda_proprietario', 50, '2026-09-30'],
        'E2' => ['proprietario', 50, null, '2026-10-01', 'E'],
        'I' => ['inquilino', 100, '2026-06-30'],
    ], [['proprietario', 'usufrutto', '2026-10-01']]);

    // Inquilino 181/365 di € 500,00 = € 247,9452. I 184 giorni dal 1/07: Bice metà (€ 126,0274), Ugo metà fino al 30/09
    // (92 giorni, € 63,0137), Elsa metà dal 1/10 da proprietaria (€ 63,0137). Resti maggiori: i due centesimi a Bice
    // (0,74) e all'inquilino (0,52). Misurando la copertura sul solo anello dell'usufruttuario, i giorni dal 1/10 tornano
    // all'anello del proprietario e Bice ed Elsa li pagano due volte.
    expect($quote)->toBe(['B' => 12603, 'E' => 6301, 'I' => 24795, 'U' => 6301]);
});

it('Coda 170 (31.2) — la spesa di una sola unità con il nudo proprietario che cambia il 1/07: i giorni contano anche sul ruolo gemello', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobile1] = setupContabile();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tab->id, 'immobile_id' => $immobile1, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobile1, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01']);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $capitolo->id, 'tabella_id' => $tab->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $mistaUnita = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '9', 'nome' => 'App 9', 'descrizione' => 'Unità mista']);
    $persone = [];
    foreach (['B' => ['proprietario', '2019-01-01', null], 'U' => ['usufruttuario', '2019-01-01', null], 'E' => ['nuda_proprietario', '2019-01-01', '2026-06-30'], 'F' => ['nuda_proprietario', '2026-07-01', null]] as $chiave => [$ruolo, $inizio, $fine]) {
        $persone[$chiave] = Anagrafica::factory()->create()->id;
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chiave], 'immobile_id' => $mistaUnita->id, 'tipologia' => $ruolo, 'quota' => 50, 'attivo' => true, 'data_inizio' => $inizio, 'data_fine' => $fine]);
    }
    $fattura = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-MISTA-2', 'data_documento' => now()->format('Y-m-d'), 'data_scadenza' => now()->addDays(30)->format('Y-m-d'), 'is_pregresso' => false, 'importo_imponibile' => 0, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 0, 'netto_a_pagare' => 0, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert([
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Facciata', 'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $mistaUnita->id, 'descrizione' => 'Balcone interno 9', 'aliquota_iva' => 0, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Straordinario mista 2', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 1]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 110000]);

    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'mista', esercizio: \App\Models\Esercizio::find($esercizio->id));

    $perChiave = [];
    $importi = RataQuote::whereIn('rata_id', $piano->fresh()->rate()->pluck('id'))->where('immobile_id', $mistaUnita->id)
        ->selectRaw('anagrafica_id, SUM(importo) as totale')->groupBy('anagrafica_id')->pluck('totale', 'anagrafica_id');
    foreach ($persone as $chiave => $id) {
        if ((int) ($importi[$id] ?? 0) !== 0) {
            $perChiave[$chiave] = (int) $importi[$id];
        }
    }
    ksort($perChiave);

    // € 100,00: metà a Bice, l'altra metà a Elsa per 181 giorni (€ 24,79) e a Fabio per 184 (€ 25,21).
    expect($perChiave)->toBe(['B' => 5000, 'E' => 2479, 'F' => 2521]);
});

it('UnitaMista — una riga del ruolo a quota zero non rende mista l\'unità: un ruolo le cui quote sono tutte a zero è un ruolo assente (anello 4)', function () {
    $riga = fn (int $id, string $tipologia, float $quota) => (object) ['id' => $id, 'tipologia' => $tipologia, 'quota' => $quota, 'attivo' => true, 'data_inizio' => null, 'data_fine' => null, 'immobile_id' => 1];
    $unita = collect([$riga(1, 'proprietario', 0), $riga(2, 'nuda_proprietario', 100), $riga(3, 'usufruttuario', 100)]);

    expect((new \App\Services\Riparto\UnitaMista(new \App\Services\Riparto\RisolutoreTitolari()))
        ->righeGemelle($unita, collect([$riga(1, 'proprietario', 0)]), 'proprietario', null))->toBeEmpty();
});

// --- Fase 1-bis della beta.41: i rilievi D1, D2 e D3 ------------------------------------------------------------------

it('rilievo D1 — Ugo e Bice al 50 %, Ugo costituisce l\'usufrutto della sua metà il 1/05: nell\'anno dell\'atto l\'unità è mista e Bice paga la sua metà, non una parte di quella di Ugo', function (array $ripartizioni, array $attesi) {
    // L'unità 1 vale € 500,00. La riga di nuda proprietà di Ugo è entrata con la costituzione (la tripla di D7): prima del
    // rilievo valeva «da sempre», l'insieme del 1/1–30/4 faceva 150 e l'unità non risultava mista (Bice € 376,29).
    $intestatari = [
        'B' => ['proprietario', 50],
        'U' => ['proprietario', 50, '2026-04-30'],
        'Un' => ['nuda_proprietario', 50, null, '2026-05-01', 'U'],
        'E' => ['usufruttuario', 50, null, '2026-05-01'],
    ];

    expect(umQuoteUnitaMista($ripartizioni, $intestatari, [['usufruttuario', 'usufrutto', '2026-05-01']]))->toBe($attesi);
})->with([
    // Bice 25000; la metà di Ugo è sua tutto l'anno, da proprietario fino al 30/4 e da nudo proprietario dopo.
    'voce sul «Proprietario»' => [['proprietario' => 100], ['B' => 25000, 'U' => 25000]],
    // La metà di Ugo: 120 giorni a lui (25000 × 120/365 = 8219,18), 245 a Elsa usufruttuaria (16780,82).
    'voce sull\'«Usufruttuario»' => [['usufruttuario' => 100], ['B' => 25000, 'E' => 16781, 'U' => 8219]],
]);

it('rilievo D3 — tre terzi scritti 33,33 fanno l\'unità intera: l\'unità mista si riconosce, e ciascuno paga un terzo', function (array $intestatari, array $ripartizioni, array $attesi) {
    $quote = umQuoteUnitaMista($ripartizioni, $intestatari);

    expect(array_keys($quote))->toBe(array_keys($attesi))->and(array_sum($quote))->toBe(50000);
    foreach ($attesi as $chiave => $importo) {
        // I resti maggiori danno l'ultimo centesimo a uno dei tre: 16667, 16667, 16666.
        expect(abs($quote[$chiave] - $importo))->toBeLessThanOrEqual(1);
    }
})->with([
    'tre terzi, voce sul «Proprietario»' => [
        ['B' => ['proprietario', 33.33], 'C' => ['proprietario', 33.33], 'U' => ['usufruttuario', 33.33], 'E' => ['nuda_proprietario', 33.33]],
        ['proprietario' => 100], ['B' => 16667, 'C' => 16667, 'E' => 16667],
    ],
    'tre terzi, voce sull\'«Usufruttuario»' => [
        ['B' => ['proprietario', 33.33], 'C' => ['proprietario', 33.33], 'U' => ['usufruttuario', 33.33], 'E' => ['nuda_proprietario', 33.33]],
        ['usufruttuario' => 100], ['B' => 16667, 'C' => 16667, 'U' => 16667],
    ],
    'due terzi pieni (66,66) e un terzo in usufrutto' => [
        ['B' => ['proprietario', 66.66], 'U' => ['usufruttuario', 33.33], 'E' => ['nuda_proprietario', 33.33]],
        ['proprietario' => 100], ['B' => 33333, 'E' => 16667],
    ],
]);

it('rilievo D3 — il limite della tolleranza: un centesimo di punto in più o in meno è l\'unità intera, due no', function (float $quotaB, bool $mista) {
    $quote = umQuoteUnitaMista(['proprietario' => 100], ['B' => ['proprietario', $quotaB], 'U' => ['usufruttuario', 50], 'E' => ['nuda_proprietario', 50]]);

    // Mista: Elsa paga la sua metà. Non mista (Coda 58): la voce sul «Proprietario» resta a Bice, sola sul ruolo.
    expect(isset($quote['E']))->toBe($mista);
})->with([
    '49,99' => [49.99, true],
    '50,01' => [50.01, true],
    '49,98' => [49.98, false],
    '50,02' => [50.02, false],
]);

it('rilievo D2 — saldi pregressi: la stessa persona sul ruolo e sul gemello paga il pregresso una volta, non due', function (string $tipo, array $righe, array $attesi) {
    $s = umSaldoUnitaMista($tipo, righe: $righe);
    $distribuzione = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    $perChiave = [];
    foreach ($s->persone as $chiave => $persona) {
        if (isset($distribuzione[$persona->id])) {
            $perChiave[$chiave] = $distribuzione[$persona->id][$s->immobile->id]['importo'];
            // Una voce di storico per persona: `VerificaSaldiSolidaliCommand` somma le voci.
            expect($distribuzione[$persona->id][$s->immobile->id]['meta_storico'])->toHaveCount(1);
        }
    }
    ksort($perChiave);
    expect($perChiave)->toBe($attesi);

    // L'anteprima della creazione del piano: una riga per persona, con le quote sommate.
    $anteprima = app(GenerateSaldiAction::class)->anteprimaSolidale($s->saldo, $s->gestione);
    expect(collect($anteprima['quote'])->pluck('importo', 'anagrafica_id')->sum())->toBe(100000)
        ->and($anteprima['quote'])->toHaveCount(count($attesi));
})->with([
    // Elsa proprietaria piena di una metà e nuda proprietaria dell'altra, Ugo usufruttuario: la straordinaria è tutta sua.
    'straordinaria, Elsa piena e nuda' => ['straordinaria', [['E', 'proprietario', 50], ['E', 'nuda_proprietario', 50], ['U', 'usufruttuario', 50]], ['E' => 100000]],
    // Ugo usufruttuario di una metà e proprietario pieno dell'altra: l'ordinaria è tutta sua.
    'ordinaria, Ugo usufruttuario e pieno' => ['ordinaria', [['U', 'usufruttuario', 50], ['U', 'proprietario', 50], ['E', 'nuda_proprietario', 50]], ['U' => 100000]],
    // Quote diverse sulla stessa persona: prima prendeva l'ultima (70) e il 100 % scritto due volte.
    'straordinaria, quote 30 e 70' => ['straordinaria', [['E', 'proprietario', 30], ['E', 'nuda_proprietario', 70], ['U', 'usufruttuario', 70]], ['E' => 100000]],
]);

it('rilievo D2 — già prima della beta: chi vende e ricompra ha due righe sullo stesso ruolo, e il pregresso non si crea dal nulla', function () {
    // Ugo vende a Elsa il 1/05 ed Elsa gli rivende il 1/09; i pregressi si calcolano senza periodo (Coda 174): le tre righe
    // contano tutte. Prima: Ugo € 1.000,00 due volte ed Elsa € 500,00, € 2.000,00 su un pregresso da € 1.000,00. Ora le
    // quote si sommano per persona (Ugo 200, Elsa 100): la proporzione della Coda 174 resta, l'eccedenza sparisce.
    $s = umSaldoUnitaMista('ordinaria', righe: [
        ['U', 'proprietario', 100, '2019-01-01', '2026-04-30'], ['E', 'proprietario', 100, '2026-05-01', '2026-08-31'], ['U', 'proprietario', 100, '2026-09-01'],
    ]);
    $distribuzione = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    expect($distribuzione[$s->persone['U']->id][$s->immobile->id]['importo'])->toBe(66667)
        ->and($distribuzione[$s->persone['E']->id][$s->immobile->id]['importo'])->toBe(33333);
});

<?php

/**
 * B2 (1.11.0-beta.31), S6 — il conguaglio del passaggio legge i tratti per capitolo (decisione 20).
 *
 * Fino a S5 la quota pura di chi esce era divisa per giorni tutta sulla base (gestione ∩ esercizio); con
 * le voci a competenza propria — il riscaldamento sulla sua stagione — motore e conguaglio divergevano.
 * Da S6 la quota si scompone per conto dalle `righe_riparto` congelate e ogni conto si divide sulla **sua**
 * competenza: i tratti della pivot del conto, poi della radice, altrimenti la base.
 *
 * Numeri: riscaldamento € 1.000,00 sui tratti 01/01–15/04 (105 gg) + 15/10–31/12 (78 gg); pulizie € 500,00
 * sull'anno (365 gg); il rogito è del 1° luglio. Chi entra prende del riscaldamento solo il secondo
 * tratto (78/183 → € 426,23) e delle pulizie 184/365 (→ € 252,05): € 678,28 in tutto. Sulla base intera
 * sarebbero stati 1.500 × 184/365 = € 756,16 — la differenza è la stagione.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Services\Subentro\ConguaglioPassaggio;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/GestionaleTestHelpers.php';

function cptScenario(bool $conTratti, int $numeroRate = 1): array
{
    $c = Condominio::factory()->create();
    static $seq = 0;
    $e = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio', 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $g = Gestione::create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $riscaldamento = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Riscaldamento', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 100000]);
    $pulizie = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 50000]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "CPT-{$seq}", 'nome' => 'Unità 1', 'interno' => '1']);
    $seq++;
    $uscente = Anagrafica::forceCreate(['nome' => 'Venditore Ugo', 'email' => "cpt-u{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'CPTUSCENTE0000'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT)]);
    $entrante = Anagrafica::forceCreate(['nome' => 'Acquirente Elsa', 'email' => "cpt-e{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'CPTENTRANTE000'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT)]);

    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => $numeroRate]);
    $piano->capitoli()->attach([$riscaldamento->id => ['importo' => 100000], $pulizie->id => ['importo' => 50000]]);
    if ($conTratti) {
        $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $riscaldamento->id)->value('id');
        CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-04-15', 'ordine' => 0]);
        CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-10-15', 'al' => '2026-12-31', 'ordine' => 1]);
    }

    // Il dettaglio congelato della generazione: una riga per conto, con gli estremi dell'insieme (dec. 15).
    foreach ([[$riscaldamento, 100000], [$pulizie, 50000]] as [$conto, $importo]) {
        DB::table('righe_riparto')->insert([
            'piano_rate_id' => $piano->id, 'tipo' => 'riparto', 'anagrafica_id' => $uscente->id, 'immobile_id' => $unita->id,
            'conto_id' => $conto->id, 'conto_nome' => $conto->nome, 'conto_radice_id' => $conto->id, 'conto_radice_nome' => $conto->nome,
            'importo' => $importo, 'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Le quote emesse: la quota pura di 150.000 divisa sulle rate.
    $perRata = intdiv(150000, $numeroRate);
    for ($n = 1; $n <= $numeroRate; $n++) {
        $rataId = DB::table('rate')->insertGetId([
            'piano_rate_id' => $piano->id, 'numero_rata' => $n, 'data_scadenza' => "2026-0{$n}-28", 'data_emissione' => '2026-01-10',
            'importo_totale' => $perRata, 'stato' => 'emessa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rate_quote')->insert([
            'rata_id' => $rataId, 'anagrafica_id' => $uscente->id, 'immobile_id' => $unita->id, 'importo' => $perRata, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => "2026-0{$n}-28",
            'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => $perRata, 'saldo_usato' => 0, 'totale_calcolato' => $perRata]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return compact('c', 'e', 'g', 'piano', 'unita', 'uscente', 'entrante', 'riscaldamento', 'pulizie');
}

it('senza tratti la quota si divide tutta sulla base, come in S5', function () {
    $s = cptScenario(false);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));

    // 150.000 × 184/365 = 75.616,44 → 75.616.
    expect($r['totale_entrante'])->toBe(75616)
        ->and($r['quote'][0]['per_capitolo'])->toBeNull()
        ->and($r['quote'][0]['gradino'])->toBe('gestione');
});

it('con il riscaldamento sui suoi tratti la divisione è voce per voce: chi entra paga solo il secondo tratto della stagione', function () {
    $s = cptScenario(true);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));

    expect($r['totale_entrante'])->toBe(42623 + 25205)
        ->and($r['coppie'])->toHaveCount(1)->and($r['coppie'][0]['importo'])->toBe(67828)
        ->and($r['quote'][0]['gradino'])->toBe('capitolo')
        ->and($r['quote'][0]['uscente'] + $r['quote'][0]['entrante'])->toBe(150000);

    $conti = collect($r['quote'][0]['per_capitolo'])->keyBy('conto');
    expect($conti['Riscaldamento']['gradino'])->toBe('capitolo')
        ->and($conti['Riscaldamento']['periodo'])->toBe([['dal' => '2026-01-01', 'al' => '2026-04-15'], ['dal' => '2026-10-15', 'al' => '2026-12-31']])
        ->and($conti['Riscaldamento']['giorni_uscente'])->toBe(105)->and($conti['Riscaldamento']['giorni_entrante'])->toBe(78)
        ->and($conti['Riscaldamento']['entrante'])->toBe(42623)
        ->and($conti['Pulizie']['gradino'])->toBe('base')
        ->and($conti['Pulizie']['giorni_entrante'])->toBe(184)->and($conti['Pulizie']['entrante'])->toBe(25205);

    $frasi = implode("\n", $r['frasi']);
    expect($frasi)->toContain('voce per voce')->toContain('Riscaldamento: € 1.000,00, competenza 1 gennaio 2026–15 aprile 2026 + 15 ottobre 2026–31 dicembre 2026 — 105 giorni a Venditore Ugo, 78 a Acquirente Elsa → € 426,23 a chi entra.')
        ->toContain('Pulizie: € 500,00');
});

it('con più rate la parte di chi entra si distribuisce sulle quote in proporzione alla quota pura, al centesimo', function () {
    $s = cptScenario(true, 3);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));

    // 67.828 su tre quote da 50.000: 22.609 + 22.609 + 22.610 (resti maggiori).
    expect(array_sum(array_column($r['quote'], 'entrante')))->toBe(67828)
        ->and(collect($r['quote'])->pluck('entrante')->sort()->values()->all())->toBe([22609, 22609, 22610])
        ->and($r['totale_entrante'])->toBe(67828);
});

it('un rogito dopo la fine della stagione lascia il riscaldamento tutto a chi esce, e la frase lo dice', function () {
    $s = cptScenario(true);
    // Tratti fino al 31/12: il 20 dicembre cade nel secondo tratto, restano 12 giorni a chi entra (20–31/12).
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-12-20'));
    $conti = collect($r['quote'][0]['per_capitolo'])->keyBy('conto');
    expect($conti['Riscaldamento']['giorni_entrante'])->toBe(12)->and($conti['Pulizie']['giorni_entrante'])->toBe(12);

    // Un piano solo estivo per confronto: decorrenza dentro il buco fra i due tratti → il primo tratto è tutto maturato.
    $s2 = cptScenario(true);
    $r2 = (new ConguaglioPassaggio())->calcola($s2['uscente'], $s2['entrante'], [$s2['unita']->id], CarbonImmutable::parse('2026-04-16'));
    $conti2 = collect($r2['quote'][0]['per_capitolo'])->keyBy('conto');
    // 16/04 è nel buco: del riscaldamento restano a chi entra solo i 78 giorni del secondo tratto.
    expect($conti2['Riscaldamento']['giorni_entrante'])->toBe(78)->and($conti2['Riscaldamento']['giorni_uscente'])->toBe(105);
});

it('verifica S6, R7 — il netting del già versato è una riga negativa dello stesso conto: un riscaldamento interamente già versato pesa zero, e le righe sommano alla quota pura emessa', function () {
    // Come cptScenario(true), ma la quota pura emessa è 50.000 (il riscaldamento è già versato per intero:
    // riga netting −100.000) e la rata emessa vale 50.000.
    $s = cptScenario(true);
    DB::table('righe_riparto')->insert([
        'piano_rate_id' => $s['piano']->id, 'tipo' => 'netting', 'anagrafica_id' => $s['uscente']->id, 'immobile_id' => $s['unita']->id,
        'conto_id' => $s['riscaldamento']->id, 'conto_nome' => 'Riscaldamento', 'conto_radice_id' => $s['riscaldamento']->id, 'conto_radice_nome' => 'Riscaldamento',
        'importo' => -100000, 'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update([
        'importo' => 50000, 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 50000, 'saldo_usato' => 0, 'totale_calcolato' => 50000]]),
    ]);

    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));

    // Prima della correzione il riscaldamento pesava ancora 100.000 lordi: bersaglio 50.000 × 67.828/150.000 = 22.609.
    // Ora il riscaldamento vale 0 e le pulizie 50.000 sulla base: 50.000 × 184/365 = 25.205.
    $conti = collect($r['quote'][0]['per_capitolo'])->keyBy('conto');
    expect($r['totale_entrante'])->toBe(25205)
        ->and($conti['Riscaldamento']['importo'])->toBe(0)->and($conti['Riscaldamento']['entrante'])->toBe(0)
        ->and((int) collect($r['quote'][0]['per_capitolo'])->sum('importo'))->toBe(50000)
        ->and(implode("\n", $r['frasi']))->toContain('Riscaldamento: € 0,00')->toContain('Pulizie: € 500,00');
});

it('verifica S6, R8 — con tre rate di cui una sola emessa le righe «voce per voce» parlano delle quote emesse, e sommano al conguaglio proposto', function () {
    $s = cptScenario(true, 3);
    // Restano emesse solo la rata 1: le altre due tornano in bozza.
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', '>', 1)->update(['stato' => 'bozza', 'data_emissione' => null]);

    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));

    // Sull'intero piano: 67.828 su 150.000; sulla sola quota emessa (50.000): round(50.000 × 67.828/150.000) = 22.609.
    expect($r['totale_entrante'])->toBe(22609)->and($r['quote'])->toHaveCount(1);
    $conti = collect($r['quote'][0]['per_capitolo']);
    expect((int) $conti->sum('entrante_emesso'))->toBe(22609)->and((int) $conti->sum('importo_emesso'))->toBe(50000);
    $frasi = implode("\n", $r['frasi']);
    // Le righe dicono i numeri delle quote emesse, non quelli dell'intero piano.
    expect($frasi)->toContain('€ 226,09')->not->toContain('€ 426,23 a chi entra')->not->toContain('€ 1.000,00, competenza');
});

/*
|--------------------------------------------------------------------------
| S8-5 — il netting del già versato: parte «della persona» e parte «dell'unità» (decisione 17, D8)
|--------------------------------------------------------------------------
*/

/** Un solo conto (riscaldamento) con lordo `$lordo`, riga netting −`$netting`, quota emessa al netto; il contributo versato, se c'è, con o senza persona. */
function cptNetting(int $lordo, int $netting, ?int $versatoPersona, bool $conPersona = true, bool $straordinaria = false, ?string $delibera = null): array
{
    $s = cptScenario(false);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->delete();
    $congelato = $straordinaria ? ['competenza_dal' => $delibera, 'competenza_al' => $delibera, 'gradino_competenza' => 'delibera'] : [];
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['riscaldamento']->id)->update(['importo' => $lordo] + $congelato);
    if ($netting > 0) {
        // Il motore congela la natura del netting nella descrizione (decisione 17, S8-bis L1-5): con il contributo
        // intestato alla persona la riga è «della persona» fino al versato, il resto «dell'unità».
        $np = $versatoPersona !== null && $conPersona ? min($netting, $versatoPersona) : 0;
        foreach ([[$np, \App\Services\CalcoloQuoteService::NETTING_DELLA_PERSONA], [$netting - $np, \App\Services\CalcoloQuoteService::NETTING_DELL_UNITA]] as [$importo, $descr]) {
            if ($importo <= 0) continue;
            DB::table('righe_riparto')->insert([
                'piano_rate_id' => $s['piano']->id, 'tipo' => 'netting', 'anagrafica_id' => $s['uscente']->id, 'immobile_id' => $s['unita']->id,
                'conto_id' => $s['riscaldamento']->id, 'conto_nome' => 'Riscaldamento', 'conto_radice_id' => $s['riscaldamento']->id, 'conto_radice_nome' => 'Riscaldamento',
                'importo' => -$importo, 'riga_descrizione' => $descr, 'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now(),
            ] + $congelato);
        }
    }
    if ($versatoPersona !== null) {
        DB::table('contributi_versati')->insert([
            'condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['riscaldamento']->id, 'immobile_id' => $s['unita']->id,
            'anagrafica_id' => $conPersona ? $s['uscente']->id : null, 'importo_cents' => $versatoPersona, 'natura' => 'avanzo', 'origine' => 'migrazione',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $netto = $lordo - $netting;
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update([
        'importo' => $netto, 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => $netto, 'saldo_usato' => 0, 'totale_calcolato' => $netto]]),
    ]);
    if ($straordinaria) {
        $s['g']->update(['tipo' => 'straordinaria']);
        $s['piano']->update(['tipo' => 'straordinario', 'data_delibera_assemblea' => $delibera]);
    }

    return $s;
}

it('S8-5 (i) — netting −30.000 su 100.001 con il versato intestato a chi esce: la parte della persona non entra nella divisione, chi entra deve 67.124 come nel motore (decisione 17)', function () {
    $s = cptNetting(100001, 30000, 30000);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-05-01'));

    // 100.001 × 245/365 = 67.124 (al lordo); prima della correzione 70.001 × 245/365 = 46.987.
    $conto = $r['quote'][0]['per_capitolo'][0];
    expect($r['totale_entrante'])->toBe(67124)
        ->and($conto)->toMatchArray(['importo' => 70001, 'versato_uscente' => 30000, 'entrante' => 67124, 'uscente' => 32877 - 30000])
        ->and(implode("\n", $r['frasi']))->toContain('€ 300,00 già versati da Venditore Ugo restano suoi');
});

it('S8-5 (ii) — lo stesso netting senza persona sul contributo è dell\'unità (D8): abbassa la spesa da dividere, 46.987 come prima', function () {
    $s = cptNetting(100001, 30000, 30000, conPersona: false);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-05-01'));
    expect($r['totale_entrante'])->toBe(46987)->and($r['quote'][0]['per_capitolo'])->toBeNull()->and(implode("\n", $r['frasi']))->not->toContain('già versati');

    // E senza nessun contributo registrato, idem.
    $s2 = cptNetting(100001, 30000, null);
    expect((new ConguaglioPassaggio())->calcola($s2['uscente'], $s2['entrante'], [$s2['unita']->id], CarbonImmutable::parse('2026-05-01'))['totale_entrante'])->toBe(46987);
});

it('S8-5 (iii) — il caso del lettore: 100.000 lordi, 50.000 già versati dalla persona, vendita al 1º luglio → 50.411 a chi entra (100.000 × 184/365)', function () {
    $s = cptNetting(100000, 50000, 50000);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));
    expect($r['totale_entrante'])->toBe(50411)->and($r['coppie'][0]['importo'])->toBe(50411);
});

it('S8-5 (iv) — straordinaria con la delibera dopo il rogito e 40.000 versati dalla persona: la spesa è di chi entra per intero (100.000) e il versato torna a chi esce col conguaglio', function () {
    $s = cptNetting(100000, 40000, 40000, straordinaria: true, delibera: '2026-06-10');
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-05-01'));
    $conto = $r['quote'][0]['per_capitolo'][0];
    expect($r['totale_entrante'])->toBe(100000)
        ->and($conto)->toMatchArray(['importo' => 60000, 'versato_uscente' => 40000, 'entrante' => 100000, 'uscente' => -40000, 'gradino' => 'delibera'])
        ->and(implode("\n", $r['frasi']))->toContain('delibera del 10 giugno 2026 — deliberata dal giorno del passaggio in poi: la spesa è di chi entra per intero → € 1.000,00 a chi entra')
        ->toContain('€ 400,00 già versati da Venditore Ugo restano suoi')->toContain('il versato torna col conguaglio');
});

it('S8-bis L1-3 — conto interamente già versato dalla persona (quota emessa a zero, riga documentaria): il versato di chi esce torna a lui per la parte di chi entra (67.123 su 100.000, 245/365), non sparisce con «finisce prima»', function () {
    $s = cptNetting(100000, 100000, 100000);
    // La quota emessa è la riga documentaria del motore: importo 0, stato credito, tipo «coperta da versamento».
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update(['importo' => 0, 'stato' => 'credito', 'tipo' => 'coperta_da_versamento', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 0, 'saldo_usato' => 0, 'totale_calcolato' => 0]])]);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-05-01'));

    // 100.000 × 245/365 = 67.123 (resti maggiori sulla quota documentaria); prima: bersaglio 0 e «finisce prima del 1 maggio».
    expect($r['totale_entrante'])->toBe(67123)->and($r['coppie'])->toHaveCount(1)->and($r['coppie'][0]['importo'])->toBe(67123)
        ->and($r['quote'][0]['per_capitolo'][0])->toMatchArray(['importo' => 0, 'versato_uscente' => 100000, 'entrante' => 67123, 'uscente' => 32877 - 100000])
        ->and(implode("\n", $r['frasi']))->toContain('€ 1.000,00 già versati da Venditore Ugo restano suoi')->not->toContain('finisce prima');

    // Straordinaria con delibera dopo il rogito: tutto a chi entra, e il versato torna col conguaglio.
    $s2 = cptNetting(100000, 100000, 100000, straordinaria: true, delibera: '2026-06-10');
    DB::table('rate_quote')->where('anagrafica_id', $s2['uscente']->id)->update(['importo' => 0, 'stato' => 'credito', 'tipo' => 'coperta_da_versamento', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 0, 'saldo_usato' => 0, 'totale_calcolato' => 0]])]);
    $r2 = (new ConguaglioPassaggio())->calcola($s2['uscente'], $s2['entrante'], [$s2['unita']->id], CarbonImmutable::parse('2026-05-01'));
    expect($r2['totale_entrante'])->toBe(100000)->and($r2['coppie'])->toHaveCount(1)->and(implode("\n", $r2['frasi']))->toContain('il versato torna col conguaglio');

    // Versato dell'unità (senza persona) al 100 %: la quota pura è zero e non c'è nulla da dividere — la frase lo dice, senza «finisce prima».
    $s3 = cptNetting(100000, 100000, 100000, conPersona: false);
    DB::table('rate_quote')->where('anagrafica_id', $s3['uscente']->id)->update(['importo' => 0, 'stato' => 'credito', 'tipo' => 'coperta_da_versamento', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 0, 'saldo_usato' => 0, 'totale_calcolato' => 0]])]);
    $r3 = (new ConguaglioPassaggio())->calcola($s3['uscente'], $s3['entrante'], [$s3['unita']->id], CarbonImmutable::parse('2026-05-01'));
    expect($r3['totale_entrante'])->toBe(0)->and(implode("\n", $r3['frasi']))->toContain('non c\'è nulla da dividere')->not->toContain('finisce prima');
});

it('S8-bis L1-5 — il netting si legge dal congelato, non dai contributi di oggi: due righe (persona −15.000, unità −10.000) su 50.000 → 26.849 a chi entra, versato della persona 15.000; una riga senza descrizione (piano pre-B2) è dell\'unità', function () {
    $s = cptScenario(false);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['riscaldamento']->id)->update(['importo' => 50000]);
    foreach ([[15000, \App\Services\CalcoloQuoteService::NETTING_DELLA_PERSONA], [10000, \App\Services\CalcoloQuoteService::NETTING_DELL_UNITA]] as [$importo, $descr]) {
        DB::table('righe_riparto')->insert(['piano_rate_id' => $s['piano']->id, 'tipo' => 'netting', 'anagrafica_id' => $s['uscente']->id, 'immobile_id' => $s['unita']->id, 'conto_id' => $s['riscaldamento']->id, 'conto_nome' => 'Riscaldamento', 'conto_radice_id' => $s['riscaldamento']->id, 'conto_radice_nome' => 'Riscaldamento', 'importo' => -$importo, 'riga_descrizione' => $descr, 'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update(['importo' => 25000, 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 25000, 'saldo_usato' => 0, 'totale_calcolato' => 25000]])]);
    // Oggi in contributi_versati c'è anche altro (30.000 alla persona): non deve contare, conta il congelato.
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['riscaldamento']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => $s['uscente']->id, 'importo_cents' => 30000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);

    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-05-01'));
    // (50.000 − 10.000) × 245/365 = 26.849; con la tabella di oggi sarebbe stato min(25.000, 30.000) = 25.000 di persona → 33.562.
    expect($r['totale_entrante'])->toBe(26849)->and($r['quote'][0]['per_capitolo'][0])->toMatchArray(['importo' => 25000, 'versato_uscente' => 15000, 'entrante' => 26849]);

    // Piano pre-B2: una riga netting senza descrizione → tutta dell'unità, anche se oggi il contributo è intestato.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'netting')->update(['riga_descrizione' => null]);
    $r2 = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-05-01'));
    // 25.000 × 245/365 = 16.781.
    expect($r2['totale_entrante'])->toBe(16781)->and($r2['quote'][0]['per_capitolo'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Verifica della strada (b) — scettico «conguaglio» (B2-1 … B2-4)
|--------------------------------------------------------------------------
*/

/** Una sola riga congelata sul riscaldamento con l'importo dato (anche negativo), quota pura emessa uguale. */
function cptUnaRiga(int $importo, bool $conRighe = true): array
{
    $s = cptScenario(false);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->delete();
    if ($conRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update(['importo' => $importo]);
    } else {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update([
        'importo' => $importo, 'stato' => $importo < 0 ? 'credito' : 'da_pagare',
        'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => $importo, 'saldo_usato' => 0, 'totale_calcolato' => $importo]]),
    ]);

    return $s;
}

it('strada (b), B2-2 — quota pura NEGATIVA (nota di credito a riparto, −20.001): il ramo per conto conserva il segno come il ramo base, −10.083 a chi entra (184/365), non zero', function () {
    $conRighe = cptUnaRiga(-20001, true);
    $r = (new ConguaglioPassaggio())->calcola($conRighe['uscente'], $conRighe['entrante'], [$conRighe['unita']->id], CarbonImmutable::parse('2026-07-01'));
    $base = cptUnaRiga(-20001, false);
    $rb = (new ConguaglioPassaggio())->calcola($base['uscente'], $base['entrante'], [$base['unita']->id], CarbonImmutable::parse('2026-07-01'));

    // −20.001 × 184/365 = −10.082,7 → i resti maggiori lavorano sul valore assoluto e conservano il segno.
    expect($rb['totale_entrante'])->toBe(-10083)
        ->and($r['totale_entrante'])->toBe(-10083)
        ->and($r['quote'][0]['entrante'])->toBe(-10083)->and($r['quote'][0]['uscente'])->toBe(-9918)
        ->and($r['coppie'][0]['importo'])->toBe(-10083)
        ->and(implode("\n", $r['frasi']))->toContain('credito € -100,83 a Venditore Ugo, debito € -100,83 a Acquirente Elsa');
});

it('strada (b), B2-3 — segni misti: Manutenzione +50.000 sul tratto 1/1–31/3 (finito prima) e Rimborso −20.000 sulla base: le righe «emesse» ridicono 0 e −10.082 col loro segno, non due metà per teste', function () {
    $s = cptScenario(false);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['riscaldamento']->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-03-31', 'ordine' => 0]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['riscaldamento']->id)->update(['importo' => 50000, 'conto_nome' => 'Manutenzione']);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->update(['importo' => -20000, 'conto_nome' => 'Rimborso']);
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update([
        'importo' => 30000, 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 30000, 'saldo_usato' => 0, 'totale_calcolato' => 30000]]),
    ]);

    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-07-01'));
    $conti = collect($r['quote'][0]['per_capitolo'])->keyBy('conto');

    // Manutenzione: competenza finita prima del 1/7 → 0; Rimborso: −20.000 × 184/365 = −10.082,19 → −10.082.
    expect($conti['Manutenzione'])->toMatchArray(['entrante' => 0, 'entrante_emesso' => 0, 'importo_emesso' => 50000])
        ->and($conti['Rimborso'])->toMatchArray(['entrante' => -10082, 'entrante_emesso' => -10082, 'importo_emesso' => -20000])
        ->and($r['totale_entrante'])->toBe(-10082)
        ->and((int) array_sum(array_column($r['quote'][0]['per_capitolo'], 'importo_emesso')))->toBe(30000);
    $frasi = implode("\n", $r['frasi']);
    expect($frasi)->toContain('Manutenzione: € 500,00, competenza 1 gennaio 2026–31 marzo 2026 — resta a Venditore Ugo')
        ->toContain('Rimborso: € -200,00, competenza 1 gennaio 2026–31 dicembre 2026 — 181 giorni a Venditore Ugo, 184 a Acquirente Elsa → € -100,82 a chi entra')
        ->not->toContain('€ -50,41');
});

it('strada (b), B2-4 — una riga congelata su un tratto che la competenza di oggi non tocca (tratto 1/5–31/12, competenza dichiarata 1/2–31/3) non si divide in silenzio sull\'intera competenza: è una voce non risolta, e la frase dice perché', function () {
    $s = cptNetting(100001, 0, null, true, true, '2026-06-15');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update([
        'competenza_dal' => '2026-02-01', 'competenza_al' => '2026-03-31', 'gradino_competenza' => 'dichiarata',
        'titolarita_dal' => '2026-05-01', 'titolarita_al' => '2026-12-31',
    ]);
    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-03-01'));
    $c = collect($r['quote'][0]['per_capitolo'])->keyBy('conto')['Riscaldamento'];

    // Prima della correzione: 100.001 × 31/59 = 52.543 a chi entra, come se il tratto non esistesse.
    expect($c['non_risolta'])->toBeTrue()->and($c['motivo'])->toBe('tratto_fuori_competenza')
        ->and($c['entrante'])->toBe(0)->and($c['uscente'])->toBe(100001)
        ->and($r['totale_entrante'])->toBe(0)->and($r['coppie'])->toBe([]);
    expect(implode("\n", $r['frasi']))->toContain('Riscaldamento: € 1.000,01, titolarità 1 maggio 2026–31 dicembre 2026 — la riga emessa copre un tratto che la competenza di oggi del piano (1 febbraio 2026–31 marzo 2026) non tocca: resta a Venditore Ugo, nessun conguaglio su questa voce finché il piano non è ricalcolato.');

    // Una riga senza tratto (pre-migrazione 11) resta sulla competenza intera: 52.543 a chi entra, come prima.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update(['titolarita_dal' => null, 'titolarita_al' => null]);
    $r2 = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-03-01'));
    expect($r2['totale_entrante'])->toBe(52543);
});

it('strada (b), B2-1 — quota del predecessore su un piano con il riscaldamento a tratti: le frasi voce per voce nominano chi ha emesso la quota e i giorni di ciascuno (105 ad Anna, 17 a Ugo, 61 a Elsa), e non dicono «resta a Ugo»', function () {
    $s = cptScenario(true);
    $seq = $s['unita']->id;
    $anna = Anagrafica::forceCreate(['nome' => 'Prima Anna', 'email' => "cpt-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'CPTPREDECESS'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
    // Le righe e le quote sono di Anna: il piano è stato generato quando l'unità era sua per l'intero anno.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update(['anagrafica_id' => $anna->id]);
    DB::table('rate_quote')->where('anagrafica_id', $s['uscente']->id)->update(['anagrafica_id' => $anna->id]);
    // Anna → Ugo il 1/7 con un passaggio registrato; Ugo → Elsa il 1/10.
    $rigaAnna = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $anna->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-06-30', 'created_at' => now(), 'updated_at' => now()]);
    $rigaUgo = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['uscente']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-07-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('subentri')->insert([
        'condominio_id' => $s['c']->id, 'immobile_id' => $s['unita']->id, 'anagrafica_uscente_id' => $anna->id, 'anagrafica_entrante_id' => $s['uscente']->id,
        'riga_uscente_id' => $rigaAnna, 'riga_entrante_id' => $rigaUgo, 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-07-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = (new ConguaglioPassaggio())->calcola($s['uscente'], $s['entrante'], [$s['unita']->id], CarbonImmutable::parse('2026-10-01'));
    $conti = collect($r['quote'][0]['per_capitolo'])->keyBy('conto');

    // Riscaldamento (1/1–15/4 + 15/10–31/12 = 183 giorni): a Elsa i 78 del secondo tratto, tutti dopo il 1/10 → 100.000 × 78/183 = 42.623;
    // ad Anna i 105 del primo tratto; a Ugo nulla (fra il 1/7 e il 30/9 il riscaldamento non matura).
    // Pulizie (365 giorni): 181 ad Anna, 92 a Ugo, 92 a Elsa → 50.000 × 92/365 = 12.603 a Elsa; a Ugo ciò che gli era
    // passato il 1/7 (50.000 × 184/365 = 25.205) meno ciò che passa ora: 12.602.
    expect($conti['Riscaldamento'])->toMatchArray(['giorni_predecessore' => 105, 'giorni_uscente' => 0, 'giorni_entrante' => 78, 'entrante' => 42623, 'uscente' => 0])
        ->and($conti['Pulizie'])->toMatchArray(['giorni_predecessore' => 181, 'giorni_uscente' => 92, 'giorni_entrante' => 92, 'entrante' => 12603, 'uscente' => 12602])
        ->and($r['totale_entrante'])->toBe(55226)
        ->and($r['quote'][0]['ereditata_da'])->toBe('Prima Anna');
    $frasi = implode("\n", $r['frasi']);
    expect($frasi)
        ->toContain('la quota ordinaria (€ 1.500,00 su 1 quota) è emessa a Prima Anna: la sua competenza è passata a Venditore Ugo dal 1 luglio 2026 con un passaggio precedente, e quella parte è divisa voce per voce')
        ->toContain('Riscaldamento: € 1.000,00, competenza 1 gennaio 2026–15 aprile 2026 + 15 ottobre 2026–31 dicembre 2026 — emessa a Prima Anna: 105 giorni a Prima Anna, 78 a Acquirente Elsa → € 426,23 a chi entra.')
        ->toContain('Pulizie: € 500,00, competenza 1 gennaio 2026–31 dicembre 2026 — emessa a Prima Anna: 181 giorni a Prima Anna, 92 a Venditore Ugo, 92 a Acquirente Elsa → € 126,03 a chi entra.')
        ->not->toContain('resta a Venditore Ugo')
        ->not->toContain('giorni a Venditore Ugo, 78');
});

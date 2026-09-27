<?php

/**
 * B2, S4 — il tempo entra nei pesi del motore (1.11.0-beta.31).
 *
 * Gli invarianti del progetto `docs/subentro_e_competenza_temporale.md` §5 che S4 doveva rendere veri:
 * 1 (cancello: senza `data_fine` identico alla beta.30), 2 (competenze a nullo), 9 (scenario 1, la
 * vendita con delibera prima del rogito), 10 (scenario 2, il gas a tratti e l'inquilino di giugno),
 * 13 (metà periodo, centesimo), 18 (totale per conto invariante), 22 (l'inquilino mai destinatario),
 * 24 (pro rata nell'ordinario anche con delibera dopo il rogito), il cancello (2) e le decisioni
 * 12, 15, 16 e 17. Ogni importo atteso è calcolato a mano nel commento, non letto dal codice.
 *
 * Fixture propria (`mt*`): gli helper degli altri file sono globali nel processo di Pest e non si
 * ridefiniscono; `condominioScrittura()` e gli altri non agganciano la gestione all'esercizio, che è
 * la condizione per cui il motore riceve una competenza. Qui l'esercizio è **sempre** agganciato.
 *
 * ⚠️ Chi prende il centesimo dei resti pari: `MoneyHelper::distribuisciPesiNormalizzati()` ordina con
 * `arsort` stabile, quindi a pari resto vince la **prima chiave inserita**, cioè l'ordine di
 * `$immobile->anagrafiche` — senza ORDER BY, l'ordine di inserimento (invariante 6 del progetto). I
 * test dell'inv. 13 e 18 lo dichiarano e lo pinnano: se un giorno la relazione avrà un ordine, questi
 * test lo diranno.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\GenerateRateQuotesAction;
use App\Exceptions\Gestionale\DestinatariCambiatiException;
use App\Exceptions\Gestionale\RichiedeDeliberaException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\DettaglioRiparto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

/**
 * Un condominio con una gestione agganciata all'esercizio, un conto su una tabella millesimale e le
 * unità/titolari passati. Restituisce tutto ciò che i test toccano.
 *
 * @param array<int, array{unita:int, ruolo:string, quota:float|int, dal?:?string, al?:?string, nome?:string}> $titolari
 * @param array<int, float> $millesimi unità => millesimi (chiavi 1..n)
 */
function mtScenario(array $titolari, array $millesimi = [1 => 1000.0], array $op = []): array
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio', 'stato' => 'aperto',
        'data_inizio' => $op['esercizio_dal'] ?? '2026-01-01', 'data_fine' => $op['esercizio_al'] ?? '2026-12-31',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione', 'tipo' => $op['gestione_tipo'] ?? 'ordinaria',
        'descrizione' => 'B2 S4', 'data_inizio' => $op['gestione_dal'] ?? null, 'data_fine' => $op['gestione_al'] ?? null,
    ]);
    legaAEsercizio($esercizio, $gestione->id);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC']);
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'nome' => $op['conto_nome'] ?? 'Lavori', 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => $op['importo'] ?? 100001,
    ]);
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $ctm, 'soggetto' => $op['soggetto'] ?? 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $unita = [];
    foreach ($millesimi as $n => $valore) {
        $unita[$n] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'codice_immobile' => "MT{$seq}-{$n}",
            'nome' => "Unità {$n}", 'interno' => (string) $n,
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $unita[$n]->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $persone = [];
    $righe = [];
    foreach ($titolari as $chiave => $t) {
        $seq++;
        $nome = $t['nome'] ?? ucfirst((string) $chiave);
        $persone[$chiave] = Anagrafica::forceCreate([
            'nome' => $nome, 'email' => "mt{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'MTTEST' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
        ]);
        $righe[$chiave] = DB::table('anagrafica_immobile')->insertGetId([
            'anagrafica_id' => $persone[$chiave]->id, 'immobile_id' => $unita[$t['unita']]->id,
            'tipologia' => $t['ruolo'], 'quota' => $t['quota'], 'attivo' => true,
            'data_inizio' => $t['dal'] ?? null, 'data_fine' => $t['al'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Piano', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'data_delibera_assemblea' => $op['data_delibera'] ?? null,
    ]);

    return compact('condominio', 'esercizio', 'gestione', 'pianoConto', 'conto', 'tabella', 'unita', 'persone', 'righe', 'piano');
}

/** Il motore in memoria, con la competenza che la generazione userebbe. */
function mtCalcola(array $s): array
{
    $motore = new CalcoloQuoteService();
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    $totali = $motore->calcolaPerGestione($s['gestione'], $s['piano']->fresh(), false, $competenza);

    return [$motore, $totali];
}

/** Quanto è stato calcolato per una persona (tutte le sue unità), in centesimi. */
function mtDi(array $totali, Anagrafica $a): ?int
{
    return isset($totali[$a->id]) ? (int) array_sum($totali[$a->id]) : null;
}

function mtSomma(array $totali): int
{
    return (int) array_sum(array_map(fn ($imm) => array_sum($imm), $totali));
}

/** Le quote scritte in `rate_quote` per persona, in centesimi (quota pura, senza saldi). */
function mtQuotePerPersona(PianoRate $piano): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)
        ->selectRaw('rate_quote.anagrafica_id, SUM(rate_quote.importo) as tot')
        ->groupBy('rate_quote.anagrafica_id')->pluck('tot', 'anagrafica_id')->map(fn ($v) => (int) $v)->all();
}

/*
|--------------------------------------------------------------------------
| Inv. 1 e 2 — il cancello: senza date il riparto è quello della beta.30
|--------------------------------------------------------------------------
*/

it('inv. 1 [S4] — con nessuna data_fine il riparto generato con la competenza è identico al centesimo a quello atemporale, su centesimi dispari, e titolarita_alla resta la costante di B1', function () {
    $s = mtScenario([
        'a' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2020-01-01'],
        'b' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2021-06-15'],
        'p' => ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03'],
    ], [1 => 500.0, 2 => 500.0]);

    $atemporale = (new CalcoloQuoteService())->calcolaPerGestione($s['gestione'], $s['piano'], true);
    [$motore, $temporale] = mtCalcola($s);

    expect($temporale)->toBe($atemporale)
        ->and(mtSomma($temporale))->toBe(100001)
        ->and($motore->getRisoluzioneTemporale())->toBe(['temporale' => true, 'destinatari_cambiati' => [], 'competenza_non_risolta' => false]);

    // La generazione vera: nessun cancello, quote uguali ai totali, snapshot con la costante di B1.
    app(GeneratePianoRateAction::class)->execute($s['piano'], esercizio: $s['esercizio']);
    $quote = mtQuotePerPersona($s['piano']);
    expect($quote[$s['persone']['a']->id])->toBe(mtDi($atemporale, $s['persone']['a']))
        ->and($quote[$s['persone']['p']->id])->toBe(mtDi($atemporale, $s['persone']['p']));

    $regole = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->pluck('rate_quote.regole_calcolo');
    foreach ($regole as $json) {
        expect(json_decode($json, true)['parametri']['titolarita_alla'])->toBe(GenerateRateQuotesAction::TITOLARITA_ATEMPORALE);
    }

    // Decisione 15: il calcolo è stato temporale (esercizio agganciato) → ogni riga porta periodo e gradino, nessuna i giorni.
    $righe = RigaRiparto::where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->get();
    expect($righe)->not->toBeEmpty()
        ->and($righe->pluck('gradino_competenza')->unique()->all())->toBe(['esercizio'])
        ->and($righe->pluck('giorni_titolarita')->unique()->all())->toBe([null])
        ->and($righe->first()->competenza_dal->toDateString())->toBe('2026-01-01')
        ->and($righe->first()->competenza_al->toDateString())->toBe('2026-12-31');
});

it('inv. 2 [S4] — senza tratti sul capitolo la competenza scende alla gestione se è chiusa e all\'esercizio se è aperta, e in entrambi i casi il riparto senza date non cambia', function () {
    $titolari = ['p' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03']];

    $aperta = mtScenario($titolari);
    [$mA] = mtCalcola($aperta);
    expect(collect($mA->getRigheDettaglio())->where('tipo', 'riparto')->pluck('gradino_competenza')->unique()->all())->toBe(['esercizio']);

    $chiusa = mtScenario($titolari, [1 => 1000.0], ['gestione_dal' => '2026-03-01', 'gestione_al' => '2026-10-31']);
    [$mC, $totali] = mtCalcola($chiusa);
    $righe = collect($mC->getRigheDettaglio())->where('tipo', 'riparto');
    expect($righe->pluck('gradino_competenza')->unique()->all())->toBe(['gestione'])
        ->and($righe->first()['competenza_dal'])->toBe('2026-03-01')
        ->and($righe->first()['competenza_al'])->toBe('2026-10-31')
        ->and(mtDi($totali, $chiusa['persone']['p']))->toBe(100001);
});

/*
|--------------------------------------------------------------------------
| Inv. 24, 13, 18 — il pro rata per giorni nell'ordinario
|--------------------------------------------------------------------------
*/

it('inv. 24 [S4] — ordinario, nessuna competenza dichiarata, delibera successiva al rogito: venditore e acquirente si dividono € 1.000,01 in proporzione ai giorni (120/245), non a gradino sulla delibera', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ], [1 => 1000.0], ['data_delibera' => '2026-06-15']);

    [$motore, $totali] = mtCalcola($s);

    // 100001 × 120/365 = 32877,04 → 32877; 100001 × 245/365 = 67123,96 → 67124 (il centesimo al resto maggiore).
    expect(mtDi($totali, $s['persone']['venditore']))->toBe(32877)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBe(67124)
        ->and(mtSomma($totali))->toBe(100001);

    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->keyBy('anagrafica_id');
    expect($righe[$s['persone']['venditore']->id]['giorni_titolarita'])->toBe(120)
        ->and($righe[$s['persone']['acquirente']->id]['giorni_titolarita'])->toBe(245)
        ->and($righe->pluck('gradino_competenza')->unique()->all())->toBe(['esercizio']);

    // La controprova: il criterio unico sulla delibera (15/06, dopo il rogito) avrebbe dato tutto all'acquirente.
    expect(mtDi($totali, $s['persone']['acquirente']))->not->toBe(100001);
});

it('inv. 13 [S4] — un subentro esattamente a metà periodo su un importo dispari chiude al centesimo: 50000,5 + 50000,5 → il centesimo va alla prima riga inserita (invariante 6, ordine senza ORDER BY)', function () {
    // Esercizio di 364 giorni: 1° gennaio – 30 dicembre. Venditore fino al 1° luglio compreso = 182 giorni; acquirente dal 2 luglio = 182.
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-07-01'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-07-02'],
    ], [1 => 1000.0], ['esercizio_al' => '2026-12-30']);

    [$motore, $totali] = mtCalcola($s);
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->keyBy('anagrafica_id');

    expect($righe[$s['persone']['venditore']->id]['giorni_titolarita'])->toBe(182)
        ->and($righe[$s['persone']['acquirente']->id]['giorni_titolarita'])->toBe(182)
        ->and(mtDi($totali, $s['persone']['venditore']))->toBe(50001)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBe(50000)
        ->and(mtSomma($totali))->toBe(100001);
});

it('inv. 18 [S4] — il totale ripartito per conto non cambia al variare dei titolari successivi; il centesimo che si sposta fra unità (decisione 7) è pinnato', function () {
    // Caso A, nessuna data: due unità 500/500 → 50000,5 e 50000,5 → il centesimo alla prima (unità 1).
    $a = mtScenario([
        'o1' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03'],
        'p' => ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03'],
    ], [1 => 500.0, 2 => 500.0]);
    [$mA, $tA] = mtCalcola($a);
    expect($mA->getImportiPerConto()[$a['conto']->id])->toBe(100001)
        ->and(mtDi($tA, $a['persone']['o1']))->toBe(50001)
        ->and(mtDi($tA, $a['persone']['p']))->toBe(50000);

    // Caso B: sull'unità 1, o1 fino al 14 marzo (73 giorni) e o2 dal 15 marzo (292): 10000,1 + 40000,4; l'unità 2 50000,5.
    // Il centesimo di resto va al resto maggiore, cioè all'unità 2: 10000 + 40000 + 50001 = 100001.
    $b = mtScenario([
        'o1' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-03-14'],
        'o2' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-03-15'],
        'p' => ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03'],
    ], [1 => 500.0, 2 => 500.0]);
    [$mB, $tB] = mtCalcola($b);
    expect($mB->getImportiPerConto()[$b['conto']->id])->toBe(100001)
        ->and(mtDi($tB, $b['persone']['o1']))->toBe(10000)
        ->and(mtDi($tB, $b['persone']['o2']))->toBe(40000)
        ->and(mtDi($tB, $b['persone']['p']))->toBe(50001)
        ->and(mtSomma($tB))->toBe(100001);
});

/*
|--------------------------------------------------------------------------
| Inv. 9, 22, decisione 12 — lo straordinario: il gradino della delibera
|--------------------------------------------------------------------------
*/

/** Lo scenario 1 di Leonardo: gestione straordinaria, delibera 01/03, rogito 30/04, fattura 01/08. */
function mtScenarioUno(?string $dataDelibera = '2026-03-01', array $competenzaFattura = [null, null], array $titolariExtra = [], array $righeExtra = []): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();
    DB::table('gestioni')->where('id', $gestione->id)->update(['tipo' => 'straordinaria']);
    $gestione->refresh();

    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $capitolo->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    static $seq = 500;
    $persone = [];
    $titolari = [
        'venditore' => ['ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01', 'al' => null],
    ] + $titolariExtra;
    foreach ($titolari as $chiave => $t) {
        $seq++;
        $persone[$chiave] = Anagrafica::forceCreate(['nome' => ucfirst($chiave), 'email' => "mtuno{$seq}@test.it", 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'MTUNO' . str_pad((string) $seq, 11, '0', STR_PAD_LEFT)]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $persone[$chiave]->id, 'immobile_id' => $immobileId, 'tipologia' => $t['ruolo'], 'quota' => $t['quota'],
            'attivo' => true, 'data_inizio' => $t['dal'], 'data_fine' => $t['al'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $fattura = FatturaPassiva::create([
        'condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-S1', 'data_documento' => '2026-08-01', 'data_scadenza' => '2026-09-01',
        'is_pregresso' => false, 'importo_imponibile' => 100001, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 100001,
        'netto_a_pagare' => 100001, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
        'competenza_dal' => $competenzaFattura[0], 'competenza_al' => $competenzaFattura[1],
    ]);
    $righe = array_merge([
        ['conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Lavori straordinari', 'importo' => 100001, 'is_sopravvenienza' => true],
    ], $righeExtra);
    foreach ($righe as $r) {
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fattura->id, 'conto_id' => $r['conto_id'], 'immobile_id' => $r['immobile_id'],
            'descrizione' => $r['descrizione'], 'aliquota_iva' => 0, 'importo_imponibile' => $r['importo'], 'importo_iva' => 0,
            'is_sopravvenienza' => $r['is_sopravvenienza'], 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $importoCollegato = (int) array_sum(array_column($righe, 'importo'));

    $piano = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Rifacimento facciata', 'stato' => 'bozza',
        'tipo' => 'straordinario', 'numero_rate' => 1, 'data_delibera_assemblea' => $dataDelibera,
    ]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => $importoCollegato]);

    return compact('condominio', 'esercizio', 'gestione', 'capitolo', 'immobileId', 'persone', 'fattura', 'piano');
}

it('inv. 9 [S4] — scenario 1 per intero: delibera 01/03, rogito 30/04, fattura 01/08 → tutto al venditore, zero all\'acquirente, e il cancello (2) lo dice prima di scrivere', function () {
    $s = mtScenarioUno();

    // Il cancello (2): l'acquirente è escluso dal periodo (1 giorno, la delibera) → si ferma e nomina chi cambia.
    try {
        app(GeneratePianoRateAction::class)->execute($s['piano'], esercizio: $s['esercizio']);
        $this->fail('Attesa DestinatariCambiatiException');
    } catch (DestinatariCambiatiException $e) {
        $c = $e->getCambiamenti();
        expect($c)->toHaveCount(1)
            ->and($c[0]['motivo'])->toBe('fuori_periodo')
            ->and($c[0]['gradino'])->toBe('delibera')
            ->and($c[0]['periodo'])->toBe([['dal' => '2026-03-01', 'al' => '2026-03-01']])
            ->and($c[0]['anagrafiche_escluse'][0]['anagrafica_nome'])->toBe('Acquirente');
    }
    expect($s['piano']->rate()->count())->toBe(0)->and(RigaRiparto::where('piano_rate_id', $s['piano']->id)->count())->toBe(0);

    // Con la presa d'atto si genera: 100 % al venditore, e nessuna configurazione dà 50/50.
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Rogito del 30/04/2026, verbale n. 3', esercizio: $s['esercizio']);
    $quote = mtQuotePerPersona($s['piano']);
    expect($quote[$s['persone']['venditore']->id])->toBe(100001)
        ->and($quote)->not->toHaveKey($s['persone']['acquirente']->id);

    $righe = RigaRiparto::where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->get();
    expect($righe->pluck('anagrafica_id')->unique()->all())->toBe([$s['persone']['venditore']->id])
        ->and($righe->first()->gradino_competenza)->toBe('delibera')
        ->and($righe->first()->competenza_dal->toDateString())->toBe('2026-03-01')
        ->and($righe->first()->competenza_al->toDateString())->toBe('2026-03-01');

    // Decisione 15: lo snapshot dice come sono stati risolti i titolari, con la nota del cancello.
    $regole = json_decode(DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->value('rate_quote.regole_calcolo'), true);
    expect($regole['parametri']['titolarita_alla'])->toBe([
        'risoluzione' => 'temporale', 'destinatari_cambiati' => true, 'nota_cancello' => 'Rogito del 30/04/2026, verbale n. 3', 'coppie' => 1,
    ]);
});

it('inv. 9-bis [S4] — lo stesso scenario con l\'uscente chiuso (non cancellato) e il piano generato DOPO il rogito dà lo stesso esito: il gradino della delibera non guarda la data di oggi', function () {
    $s = mtScenarioUno();
    $motore = new CalcoloQuoteService();
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano'], $s['esercizio']);
    $totali = $motore->calcolaDaFattureStraordinarie($s['piano'], $competenza);

    expect(mtDi($totali, $s['persone']['venditore']))->toBe(100001)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBeNull()
        ->and($motore->getRisoluzioneTemporale()['destinatari_cambiati'][0]['motivo'])->toBe('fuori_periodo');
});

it('decisione 12 [S4] — piano straordinario senza data della delibera e senza competenza sulla fattura: la generazione si ferma e rimanda al campo, non ripiega sul periodo della gestione', function () {
    $s = mtScenarioUno(null);

    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], esercizio: $s['esercizio']))
        ->toThrow(RichiedeDeliberaException::class, 'Rifacimento facciata');
    expect($s['piano']->rate()->count())->toBe(0);

    // Urgenza: la competenza dichiarata sulla fattura basta (gradino «dichiarata»), anche senza delibera.
    $u = mtScenarioUno(null, ['2026-03-01', '2026-03-01']);
    // …e l'ANTEPRIMA dello stesso piano dice la stessa cosa della generazione: tutto al venditore, gradino
    // «dichiarata», risoluzione temporale — non il 50/50 atemporale (verifica S4, 19/09).
    $anteprima = app(DettaglioRiparto::class)->perPiano($u['piano']->fresh());
    expect($anteprima['fonte']['tipo'])->toBe('anteprima')
        ->and($anteprima['fonte']['risoluzione'])->toBe('temporale')
        ->and($anteprima['fonte']['competenza_non_risolta'])->toBeFalse()
        ->and(collect($anteprima['righe'])->where('tipo', 'riparto')->sum('importo'))->toBe(100001)
        ->and(collect($anteprima['righe'])->where('tipo', 'riparto')->pluck('anagrafica_id')->unique()->all())->toBe([$u['persone']['venditore']->id]);
    app(GeneratePianoRateAction::class)->execute($u['piano'], accettaDestinatari: true, notaDestinatari: 'Urgenza: competenza dichiarata sulla fattura', esercizio: $u['esercizio']);
    expect(mtQuotePerPersona($u['piano'])[$u['persone']['venditore']->id])->toBe(100001)
        ->and(RigaRiparto::where('piano_rate_id', $u['piano']->id)->where('tipo', 'riparto')->value('gradino_competenza'))->toBe('dichiarata');

    // Senza delibera e senza competenza dichiarata l'anteprima non si ferma (è una stampa), ma lo dice.
    $n = mtScenarioUno(null);
    $anteprima = app(DettaglioRiparto::class)->perPiano($n['piano']->fresh());
    expect($anteprima['fonte']['risoluzione'])->toBe('atemporale')
        ->and($anteprima['fonte']['competenza_non_risolta'])->toBeTrue()
        ->and(collect($anteprima['righe'])->where('tipo', 'riparto')->sum('importo'))->toBe(100001);
});

it('decisione 11 [S4] — fa fede la natura della gestione: lo stesso piano «straordinario» su una gestione ordinaria non si ferma e va pro rata (gradino esercizio), dichiarando la divergenza', function () {
    $s = mtScenarioUno(null);
    DB::table('gestioni')->where('id', $s['gestione']->id)->update(['tipo' => 'ordinaria']);

    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    expect($competenza->divergenzaTipoPiano)->toBeTrue()->and($competenza->richiedeDelibera)->toBeFalse();

    $motore = new CalcoloQuoteService();
    $totali = $motore->calcolaDaFattureStraordinarie($s['piano']->fresh(), $competenza);
    // 100001 × 120/365 = 32877,04 → 32877; 245/365 → 67124.
    expect(mtDi($totali, $s['persone']['venditore']))->toBe(32877)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBe(67124)
        ->and(collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->pluck('gradino_competenza')->unique()->all())->toBe(['esercizio']);
});

it('inv. 22 [S4] — l\'inquilino non compare mai fra i destinatari di un addebito diretto, nemmeno con i periodi; e l\'acquirente fuori periodo non paga la riga ad personam', function () {
    $s = mtScenarioUno('2026-03-01', [null, null], [
        'inquilino' => ['ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2024-01-01', 'al' => null],
    ], [
        ['conto_id' => null, 'immobile_id' => null, 'descrizione' => 'segnaposto', 'importo' => 0, 'is_sopravvenienza' => false],
    ]);
    // La riga ad personam sull'unità: senza conto, come la scrive la registrazione della fattura.
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $s['fattura']->id, 'conto_id' => null, 'immobile_id' => $s['immobileId'],
        'descrizione' => 'Riparazione balcone', 'aliquota_iva' => 0, 'importo_imponibile' => 10001, 'importo_iva' => 0,
        'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('piano_rate_fatture')->where('piano_rate_id', $s['piano']->id)->update(['importo_collegato' => 110002]);

    $motore = new CalcoloQuoteService();
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    $totali = $motore->calcolaDaFattureStraordinarie($s['piano']->fresh(), $competenza);

    expect(mtDi($totali, $s['persone']['venditore']))->toBe(110002)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBeNull()
        ->and(mtDi($totali, $s['persone']['inquilino']))->toBeNull();

    $adPersonam = collect($motore->getRigheDettaglio())->where('tipo', 'ad_personam');
    expect($adPersonam)->toHaveCount(1)
        ->and($adPersonam->first()['anagrafica_id'])->toBe($s['persone']['venditore']->id)
        ->and($adPersonam->first()['ruolo_risolto'])->toBe('proprietario')
        ->and($adPersonam->first()['importo'])->toBe(10001)
        ->and($adPersonam->first()['gradino_competenza'])->toBe('delibera');
});

/*
|--------------------------------------------------------------------------
| Inv. 10 — la competenza a tratti del capitolo (decisione 20)
|--------------------------------------------------------------------------
*/

it('inv. 10 [S4] — scenario 2 per intero: gas con competenza 01/10–01/05 sul capitolo, inquilino che entra il 01/06 → tutto al vecchio inquilino, gradino «capitolo»', function () {
    $s = mtScenario([
        'proprietario' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
        'vecchio' => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2025-10-01', 'al' => '2026-05-31'],
        'nuovo' => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2026-06-01'],
    ], [1 => 1000.0], ['esercizio_dal' => '2025-10-01', 'esercizio_al' => '2026-09-30', 'soggetto' => 'inquilino', 'conto_nome' => 'Gas riscaldamento']);

    // Il capitolo nel piano, con il suo tratto di competenza (decisione 20).
    $s['piano']->capitoli()->attach($s['conto']->id, ['importo' => 100001]);
    $pivotId = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['conto']->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivotId, 'dal' => '2025-10-01', 'al' => '2026-05-01', 'ordine' => 1]);

    [$motore, $totali] = mtCalcola($s);

    expect(mtDi($totali, $s['persone']['vecchio']))->toBe(100001)
        ->and(mtDi($totali, $s['persone']['nuovo']))->toBeNull()
        ->and(mtDi($totali, $s['persone']['proprietario']))->toBeNull();

    $riga = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->first();
    expect($riga['gradino_competenza'])->toBe('capitolo')
        ->and($riga['competenza_dal'])->toBe('2025-10-01')
        ->and($riga['competenza_al'])->toBe('2026-05-01')
        ->and($riga['ruolo_risolto'])->toBe('inquilino');

    // Il nuovo inquilino è un destinatario che il calcolo atemporale avrebbe fatto pagare: cancello (2).
    $cambiati = $motore->getRisoluzioneTemporale()['destinatari_cambiati'];
    expect($cambiati)->toHaveCount(1)->and($cambiati[0]['motivo'])->toBe('fuori_periodo');
});

it('inv. 10-bis [S4] — con due tratti (zona E: 01/01–15/04 e 15/10–31/12) il venditore del 30 aprile paga il primo tratto intero e nulla del secondo', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ], [1 => 1000.0], ['conto_nome' => 'Riscaldamento']);
    $s['piano']->capitoli()->attach($s['conto']->id, ['importo' => 100001]);
    $pivotId = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivotId, 'dal' => '2026-01-01', 'al' => '2026-04-15', 'ordine' => 1]);
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivotId, 'dal' => '2026-10-15', 'al' => '2026-12-31', 'ordine' => 2]);

    [$motore, $totali] = mtCalcola($s);

    // 105 giorni al venditore, 78 all'acquirente su 183: 100001 × 105/183 = 57377,62 → 57378; 42623,38 → 42623.
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->keyBy('anagrafica_id');
    expect($righe[$s['persone']['venditore']->id]['giorni_titolarita'])->toBe(105)
        ->and($righe[$s['persone']['acquirente']->id]['giorni_titolarita'])->toBe(78)
        ->and(mtDi($totali, $s['persone']['venditore']))->toBe(57378)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBe(42623)
        ->and(mtSomma($totali))->toBe(100001)
        ->and($righe->pluck('gradino_competenza')->unique()->all())->toBe(['capitolo'])
        // Il congelato porta gli estremi dell'insieme: la legenda dice «due tratti», il dettaglio in S7.
        ->and($righe->first()['competenza_dal'])->toBe('2026-01-01')
        ->and($righe->first()['competenza_al'])->toBe('2026-12-31');
});

/*
|--------------------------------------------------------------------------
| Il cancello (2), le decisioni 15 e 16
|--------------------------------------------------------------------------
*/

it('cancello (2) [S4] — la generazione con un pro rata si ferma senza spunta e nota, si genera con la presa d\'atto, e non si ferma quando nessuno cambia', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ]);

    try {
        app(GeneratePianoRateAction::class)->execute($s['piano'], esercizio: $s['esercizio']);
        $this->fail('Attesa DestinatariCambiatiException');
    } catch (DestinatariCambiatiException $e) {
        $c = $e->getCambiamenti()[0];
        expect($c['motivo'])->toBe('pro_rata_giorni')
            ->and($c['immobile_nome'])->toBe('Unità 1')
            ->and($c['conto_nome'])->toBe('Lavori')
            ->and(collect($c['righe'])->pluck('giorni', 'anagrafica_nome')->all())->toBe(['Venditore' => 120, 'Acquirente' => 245]);
    }
    expect($s['piano']->rate()->count())->toBe(0);

    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Rogito del 30 aprile, letto il pannello', esercizio: $s['esercizio']);
    $quote = mtQuotePerPersona($s['piano']);
    expect($quote[$s['persone']['venditore']->id])->toBe(32877)->and($quote[$s['persone']['acquirente']->id])->toBe(67124);

    // Decisione 15 sulle righe persistite: periodo, gradino e giorni, per riga.
    $righe = RigaRiparto::where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->get()->keyBy('anagrafica_id');
    expect($righe[$s['persone']['venditore']->id]->giorni_titolarita)->toBe(120)
        ->and($righe[$s['persone']['acquirente']->id]->giorni_titolarita)->toBe(245)
        ->and($righe[$s['persone']['venditore']->id]->competenza_dal->toDateString())->toBe('2026-01-01')
        ->and($righe[$s['persone']['venditore']->id]->gradino_competenza)->toBe('esercizio');
});

it('cancello (2) [S4, verifica 19/09] — spesa «inquilino» senza inquilino, cascata sul proprietario: il proprietario uscito PRIMA del periodo sparisce dai destinatari e il cancello lo dice (fuori_periodo), invece di tacere perché il ruolo richiesto era vuoto', function () {
    // Atemporale (beta.30): A e B proprietari attivi → 50001 / 50000. Temporale: A è chiuso il 31/12/2025,
    // fuori dall'esercizio 2026 → B paga 100001. Nessuno «cambia nel periodo» (D8), quindi il pro rata non
    // scatta: l'unico segnale è «A escluso dal periodo», che va registrato sul ruolo RISOLTO dalla cascata.
    $s = mtScenario([
        'a' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-01-01', 'al' => '2025-12-31'],
        'b' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-01-01'],
    ], [1 => 1000.0], ['soggetto' => 'inquilino']);

    [$motore, $totali] = mtCalcola($s);
    expect(mtDi($totali, $s['persone']['b']))->toBe(100001)->and(mtDi($totali, $s['persone']['a']))->toBeNull();

    $cambiamenti = $motore->getRisoluzioneTemporale()['destinatari_cambiati'];
    expect($cambiamenti)->toHaveCount(1)
        ->and($cambiamenti[0]['motivo'])->toBe('fuori_periodo')
        ->and($cambiamenti[0]['tipologia'])->toBe('proprietario')
        ->and($cambiamenti[0]['anagrafiche_escluse'])->toBe([$s['persone']['a']->id]);

    // E la generazione si ferma, come per il pro rata.
    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], esercizio: $s['esercizio']))
        ->toThrow(DestinatariCambiatiException::class);
});

it('cancello (2) [S4, verifica 19/09] — la stessa coppia che cambia compare UNA volta anche se il conto ha due ripartizioni che ripiegano sullo stesso ruolo, e `coppie` conta le coppie', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ]);
    // Seconda ripartizione sullo stesso conto e tabella: 50 % proprietario + 50 % inquilino (nessun inquilino → cascata).
    $ctm = DB::table('conto_tabella_millesimale')->where('conto_id', $s['conto']->id)->value('id');
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 50]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 50, 'created_at' => now(), 'updated_at' => now()]);

    [$motore, $totali] = mtCalcola($s);
    expect(mtSomma($totali))->toBe(100001);
    $cambiamenti = $motore->getRisoluzioneTemporale()['destinatari_cambiati'];
    expect($cambiamenti)->toHaveCount(1)->and($cambiamenti[0]['motivo'])->toBe('pro_rata_giorni');

    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Rogito del 30 aprile, letto il pannello', esercizio: $s['esercizio']);
    $quota = RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $s['piano']->id))->first();
    expect($quota->regole_calcolo['parametri']['titolarita_alla']['coppie'])->toBe(1);
});

it('decisione 16 [S4] — il ramo ricostruito di DettaglioRiparto resta atemporale: un piano generato prima del subentro ristampa identico dalle righe registrate, e senza righe ricostruisce come la 1.10.x (50/50), dichiarandolo', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03'],
    ]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], esercizio: $s['esercizio']);
    $registrato = DettaglioRiparto::perPiano($s['piano']->fresh());
    expect($registrato['fonte']['tipo'])->toBe('registrato')->and($registrato['fonte']['risoluzione'])->toBe('temporale');

    // Il subentro registrato DOPO la generazione: la riga si chiude, l'acquirente entra.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['venditore'])->update(['data_fine' => '2026-04-30']);
    $acquirente = Anagrafica::forceCreate(['nome' => 'Acquirente', 'email' => 'mt-acq@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'MTACQ00000000001']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $acquirente->id, 'immobile_id' => $s['unita'][1]->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-05-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // (1) Registrato: il documento non si muove.
    $dopo = DettaglioRiparto::perPiano($s['piano']->fresh());
    expect($dopo['righe'])->toBe($registrato['righe']);

    // (2) Ricostruito (un piano della 1.10.x, senza righe): atemporale, 50/50 fra i due, colonne temporali nulle.
    RigaRiparto::where('piano_rate_id', $s['piano']->id)->delete();
    $ricostruito = DettaglioRiparto::perPiano($s['piano']->fresh());
    expect($ricostruito['fonte']['tipo'])->toBe('ricostruito')
        ->and($ricostruito['fonte']['risoluzione'])->toBe('atemporale');
    $perPersona = collect($ricostruito['righe'])->where('tipo', 'riparto')->groupBy('anagrafica_id')->map(fn ($r) => $r->sum('importo'));
    expect($perPersona[$s['persone']['venditore']->id])->toBe(50001)
        ->and($perPersona[$acquirente->id])->toBe(50000)
        ->and(collect($ricostruito['righe'])->pluck('competenza_dal')->unique()->all())->toBe([null]);
});

/*
|--------------------------------------------------------------------------
| Decisione 17 — il già versato sconta chi ha versato
|--------------------------------------------------------------------------
*/

it('critico S4 — una gestione riusata su un esercizio successivo senza spostarne le date non fa ereditare al piano il periodo vecchio: conta la parte della gestione dentro l\'esercizio del piano, e senza intersezione l\'esercizio', function () {
    // Il prodotto riusa la «Gestione ordinaria» su ogni esercizio importato (CondominioService::createDefaultGestione)
    // con le date del primo: gestione 2025, piano 2026. A chiude il 28/02/2026, B dal 01/03/2026.
    $s = mtScenario([
        'a' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-01-01', 'al' => '2026-02-28'],
        'b' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-03-01'],
    ], [1 => 1000.0], ['gestione_dal' => '2025-01-01', 'gestione_al' => '2025-12-31']);
    // Anche il 2025 è agganciato alla stessa gestione: senza l'indirizzo l'esercizio del piano va dedotto.
    $e2025 = Esercizio::create(['condominio_id' => $s['condominio']->id, 'nome' => 'Esercizio 2025', 'stato' => 'chiuso', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    legaAEsercizio($e2025, $s['gestione']->id);

    // Con l'esercizio dall'indirizzo: gradino «esercizio» 2026, non «gestione» 2025.
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    expect($competenza->gradino->value)->toBe('esercizio')->and($competenza->periodi->dal()->toDateString())->toBe('2026-01-01');
    // Senza l'indirizzo: il piano è stato creato oggi, dentro il 2026 → stesso esito.
    expect(app(CompetenzaDelPiano::class)->esercizioDelPiano($s['piano']->fresh())->id)->toBe($s['esercizio']->id)
        ->and(app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh())->periodi->al()->toDateString())->toBe('2026-12-31');

    // A 59 giorni (1/1–28/2), B 306: 100001 × 59/365 = 16164,55 → 16164; B 83836,45 → 83836; resta 1 → .55 vince → A 16165.
    // Prima del rimedio: A 100001, B 0.
    [, $totali] = mtCalcola($s);
    expect(mtDi($totali, $s['persone']['a']))->toBe(16165)->and(mtDi($totali, $s['persone']['b']))->toBe(83836);

    // La generazione scrive sul piano l'esercizio con cui è generato (migrazione 9, verifica S5 R8): da lì in poi
    // chi non ha l'indirizzo (conguaglio, anteprima) legge quello e non deduce più.
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'A chiuso il 28/02, letto il pannello', esercizio: $s['esercizio']);
    expect($s['piano']->fresh()->esercizio_id)->toBe($s['esercizio']->id)
        ->and(app(CompetenzaDelPiano::class)->esercizioDedotto($s['piano']->fresh()))->toBeFalse();

    // Gestione a cavallo (01/07/2025–30/06/2026) sull'esercizio 2026: conta la parte nel 2026 (gradino «gestione», 01/01–30/06).
    DB::table('gestioni')->where('id', $s['gestione']->id)->update(['data_inizio' => '2025-07-01', 'data_fine' => '2026-06-30']);
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    expect($competenza->gradino->value)->toBe('gestione')
        ->and($competenza->periodi->toArray())->toBe([['dal' => '2026-01-01', 'al' => '2026-06-30']]);
});

it('critico S4 — straordinario: due fatture con competenze diverse sullo stesso conto (due chiamate) scontano il già versato UNA volta sola, anche con conti.importo a zero', function () {
    // F1 con competenza dichiarata 01/03, F2 alla delibera del 15/02: entrambe prima del rogito (30/04) → tutto al venditore.
    $s = mtScenarioUno('2026-02-15', ['2026-03-01', '2026-03-01']);
    DB::table('conti')->where('id', $s['capitolo']->id)->update(['importo' => 0]);
    $f2 = FatturaPassiva::create([
        'condominio_id' => $s['condominio']->id, 'fornitore_id' => $s['fattura']->fornitore_id, 'esercizio_id' => $s['esercizio']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-S1-BIS', 'data_documento' => '2026-08-15', 'data_scadenza' => '2026-09-15',
        'is_pregresso' => false, 'importo_imponibile' => 100001, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 100001,
        'netto_a_pagare' => 100001, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $f2->id, 'conto_id' => $s['capitolo']->id, 'immobile_id' => null, 'descrizione' => 'Saldo lavori',
        'aliquota_iva' => 0, 'importo_imponibile' => 100001, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $s['piano']->fatture()->attach($f2->id, ['importo_collegato' => 100001]);
    ContributoVersato::create([
        'condominio_id' => $s['condominio']->id, 'target_type' => Conto::class, 'target_id' => $s['capitolo']->id,
        'immobile_id' => $s['immobileId'], 'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione',
    ]);

    $motore = new CalcoloQuoteService();
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    $totali = $motore->calcolaDaFattureStraordinarie($s['piano']->fresh(), $competenza);

    // 200002 lordi − 30000 versati = 170002, tutto al venditore. Prima del rimedio: 30000 scontati due volte → 140002.
    expect(mtSomma($totali))->toBe(170002)
        ->and(mtDi($totali, $s['persone']['venditore']))->toBe(170002)
        ->and(collect($motore->getRigheDettaglio())->where('tipo', 'netting')->sum('importo'))->toBe(-30000);
});

it('decisione 17 [S4] — il versato dell\'uscente (contributi_versati.anagrafica_id) sconta la quota dell\'uscente, non quella dell\'entrante; senza anagrafica resta dell\'unità come prima', function () {
    $scenario = fn () => mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ]);
    $versato = function (array $s, ?Anagrafica $chi, int $cents) {
        ContributoVersato::create([
            'condominio_id' => $s['condominio']->id, 'target_type' => Conto::class, 'target_id' => $s['conto']->id,
            'immobile_id' => $s['unita'][1]->id, 'anagrafica_id' => $chi?->id, 'importo_cents' => $cents,
            'natura' => 'fondo_vincolato', 'origine' => 'migrazione',
        ]);
    };

    // Lordi: venditore 32877, acquirente 67124. Il venditore ha anticipato € 300,00.
    $a = $scenario();
    $versato($a, $a['persone']['venditore'], 30000);
    [$mA, $tA] = mtCalcola($a);
    expect(mtDi($tA, $a['persone']['venditore']))->toBe(2877)
        ->and(mtDi($tA, $a['persone']['acquirente']))->toBe(67124)
        ->and(mtSomma($tA))->toBe(70001);
    $netting = collect($mA->getRigheDettaglio())->where('tipo', 'netting');
    expect($netting)->toHaveCount(1)
        ->and($netting->first()['anagrafica_id'])->toBe($a['persone']['venditore']->id)
        ->and($netting->first()['importo'])->toBe(-30000);

    // Ha versato più della sua quota: l'eccedenza è sua, l'acquirente non ne vede un centesimo.
    $b = $scenario();
    $versato($b, $b['persone']['venditore'], 40000);
    [$mB, $tB] = mtCalcola($b);
    expect(mtDi($tB, $b['persone']['venditore']))->toBe(0)
        ->and(mtDi($tB, $b['persone']['acquirente']))->toBe(67124);
    $ecc = collect($mB->getEccedenzeCopertura())->first();
    expect($ecc['anagrafica_id'])->toBe($b['persone']['venditore']->id)->and($ecc['eccedenza'])->toBe(40000 - 32877);

    // Legacy: la riga scritta per unità (senza persona) si spacca pro-lordo come nella beta.30:
    // 30000 × 32877/100001 = 9862,60 → 9863 (resto maggiore); 30000 × 67124/100001 = 20137,39 → 20137.
    $c = $scenario();
    $versato($c, null, 30000);
    [, $tC] = mtCalcola($c);
    expect(mtDi($tC, $c['persone']['venditore']))->toBe(32877 - 9863)
        ->and(mtDi($tC, $c['persone']['acquirente']))->toBe(67124 - 20137)
        ->and(mtSomma($tC))->toBe(70001);
});

/*
|--------------------------------------------------------------------------
| Verifica S6, R1 — sullo straordinario i tratti del capitolo non sono un gradino
|--------------------------------------------------------------------------
*/

it('R1 [S6] — su una gestione STRAORDINARIA i tratti scritti sul capitolo non scalzano la delibera: paga chi era titolare quel giorno, anche con i due tratti della stagione in tabella', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ], [1 => 1000.0], ['gestione_tipo' => 'straordinaria', 'data_delibera' => '2026-02-15', 'conto_nome' => 'Riscaldamento']);
    $s['piano']->capitoli()->attach($s['conto']->id, ['importo' => 100001]);
    $pivotId = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivotId, 'dal' => '2026-01-01', 'al' => '2026-04-15', 'ordine' => 1]);
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivotId, 'dal' => '2026-10-15', 'al' => '2026-12-31', 'ordine' => 2]);

    [$motore, $totali] = mtCalcola($s);

    // Prima della correzione: venditore 57378 e acquirente 42623 sul gradino «capitolo» — l'acquirente pagava una
    // spesa deliberata prima del suo rogito (decisioni 12 e 20: «lo straordinario resta un giorno»).
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->keyBy('anagrafica_id');
    expect(mtDi($totali, $s['persone']['venditore']))->toBe(100001)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBeNull()
        ->and($righe->pluck('gradino_competenza')->unique()->all())->toBe(['delibera']);
});

it('decisione 23 (S8-9) — un comproprietario censito a giugno accanto a una vendita di febbraio fra altri due non è l\'acquirente: la sua data_inizio non ha un predecessore contiguo e conta 365 giorni (50.000), Verdi 59 (8.082), Neri 306 (41.918)', function () {
    $s = mtScenario([
        'rossi' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2026-06-01'],
        'verdi' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2015-01-01', 'al' => '2026-02-28'],
        'neri'  => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2026-03-01'],
    ], [1 => 1000.0], ['importo' => 100000]);

    [$motore, $totali] = mtCalcola($s);

    // Prima (D7 largo): Rossi perdeva 151 giorni mai ceduti — 36.960/10.190/52.850 con giorni 214/59/306.
    expect(mtDi($totali, $s['persone']['rossi']))->toBe(50000)
        ->and(mtDi($totali, $s['persone']['verdi']))->toBe(8082)
        ->and(mtDi($totali, $s['persone']['neri']))->toBe(41918)
        ->and(mtSomma($totali))->toBe(100000);
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->keyBy('anagrafica_id');
    expect($righe[$s['persone']['rossi']->id]['giorni_titolarita'])->toBe(365)
        ->and($righe[$s['persone']['verdi']->id]['giorni_titolarita'])->toBe(59)
        ->and($righe[$s['persone']['neri']->id]['giorni_titolarita'])->toBe(306);
});

/*
|--------------------------------------------------------------------------
| Decisione 22 (S8-8) — i giorni in cui nessuno del ruolo è in vigore
|--------------------------------------------------------------------------
*/

it('decisione 22 (S8-8) — l\'inquilino esce il 30/6 e nessuno subentra: i suoi 181 giorni restano suoi (49.589) e i 184 giorni sfitti vanno al proprietario (50.411), non spalmati sull\'inquilino; somma esatta (inv. 18)', function () {
    $s = mtScenario([
        'proprietario' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
        'inquilino'    => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2020-01-01', 'al' => '2026-06-30'],
    ], [1 => 1000.0], ['importo' => 100000, 'soggetto' => 'inquilino']);

    [$motore, $totali] = mtCalcola($s);

    // Prima: l'inquilino era l'unico della sua coppia e i pesi normalizzati gli davano tutti i 100.000.
    expect(mtDi($totali, $s['persone']['inquilino']))->toBe(49589)
        ->and(mtDi($totali, $s['persone']['proprietario']))->toBe(50411)
        ->and(mtSomma($totali))->toBe(100000)
        ->and($motore->getScoperti())->toBe([]);
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->keyBy('anagrafica_id');
    expect($righe[$s['persone']['inquilino']->id]['giorni_titolarita'])->toBe(181)->and($righe[$s['persone']['inquilino']->id]['ruolo_risolto'])->toBe('inquilino')
        ->and($righe[$s['persone']['proprietario']->id]['giorni_titolarita'])->toBe(184)->and($righe[$s['persone']['proprietario']->id]['ruolo_risolto'])->toBe('proprietario');

    // Il cancello (2) dice dove sono andati i giorni scoperti.
    $voce = collect($motore->getRisoluzioneTemporale()['destinatari_cambiati'])->firstWhere('tipologia', 'inquilino');
    expect($voce['giorni_periodo'])->toBe(365)->and($voce['giorni_scoperti'])->toBe(184)
        ->and($voce['ripiego']['ruolo'])->toBe('proprietario')->and($voce['ripiego']['giorni_residui'])->toBe(0)
        ->and($voce['ripiego']['righe'][0]['giorni'])->toBe(184);
});

it('decisione 22 (S8-8) — il PROPRIETARIO esce il 30/6 senza successore: i suoi 181 giorni sono suoi (49.589), i 184 scoperti fermano la generazione con il motivo «giorni_senza_titolare» (50.411), nessuno paga al posto di nessuno', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-06-30'],
    ], [1 => 1000.0], ['importo' => 100000]);

    [$motore, $totali] = mtCalcola($s);

    expect(mtDi($totali, $s['persone']['venditore']))->toBe(49589)->and(mtSomma($totali))->toBe(49589);
    $scoperti = $motore->getScoperti();
    expect($scoperti)->toHaveCount(1)
        ->and($scoperti[0])->toMatchArray(['immobile_id' => $s['unita'][1]->id, 'importo' => 50411, 'motivo' => 'giorni_senza_titolare', 'giorni' => 184]);
    $voce = collect($motore->getRisoluzioneTemporale()['destinatari_cambiati'])->firstWhere('tipologia', 'proprietario');
    expect($voce['giorni_scoperti'])->toBe(184)->and($voce['ripiego']['ruolo'])->toBeNull()->and($voce['ripiego']['giorni_residui'])->toBe(184);
});

it('decisione 22 (S8-8) — locazione con un vuoto di tre mesi (A fino al 28/2, B dal 1/6 registrato con «Inizio locazione»): A 59 giorni, B 214, i 92 giorni sfitti al proprietario — non più spalmati su A e B', function () {
    $s = mtScenario([
        'proprietario' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
        'inqA' => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2020-01-01', 'al' => '2026-02-28'],
        'inqB' => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2026-06-01'],
    ], [1 => 1000.0], ['importo' => 100000, 'soggetto' => 'inquilino']);
    // B è entrato con un passaggio registrato (decisione 23): la sua data_inizio è una decorrenza.
    DB::table('subentri')->insert(['condominio_id' => $s['condominio']->id, 'immobile_id' => $s['unita'][1]->id, 'anagrafica_entrante_id' => $s['persone']['inqB']->id, 'riga_entrante_id' => $s['righe']['inqB'], 'tipologia' => 'inquilino', 'tipo_passaggio' => 'inizio_locazione', 'decorrenza' => '2026-06-01', 'created_at' => now(), 'updated_at' => now()]);

    [$motore, $totali] = mtCalcola($s);

    // 100.000 × 59/365 = 16.164; × 214/365 = 58.630; × 92/365 = 25.205 (+1 di resto maggiore). Prima: 21.612 / 78.388 / 0.
    expect(mtDi($totali, $s['persone']['inqA']))->toBe(16164)
        ->and(mtDi($totali, $s['persone']['inqB']))->toBe(58630)
        ->and(mtDi($totali, $s['persone']['proprietario']))->toBe(25206)
        ->and(mtSomma($totali))->toBe(100000)
        ->and($motore->getScoperti())->toBe([]);
});

it('decisione 22 (S8-8) — addebito diretto (ad personam): il proprietario che esce il 30/6 senza successore → 49.589 suoi e 50.411 scoperti «giorni_senza_titolare», con la riga del dettaglio sui suoi 181 giorni', function () {
    $s = mtScenario([
        'venditore' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-06-30'],
    ], [1 => 1000.0], ['importo' => 100000]);
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    $motore = new CalcoloQuoteService();
    $motore->calcolaPerGestione($s['gestione'], $s['piano']->fresh(), false, $competenza); // imposta la competenza e azzera i registri
    $totali = [];
    $rif = new \ReflectionMethod($motore, 'addebitaDiretto');
    $rif->invokeArgs($motore, [$s['unita'][1]->id, 100000, &$totali, null, $s['conto']->id, 'Riparazione balcone']);

    expect((int) array_sum($totali[$s['persone']['venditore']->id]))->toBe(49589);
    // Il primo scoperto è quello della tabella (calcolaPerGestione, stesso vuoto); l'ultimo è dell'addebito diretto.
    $scoperto = collect($motore->getScoperti())->where('motivo', 'giorni_senza_titolare')->last();
    expect($scoperto)->toMatchArray(['immobile_id' => $s['unita'][1]->id, 'importo' => 50411, 'giorni' => 184, 'riga_descrizione' => 'Riparazione balcone']);
    $riga = collect($motore->getRigheDettaglio())->where('tipo', 'ad_personam')->firstWhere('anagrafica_id', $s['persone']['venditore']->id);
    expect($riga['giorni_titolarita'])->toBe(181)->and($riga['importo'])->toBe(49589);
});

it('S8-bis L1-5 — il motore congela la natura del netting: con contributi misti (15.000 della persona, 10.000 dell\'unità) scrive due righe «netting» distinte per descrizione, che sommano al versato applicato', function () {
    $s = mtScenario([
        'rossi' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
    ], [1 => 1000.0], ['importo' => 50000]);
    DB::table('contributi_versati')->insert([
        ['condominio_id' => $s['condominio']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $s['conto']->id, 'immobile_id' => $s['unita'][1]->id, 'anagrafica_id' => $s['persone']['rossi']->id, 'importo_cents' => 15000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()],
        ['condominio_id' => $s['condominio']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $s['conto']->id, 'immobile_id' => $s['unita'][1]->id, 'anagrafica_id' => null, 'importo_cents' => 10000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()],
    ]);

    [$motore, $totali] = mtCalcola($s);

    expect(mtDi($totali, $s['persone']['rossi']))->toBe(25000);
    $netting = collect($motore->getRigheDettaglio())->where('tipo', 'netting')->values();
    expect($netting)->toHaveCount(2)
        ->and($netting->firstWhere('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)['importo'])->toBe(-15000)
        ->and($netting->firstWhere('riga_descrizione', CalcoloQuoteService::NETTING_DELL_UNITA)['importo'])->toBe(-10000)
        ->and((int) $netting->sum('importo'))->toBe(-25000);
});

/*
|--------------------------------------------------------------------------
| S8-bis — il tratto congelato (migrazione 11) e il conguaglio che lo legge
|--------------------------------------------------------------------------
*/

/** Le rate del piano emesse (stato + data), come le lascia l'emissione — il conguaglio guarda solo quelle. */
function mtEmetti(PianoRate $piano): void
{
    DB::table('rate')->where('piano_rate_id', $piano->id)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-10']);
}

it('S8-bis migrazione 11 — la generazione congela il TRATTO di ogni riga (titolarita_dal/al), non solo i giorni: venditore 1/1–30/4, acquirente 1/5–31/12, e per il ripiego gli estremi dei giorni scoperti', function () {
    $s = mtScenario([
        'venditore'    => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-03-03', 'al' => '2026-04-30'],
        'acquirente'   => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
        'inquilino'    => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2020-01-01', 'al' => '2026-06-30'],
    ], [1 => 1000.0], ['importo' => 100000, 'soggetto' => 'inquilino']);
    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaDestinatari: true, notaDestinatari: 'Rogito e fine locazione, letti', esercizio: $s['esercizio']);

    $righe = RigaRiparto::where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->get();
    $inq = $righe->firstWhere('anagrafica_id', $s['persone']['inquilino']->id);
    expect($inq->giorni_titolarita)->toBe(181)->and($inq->titolarita_dal->toDateString())->toBe('2026-01-01')->and($inq->titolarita_al->toDateString())->toBe('2026-06-30');
    // Il ripiego (decisione 22): i 184 giorni sfitti vanno al proprietario in corso in quei giorni — l'acquirente — sul suo tratto 1/7–31/12.
    $acq = $righe->firstWhere('anagrafica_id', $s['persone']['acquirente']->id);
    expect($acq->ruolo_risolto)->toBe('proprietario')->and($acq->giorni_titolarita)->toBe(184)
        ->and($acq->titolarita_dal->toDateString())->toBe('2026-07-01')->and($acq->titolarita_al->toDateString())->toBe('2026-12-31')
        ->and($righe->firstWhere('anagrafica_id', $s['persone']['venditore']->id))->toBeNull();
});

it('S8-bis L1-1 — la stessa persona con due righe sullo stesso conto (era al 50 %, ha comprato l\'altra metà il 1/5): il conguaglio divide ogni riga sul suo tratto — vende il 1/9 a E → 12.200 (100 % × 122 gg), non 30.500', function () {
    $s = mtScenario([
        'a' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2019-01-01', 'al' => '2026-04-30'],
        'u_prima' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50, 'dal' => '2019-01-01', 'al' => '2026-04-30', 'nome' => 'U'],
        'u_dopo' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01', 'nome' => 'U'],
    ], [1 => 1000.0], ['importo' => 36500]);
    // Stessa persona su due righe: la seconda riga è di U.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['u_dopo'])->update(['anagrafica_id' => $s['persone']['u_prima']->id]);
    $u = $s['persone']['u_prima'];
    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaDestinatari: true, notaDestinatari: 'Rogito del 30 aprile, letto', esercizio: $s['esercizio']);
    mtEmetti($s['piano']);
    $righeU = RigaRiparto::where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->where('anagrafica_id', $u->id)->orderBy('importo')->get();
    // 36.500 × (50 % × 120 gg + 100 % × 245 gg) / (50×120 + 50×120 + 100×245) → U: 6.000 (120 gg al 50 %) + 24.500 (245 gg al 100 %).
    expect($righeU->pluck('importo')->all())->toBe([6000, 24500])->and($righeU->pluck('giorni_titolarita')->all())->toBe([120, 245]);

    $e = Anagrafica::forceCreate(['nome' => 'E', 'email' => 'e-l11@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'ELUNOTEST0000001']);
    $r = (new \App\Services\Subentro\ConguaglioPassaggio())->calcola($u, $e, [$s['unita'][1]->id], \Carbon\CarbonImmutable::parse('2026-09-01'));
    // Per competenza E deve 36.500 × 122/365 = 12.200: la riga al 100 % dal 1/5 dà 24.500 × 122/245 = 12.200, quella al 50 % (1/1–30/4) niente.
    expect($r['totale_entrante'])->toBe(12200)->and($r['coppie'][0]['importo'])->toBe(12200);
    $conti = collect($r['quote'][0]['per_capitolo']);
    expect($conti)->toHaveCount(2)
        ->and($conti->firstWhere('importo', 6000))->toMatchArray(['entrante' => 0, 'giorni_entrante' => 0, 'giorni_periodo' => 120])
        ->and($conti->firstWhere('importo', 24500))->toMatchArray(['entrante' => 12200, 'giorni_uscente' => 123, 'giorni_entrante' => 122, 'giorni_periodo' => 245]);
});

it('S8-bis L1-2 — la quota del predecessore già tagliata dal pro rata alla sua uscita (A 120 gg) non passa a chi entra dopo: A→U 1/5 registrato prima della generazione, U→E 1/9 → una sola coppia da 12.200, la quota di A non riguarda il passaggio', function () {
    $s = mtScenario([
        'a' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-01-01', 'al' => '2026-04-30'],
        'u' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ], [1 => 1000.0], ['importo' => 36500]);
    // A→U è un passaggio registrato (la riga di U è l'entrante).
    DB::table('subentri')->insert(['condominio_id' => $s['condominio']->id, 'immobile_id' => $s['unita'][1]->id, 'anagrafica_uscente_id' => $s['persone']['a']->id, 'anagrafica_entrante_id' => $s['persone']['u']->id, 'riga_uscente_id' => $s['righe']['a'], 'riga_entrante_id' => $s['righe']['u'], 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaDestinatari: true, notaDestinatari: 'Rogito del 30 aprile, letto', esercizio: $s['esercizio']);
    mtEmetti($s['piano']);
    expect(mtQuotePerPersona($s['piano']))->toBe([$s['persone']['a']->id => 12000, $s['persone']['u']->id => 24500]);

    $e = Anagrafica::forceCreate(['nome' => 'E', 'email' => 'e-l12@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'ELDUETEST0000001']);
    $r = (new \App\Services\Subentro\ConguaglioPassaggio())->calcola($s['persone']['u'], $e, [$s['unita'][1]->id], \Carbon\CarbonImmutable::parse('2026-09-01'));
    // Prima: la quota di A (12.000, gennaio–aprile) entrava come ereditata e si divideva sull'anno intero → +4.011 a E.
    expect($r['totale_entrante'])->toBe(12200)->and($r['coppie'])->toHaveCount(1);
    $quotaA = collect($r['quote'])->firstWhere('quota_pura', 12000);
    expect($quotaA['entrante'])->toBe(0)->and($quotaA['uscente'])->toBe(0);
    expect(implode("\n", $r['frasi']))->not->toContain('€ 40,11');
});

it('S8-bis L1-4 — la riga di chi esce era già chiusa alla generazione (data_fine 30/4, tratto 1/1–30/4): il suo conguaglio alla vendita del 1/5 è zero, e il recesso anticipato dell\'inquilino (fino al 31/10, nuovo inquilino dal 1/7) divide 181/123 sul tratto, non «in coda»', function () {
    // Caso 1: A chiuso al 30/4 prima della generazione, B dal 1/5. Il piano dà ad A 120 gg. Il passaggio A→B viene
    // registrato DOPO (decorrenza 1/5): niente passa, A ha già pagato solo i suoi giorni.
    $s = mtScenario([
        'a' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2019-01-01', 'al' => '2026-04-30'],
        'b' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01'],
    ], [1 => 1000.0], ['importo' => 100001]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaDestinatari: true, notaDestinatari: 'Rogito del 30 aprile, letto', esercizio: $s['esercizio']);
    mtEmetti($s['piano']);
    $r = (new \App\Services\Subentro\ConguaglioPassaggio())->calcola($s['persone']['a'], $s['persone']['b'], [$s['unita'][1]->id], \Carbon\CarbonImmutable::parse('2026-05-01'));
    // Prima (giorni «in coda»): 32.877 × 245/365… tutto all'entrante. Ora: il tratto 1/1–30/4 finisce prima del 1/5.
    expect($r['totale_entrante'])->toBe(0)->and($r['coppie'])->toBe([])
        ->and(implode("\n", $r['frasi']))->toContain('finisce prima del 1 maggio 2026');

    // Caso 2: inquilino con data_fine 31/10 (contratto in scadenza), piano generato a gennaio: 304 gg. Recesso anticipato
    // il 1/7 con nuovo inquilino: la sua quota si divide sul suo tratto 1/1–31/10 → 181 a lui, 123 al nuovo.
    $t = mtScenario([
        'prop' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
        'inq'  => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2020-01-01', 'al' => '2026-10-31'],
    ], [1 => 1000.0], ['importo' => 100000, 'soggetto' => 'inquilino']);
    app(GeneratePianoRateAction::class)->execute($t['piano'], accettaDestinatari: true, notaDestinatari: 'Scadenza contratto, letta', esercizio: $t['esercizio']);
    mtEmetti($t['piano']);
    expect(mtQuotePerPersona($t['piano'])[$t['persone']['inq']->id])->toBe(83288); // 304/365; i 61 giorni di nov–dic al proprietario (decisione 22)
    $nuovo = Anagrafica::forceCreate(['nome' => 'Nuovo Inquilino', 'email' => 'ni-l14@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'NUOVOINQTEST0001']);
    $r2 = (new \App\Services\Subentro\ConguaglioPassaggio())->calcola($t['persone']['inq'], $nuovo, [$t['unita'][1]->id], \Carbon\CarbonImmutable::parse('2026-07-01'));
    // 83.288 × 123/304 = 33.699.
    expect($r2['totale_entrante'])->toBe(33699)->and($r2['quote'][0])->toMatchArray(['giorni_uscente' => 181, 'giorni_entrante' => 123, 'giorni_periodo' => 304])
        ->and(implode("\n", $r2['frasi']))->toContain('181 a Inq, 123 a Nuovo Inquilino');
});

it('S8-bis L2-1 — dopo una costituzione (1/7) o un\'estinzione (1/5) dell\'usufrutto la persona continua sull\'anello gemello: nessun giorno scoperto, tutto a lei (181 come proprietario + 184 come nudo; 120 + 245)', function () {
    $c = mtScenario([
        'rossi_pieno' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-06-30', 'nome' => 'Rossi'],
        'rossi_nudo'  => ['unita' => 1, 'ruolo' => 'nuda_proprietario', 'quota' => 100, 'dal' => '2026-07-01', 'nome' => 'Rossi'],
        'usu'         => ['unita' => 1, 'ruolo' => 'usufruttuario', 'quota' => 100, 'dal' => '2026-07-01'],
    ], [1 => 1000.0], ['importo' => 100000]);
    DB::table('anagrafica_immobile')->where('id', $c['righe']['rossi_nudo'])->update(['anagrafica_id' => $c['persone']['rossi_pieno']->id]);
    [$motore, $totali] = mtCalcola($c);
    // Prima (L2-1): il proprietario era «terminale» → 184 giorni scoperti «giorni_senza_titolare» e la generazione si fermava.
    expect(mtDi($totali, $c['persone']['rossi_pieno']))->toBe(100000)->and($motore->getScoperti())->toBe([]);
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto')->where('anagrafica_id', $c['persone']['rossi_pieno']->id)->sortBy('giorni_titolarita')->values();
    expect($righe->pluck('giorni_titolarita')->all())->toBe([181, 184])->and($righe->pluck('ruolo_risolto')->all())->toBe(['proprietario', 'nuda_proprietario']);

    $e = mtScenario([
        'rossi_nudo'  => ['unita' => 1, 'ruolo' => 'nuda_proprietario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-04-30', 'nome' => 'Rossi'],
        'rossi_pieno' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2026-05-01', 'nome' => 'Rossi'],
        'usu'         => ['unita' => 1, 'ruolo' => 'usufruttuario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-04-30'],
    ], [1 => 1000.0], ['importo' => 100000]);
    DB::table('anagrafica_immobile')->where('id', $e['righe']['rossi_pieno'])->update(['anagrafica_id' => $e['persone']['rossi_nudo']->id]);
    // L'estinzione è un passaggio registrato: la riga «pieno» di Rossi decorre dal 1/5 (D7 via b).
    DB::table('subentri')->insert(['condominio_id' => $e['condominio']->id, 'immobile_id' => $e['unita'][1]->id, 'anagrafica_uscente_id' => $e['persone']['usu']->id, 'anagrafica_entrante_id' => $e['persone']['rossi_nudo']->id, 'riga_uscente_id' => $e['righe']['usu'], 'riga_entrante_id' => $e['righe']['rossi_pieno'], 'tipologia' => 'proprietario', 'tipo_passaggio' => 'usufrutto', 'decorrenza' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
    [$motore2, $totali2] = mtCalcola($e);
    expect(mtDi($totali2, $e['persone']['rossi_nudo']))->toBe(100000)->and($motore2->getScoperti())->toBe([]);
});

it('S8-bis L2-2 — estinzione con due nudi (60/40) lo stesso giorno: anche il secondo pieno, che non è «riga_entrante_id», decorre dal 1/5 per la tripla (unità, ruolo, decorrenza) — 60.000 / 40.000, non 50,2 / 49,8', function () {
    $s = mtScenario([
        'rossi_nudo' => ['unita' => 1, 'ruolo' => 'nuda_proprietario', 'quota' => 60, 'dal' => '2015-01-01', 'al' => '2026-04-30', 'nome' => 'Rossi'],
        'neri_nudo'  => ['unita' => 1, 'ruolo' => 'nuda_proprietario', 'quota' => 40, 'dal' => '2015-01-01', 'al' => '2026-04-30', 'nome' => 'Neri'],
        'rossi_pieno' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 60, 'dal' => '2026-05-01', 'nome' => 'Rossi'],
        'neri_pieno'  => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 40, 'dal' => '2026-05-01', 'nome' => 'Neri'],
        'usu'         => ['unita' => 1, 'ruolo' => 'usufruttuario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-04-30'],
    ], [1 => 1000.0], ['importo' => 100000]);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['rossi_pieno'])->update(['anagrafica_id' => $s['persone']['rossi_nudo']->id]);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['neri_pieno'])->update(['anagrafica_id' => $s['persone']['neri_nudo']->id]);
    // Il record del passaggio nomina un solo entrante (il primo nudo).
    DB::table('subentri')->insert(['condominio_id' => $s['condominio']->id, 'immobile_id' => $s['unita'][1]->id, 'anagrafica_uscente_id' => $s['persone']['usu']->id, 'anagrafica_entrante_id' => $s['persone']['rossi_nudo']->id, 'riga_uscente_id' => $s['righe']['usu'], 'riga_entrante_id' => $s['righe']['rossi_pieno'], 'tipologia' => 'proprietario', 'tipo_passaggio' => 'usufrutto', 'decorrenza' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);

    [$motore, $totali] = mtCalcola($s);
    // Prima: Neri «aperto da sempre» → 60×245 / 40×365 → 50.171 / 49.829 e nessuno scoperto a segnalarlo.
    expect(mtDi($totali, $s['persone']['rossi_nudo']))->toBe(60000)->and(mtDi($totali, $s['persone']['neri_nudo']))->toBe(40000)->and($motore->getScoperti())->toBe([]);

    // E la forma SQL concorda: nel tratto 1/1–30/4 nessuno dei due «pieni» c'è, per entrambe le vie (invariante 4).
    $r = app(\App\Services\Riparto\RisolutoreTitolari::class);
    $tratto = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-30');
    expect($r->attiviAlla($s['unita'][1]->anagrafiche()->get(), $tratto)->filter(fn ($a) => $a->pivot->tipologia === 'proprietario')->count())->toBe(0)
        ->and($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita'][1]->id)->where('tipologia', 'proprietario'), $tratto)->count())->toBe(0);
});

it('S8-bis L2-3 — la cascata dei giorni scoperti avanza sul residuo: inquilino fino al 30/6, usufruttuario fino al 30/9, proprietario tutto l\'anno → 49.589 all\'inquilino, 92 giorni all\'usufruttuario, 92 al proprietario, nessuno scoperto', function () {
    $s = mtScenario([
        'prop' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
        'usu'  => ['unita' => 1, 'ruolo' => 'usufruttuario', 'quota' => 100, 'dal' => '2015-01-01', 'al' => '2026-09-30'],
        'inq'  => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2020-01-01', 'al' => '2026-06-30'],
    ], [1 => 1000.0], ['importo' => 100000, 'soggetto' => 'inquilino']);
    [$motore, $totali] = mtCalcola($s);
    // 181/365 = 49.589; 92/365 = 25.205 all'usufruttuario (1/7–30/9); 92/365 = 25.205 (+1 di resto) al proprietario (1/10–31/12).
    expect(mtDi($totali, $s['persone']['inq']))->toBe(49589)
        ->and(mtDi($totali, $s['persone']['usu']) + mtDi($totali, $s['persone']['prop']))->toBe(50411)
        ->and(abs(mtDi($totali, $s['persone']['usu']) - mtDi($totali, $s['persone']['prop'])))->toBeLessThanOrEqual(1)
        ->and(mtSomma($totali))->toBe(100000)->and($motore->getScoperti())->toBe([]);
    $voce = collect($motore->getRisoluzioneTemporale()['destinatari_cambiati'])->firstWhere('tipologia', 'inquilino');
    expect($voce['ripiego']['giorni_residui'])->toBe(0)
        ->and(collect($voce['ripiego']['righe'])->pluck('ruolo')->sort()->values()->all())->toBe(['proprietario', 'usufruttuario']);
    $righe = collect($motore->getRigheDettaglio())->where('tipo', 'riparto');
    expect($righe->firstWhere('anagrafica_id', $s['persone']['usu']->id))->toMatchArray(['ruolo_risolto' => 'usufruttuario', 'giorni_titolarita' => 92, 'titolarita_dal' => '2026-07-01', 'titolarita_al' => '2026-09-30'])
        ->and($righe->firstWhere('anagrafica_id', $s['persone']['prop']->id))->toMatchArray(['ruolo_risolto' => 'proprietario', 'giorni_titolarita' => 92, 'titolarita_dal' => '2026-10-01', 'titolarita_al' => '2026-12-31']);
});

it('S8-bis L2-4 — l\'inquilino estivo (16/4–14/10) sta tutto nel buco fra i due tratti del riscaldamento (1/1–15/4, 15/10–31/12): non è più scoperto «fuori competenza», i 183 giorni dei tratti vanno al proprietario per ripiego', function () {
    $s = mtScenario([
        'prop' => ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'dal' => '2015-01-01'],
        'inq'  => ['unita' => 1, 'ruolo' => 'inquilino', 'quota' => 100, 'dal' => '2026-04-16', 'al' => '2026-10-14'],
    ], [1 => 1000.0], ['importo' => 100000, 'soggetto' => 'inquilino']);
    // L'inquilino è entrato con un passaggio registrato: la sua data_inizio è una decorrenza (D7 via b).
    DB::table('subentri')->insert(['condominio_id' => $s['condominio']->id, 'immobile_id' => $s['unita'][1]->id, 'anagrafica_entrante_id' => $s['persone']['inq']->id, 'riga_entrante_id' => $s['righe']['inq'], 'tipologia' => 'inquilino', 'tipo_passaggio' => 'inizio_locazione', 'decorrenza' => '2026-04-16', 'created_at' => now(), 'updated_at' => now()]);
    $s['piano']->capitoli()->attach($s['conto']->id, ['importo' => 100000]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['conto']->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-04-15', 'ordine' => 0]);
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-10-15', 'al' => '2026-12-31', 'ordine' => 1]);

    [$motore, $totali] = mtCalcola($s);
    // Prima (S4): l'intera quota «titolari_fuori_competenza» e la generazione si fermava.
    expect(mtDi($totali, $s['persone']['prop']))->toBe(100000)->and(mtDi($totali, $s['persone']['inq']))->toBeNull()
        ->and($motore->getScoperti())->toBe([]);
});

/** La pregressa come la scrive `FatturaPassivaService`: nessuna riga straordinaria, l'eccedenza non coperta dai saldi iniziali sta in `fattura_coperture` (sopravvenienza) su un conto. */
function mtRendiPregressa(array $s, ?string $dal, ?string $al): void
{
    $s['fattura']->update(['is_pregresso' => true, 'data_documento' => '2025-11-20', 'competenza_dal' => $dal, 'competenza_al' => $al]);
    DB::table('righe_fattura')->where('fattura_passiva_id', $s['fattura']->id)->delete();
    DB::table('fattura_coperture')->insert([
        'fattura_passiva_id' => $s['fattura']->id, 'tipo_copertura' => 'sopravvenienza', 'importo' => 100001, 'stato' => 'pianificata',
        'conto_id' => $s['capitolo']->id, 'nota_amministratore' => 'Eccedenza fattura pregressa non coperta dai saldi iniziali', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Il motore dello straordinario, con la competenza che la generazione userebbe. */
function mtCalcolaStraordinario(array $s): array
{
    $motore = new CalcoloQuoteService();
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['esercizio']);
    $totali = $motore->calcolaDaFattureStraordinarie($s['piano']->fresh(), $competenza);

    return [$motore, $totali];
}

it('domanda di Vincenzo (20/09) — fattura PREGRESSA dell\'anno prima registrata dopo il passaggio: l\'eccedenza va a straordinario, e con «costo maturato» 2025 dichiarato sulla fattura paga chi era titolare nel 2025 (il venditore), non l\'acquirente del 2026; senza competenza dichiarata decide la delibera, e la delibera del 2026 lo darebbe all\'acquirente', function () {
    // Il venditore ha l'unità fino al 30/4/2026, l'acquirente dal 1/5; la pregressa (costo del 2025) è registrata nel 2026.
    $s = mtScenarioUno('2026-06-15');
    mtRendiPregressa($s, '2025-01-01', '2025-12-31');
    [$motore, $totali] = mtCalcolaStraordinario($s);
    $riga = collect($motore->getRigheDettaglio())->firstWhere('tipo', 'riparto');

    expect(mtDi($totali, $s['persone']['venditore']))->toBe(100001)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBeNull()
        ->and(mtSomma($totali))->toBe(100001)
        // Nel 2025 sulla coppia non cambia nessuno (il venditore è solo): riga atemporale, come vuole l'invariante 1.
        ->and($riga)->toMatchArray(['gradino_competenza' => 'dichiarata', 'competenza_dal' => '2025-01-01', 'competenza_al' => '2025-12-31', 'giorni_titolarita' => null]);

    // Stessa pregressa senza competenza dichiarata: il gradino è la delibera del 15/6/2026, dopo il rogito → l'acquirente.
    // È il motivo per cui il pannello della fattura chiede il costo maturato: la delibera del 2026 non dice nulla su un
    // costo del 2025.
    $s2 = mtScenarioUno('2026-06-15');
    mtRendiPregressa($s2, null, null);
    [, $totali2] = mtCalcolaStraordinario($s2);
    expect(mtDi($totali2, $s2['persone']['acquirente']))->toBe(100001)
        ->and(mtDi($totali2, $s2['persone']['venditore']))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Decisione 26 (1.11.0-beta.35) — nel piano da fatture la competenza dichiarata prevale qualunque sia la gestione
|--------------------------------------------------------------------------
|
| Fino alla beta.34 un piano da fatture su una gestione ORDINARIA ignorava la competenza dichiarata sulla fattura
| (ramo `conFatturaIgnorata()`) e divideva per giorni sull'esercizio: la pregressa 2025 andava per 245/365 a chi è entrato
| nel 2026. La decisione 11 diceva già che il primo gradino «è comune e prevale sempre»; la 26 la applica. Senza competenza
| dichiarata non cambia nulla (test «decisione 11 [S4]» qui sopra, che resta com'è).
*/

/** Lo scenario uno su una gestione ordinaria e senza delibera: il piano da fatture resta «straordinario» di tipo. */
function mtScenarioUnoOrdinario(array $competenzaFattura = [null, null]): array
{
    $s = mtScenarioUno(null, $competenzaFattura);
    DB::table('gestioni')->where('id', $s['gestione']->id)->update(['tipo' => 'ordinaria']);
    $s['gestione']->refresh();

    return $s;
}

it('decisione 26 [beta.35] — piano da fatture su gestione ORDINARIA: la pregressa con «costo maturato» 2025 è tutta di chi era titolare nel 2025 (il venditore), come sulla straordinaria; prima si divideva 120/245 sul 2026', function () {
    $s = mtScenarioUnoOrdinario();
    mtRendiPregressa($s, '2025-01-01', '2025-12-31');
    [$motore, $totali] = mtCalcolaStraordinario($s);
    $riga = collect($motore->getRigheDettaglio())->firstWhere('tipo', 'riparto');

    // Nel 2025 il venditore è solo: 100001 a lui, niente all'acquirente, riga atemporale (invariante 1).
    expect(mtDi($totali, $s['persone']['venditore']))->toBe(100001)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBeNull()
        ->and(mtSomma($totali))->toBe(100001)
        ->and($riga)->toMatchArray(['gradino_competenza' => 'dichiarata', 'competenza_dal' => '2025-01-01', 'competenza_al' => '2025-12-31', 'giorni_titolarita' => null]);
});

it('decisione 26 [beta.35] — anche un imprevisto dell\'anno: «costo maturato» 1/1–30/4/2026 su gestione ordinaria è tutto del venditore, e 1/3–31/8 si divide 61/123 sui giorni DICHIARATI, non sui 365 dell\'esercizio', function () {
    $s = mtScenarioUnoOrdinario(['2026-01-01', '2026-04-30']);
    [, $totali] = mtCalcolaStraordinario($s);
    expect(mtDi($totali, $s['persone']['venditore']))->toBe(100001)
        ->and(mtDi($totali, $s['persone']['acquirente']))->toBeNull();

    $s2 = mtScenarioUnoOrdinario(['2026-03-01', '2026-08-31']);
    [$motore2, $totali2] = mtCalcolaStraordinario($s2);
    // 184 giorni: venditore 1/3–30/4 = 61, acquirente 1/5–31/8 = 123. 100001 × 61/184 = 33152,51 e × 123/184 = 66848,49:
    // i resti maggiori danno il centesimo al venditore (,51 > ,49) → 33153 + 66848 = 100001.
    expect(mtDi($totali2, $s2['persone']['venditore']))->toBe(33153)
        ->and(mtDi($totali2, $s2['persone']['acquirente']))->toBe(66848)
        ->and(collect($motore2->getRigheDettaglio())->where('tipo', 'riparto')->pluck('giorni_titolarita', 'anagrafica_id')->all())
            ->toBe([$s2['persone']['venditore']->id => 61, $s2['persone']['acquirente']->id => 123])
        ->and(collect($motore2->getRigheDettaglio())->where('tipo', 'riparto')->pluck('gradino_competenza')->unique()->values()->all())->toBe(['dichiarata']);
});

it('decisione 26 [beta.35] — due fatture sullo stesso conto con competenze dichiarate diverse: il cancello (2) nomina tutte e due le divisioni, non solo l\'ultima (la chiave del cambiamento non portava il periodo)', function () {
    $s = mtScenarioUnoOrdinario(['2026-03-01', '2026-08-31']);
    $f2 = FatturaPassiva::create([
        'condominio_id' => $s['condominio']->id, 'fornitore_id' => $s['fattura']->fornitore_id, 'esercizio_id' => $s['esercizio']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-S1-BIS', 'data_documento' => '2026-06-15', 'data_scadenza' => '2026-07-15',
        'is_pregresso' => false, 'importo_imponibile' => 50000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 50000,
        'netto_a_pagare' => 50000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
        'competenza_dal' => '2026-04-01', 'competenza_al' => '2026-05-31',
    ]);
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $f2->id, 'conto_id' => $s['capitolo']->id, 'immobile_id' => null, 'descrizione' => 'Riparazione cancello',
        'aliquota_iva' => 0, 'importo_imponibile' => 50000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $s['piano']->fatture()->attach($f2->id, ['importo_collegato' => 50000]);

    try {
        app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), esercizio: $s['esercizio']);
        $this->fail('Attesa DestinatariCambiatiException');
    } catch (DestinatariCambiatiException $e) {
        $proRata = collect($e->getCambiamenti())->where('motivo', 'pro_rata_giorni');
        // 1/3–31/8 → 61/123; 1/4–31/5 → 30/31 (50000 × 30/61 = 24590,16).
        expect($proRata)->toHaveCount(2)
            ->and($proRata->pluck('periodo')->all())->toEqualCanonicalizing([
                [['dal' => '2026-03-01', 'al' => '2026-08-31']],
                [['dal' => '2026-04-01', 'al' => '2026-05-31']],
            ]);
    }
});

it('decisione 26 [beta.35] — pregressa GIÀ registrata senza periodo, su gestione ordinaria e senza nessun passaggio nell\'anno: il piano non indovina, si ferma e lo dice (si ripartisce sui giorni di quest\'anno), e con la nota genera', function () {
    // Una sola titolare per tutto il 2026: nessun pro rata, e prima della beta.35 la generazione non si fermava.
    $s = mtScenarioUnoOrdinario();
    DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['acquirente']->id)->delete();
    DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['venditore']->id)->update(['data_fine' => null]);
    mtRendiPregressa($s, null, null);

    try {
        app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), esercizio: $s['esercizio']);
        $this->fail('Attesa DestinatariCambiatiException');
    } catch (DestinatariCambiatiException $e) {
        $c = collect($e->getCambiamenti())->firstWhere('motivo', 'pregressa_senza_periodo');
        expect($c)->not->toBeNull()
            ->and($c['fattura_numero'])->toBe('FT-S1')
            ->and($c['gradino'])->toBe('esercizio')
            ->and($c['periodo'])->toBe([['dal' => '2026-01-01', 'al' => '2026-12-31']]);
    }
    expect($s['piano']->rate()->count())->toBe(0);

    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Pregressa del 2025 senza periodo: nel 2025 la titolare era la stessa', esercizio: $s['esercizio']);
    expect(mtQuotePerPersona($s['piano']))->toBe([$s['persone']['venditore']->id => 100001]);
    $regole = json_decode(DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->value('rate_quote.regole_calcolo'), true);
    // La pregressa senza periodo non è una coppia che cambia: la nota resta, le coppie sono zero.
    expect($regole['parametri']['titolarita_alla'])->toMatchArray(['destinatari_cambiati' => true, 'nota_cancello' => 'Pregressa del 2025 senza periodo: nel 2025 la titolare era la stessa', 'coppie' => 0]);
});

it('decisione 26 [beta.35] — pregressa senza periodo su gestione STRAORDINARIA: decide la delibera di quest\'anno, e il piano lo dice con lo stesso motivo (gradino «delibera», il giorno della delibera)', function () {
    $s = mtScenarioUno('2026-06-15');
    DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['acquirente']->id)->delete();
    DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['venditore']->id)->update(['data_fine' => null]);
    mtRendiPregressa($s, null, null);

    try {
        app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), esercizio: $s['esercizio']);
        $this->fail('Attesa DestinatariCambiatiException');
    } catch (DestinatariCambiatiException $e) {
        $c = collect($e->getCambiamenti())->firstWhere('motivo', 'pregressa_senza_periodo');
        expect($c)->not->toBeNull()
            ->and($c['gradino'])->toBe('delibera')
            ->and($c['periodo'])->toBe([['dal' => '2026-06-15', 'al' => '2026-06-15']]);
    }
});

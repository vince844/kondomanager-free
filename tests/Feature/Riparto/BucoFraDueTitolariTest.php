<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il buco fra due titolari scritti a mano: Bianchi fino al 30/06/2026, nessuno, Rossi dal 15/07/2026, senza un passaggio registrato. Il programma
 * conta Rossi dalla sua data, come se il passaggio fosse registrato, e i giorni in mezzo restano da assegnare («Quote non assegnate»).
 *
 * La regola: una riga senza una riga chiusa il giorno prima ha la sua data d'inizio come decorrenza anche quando sulla stessa coppia c'è una riga chiusa
 * prima che nessun'altra continua, e lei è la prima della coppia a cominciare dopo quella fine. Il comproprietario censito accanto a una vendita (la riga
 * chiusa ha già chi la continua) resta «aperto da sempre».
 *
 * Il caso: Pulizie € 1.000,00 sulla tabella «Generali», U1 500, U2 300, U3 200. La parte di U2 è € 300,00. Le cifre sono rifatte a mano nei commenti.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__ . '/ConsuntivoPerPersonaPosizioneScenario.php';

/**
 * Il palazzo del caso, con le righe di U2 date (`nome => [ruolo, quota, dal, al]`) e la voce sul ruolo dato. Neri (U1) e Verdi (U3) da sempre.
 */
function bftCaso(array $righeU2, string $soggetto = 'proprietario', array $op = []): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $g = Gestione::create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'descrizione' => 'Ordinaria']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 100000]);
    $t = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Generali', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $t->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = [];
    foreach (['U1' => 500, 'U2' => 300, 'U3' => 200] as $n => $v) {
        $unita[$n] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "BFT{$seq}-{$n}", 'nome' => $n, 'interno' => $n]);
        DB::table('quote_tabella')->insert(['tabella_id' => $t->id, 'immobile_id' => $unita[$n]->id, 'valore' => $v, 'created_at' => now(), 'updated_at' => now()]);
    }
    $persone = [];
    $righe = [];
    foreach (array_merge(['Neri' => ['U1', 'proprietario', 100, '2015-01-01', null], 'Verdi' => ['U3', 'proprietario', 100, '2015-01-01', null]],
        array_map(fn ($r) => array_merge(['U2'], $r), $righeU2)) as $nome => [$u, $ruolo, $quota, $dal, $al]) {
        $persone[$nome] ??= Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-bft{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => strtoupper(substr(md5($nome . 'bft' . $seq), 0, 16))]);
        $righe[$nome] = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => $ruolo,
            'quota' => $quota, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-01-15']);

    return compact('c', 'e', 'g', 'conto', 'unita', 'persone', 'righe', 'piano');
}

/** La generazione del piano, con la presa d'atto dei destinatari e degli scoperti; le righe del riparto di U2 per persona e le quote non assegnate. */
function bftGenera(array $s): array
{
    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: true, notaScoperti: 'Prova', accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $n) => [$p->id => $n])->all();
    $righe = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('immobile_id', $s['unita']['U2']->id)->where('tipo', 'riparto')->get()
        ->mapWithKeys(fn ($r) => [$nomi[$r->anagrafica_id] => [(int) $r->importo, (int) $r->giorni_titolarita, substr((string) $r->titolarita_dal, 0, 10)]])->all();
    $scoperti = collect($s['piano']->fresh()->quote_non_assegnate['voci'] ?? [])->where('immobile_id', $s['unita']['U2']->id)
        ->map(fn ($v) => [$v['motivo'], (int) $v['importo_cents'], (int) $v['giorni']])->values()->all();

    return [$righe, $scoperti];
}

it('il buco senza passaggio: Bianchi € 148,77, Rossi € 139,72 e € 11,51 di giorni da assegnare, come con il passaggio registrato (prima € 99,45 e € 200,55)', function () {
    $s = bftCaso(['Bianchi' => ['proprietario', 100, '2015-01-01', '2026-06-30'], 'Rossi' => ['proprietario', 100, '2026-07-15', null]]);

    [$righe, $scoperti] = bftGenera($s);

    // € 300,00 × 181/365 = 148,767 → 148,77 (Bianchi, 01/01–30/06); × 170/365 = 139,726 → 139,72 (Rossi, 15/07–31/12); × 14/365 = 11,507 → 11,51 (01/07–14/07).
    // 14.877 + 13.972 + 1.151 = 30.000. Prima Rossi contava da inizio anno: 181/546 e 365/546, 9.945 e 20.055.
    expect($righe)->toEqual(['Bianchi' => [14877, 181, '2026-01-01'], 'Rossi' => [13972, 170, '2026-07-15']])
        ->and($scoperti)->toBe([['giorni_senza_titolare', 1151, 14]]);
});

it('con il passaggio registrato le cifre sono le stesse: la regola nuova non cambia il caso che c\'era già', function () {
    $s = bftCaso(['Bianchi' => ['proprietario', 100, '2015-01-01', '2026-06-30'], 'Rossi' => ['proprietario', 100, '2026-07-15', null]]);
    DB::table('subentri')->insert(['condominio_id' => $s['c']->id, 'immobile_id' => $s['unita']['U2']->id, 'anagrafica_entrante_id' => $s['persone']['Rossi']->id,
        'riga_entrante_id' => $s['righe']['Rossi'], 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-07-15', 'created_at' => now(), 'updated_at' => now()]);

    [$righe, $scoperti] = bftGenera($s);

    expect($righe)->toEqual(['Bianchi' => [14877, 181, '2026-01-01'], 'Rossi' => [13972, 170, '2026-07-15']])
        ->and($scoperti)->toBe([['giorni_senza_titolare', 1151, 14]]);
});

it('il comproprietario censito accanto a una vendita resta aperto da sempre: la riga chiusa ha già chi la continua', function () {
    // Bianchi (50) fino al 28/02, Gialli (50) dal 01/03 contiguo; Rossi (50) censito il 01/06. La riga di Bianchi è continuata da Gialli: Rossi non è il primo
    // dopo un buco. € 300,00 su pesi 50×365, 50×59, 50×306 (somma 730): Rossi 150,00, Bianchi 300 × 59/730 = 24,247 → 24,25, Gialli 300 × 306/730 = 125,753 → 125,75.
    $s = bftCaso(['Rossi' => ['proprietario', 50, '2026-06-01', null], 'Bianchi' => ['proprietario', 50, '2015-01-01', '2026-02-28'], 'Gialli' => ['proprietario', 50, '2026-03-01', null]]);

    [$righe, $scoperti] = bftGenera($s);

    expect($righe)->toEqual(['Rossi' => [15000, 365, '2026-01-01'], 'Bianchi' => [2425, 59, '2026-01-01'], 'Gialli' => [12575, 306, '2026-03-01']])
        ->and($scoperti)->toBe([]);
});

it('chi esce da una comproprietà senza un passaggio non lascia un buco: l\'altro comproprietario c\'è, e chi entra dopo resta aperto da sempre (come oggi)', function () {
    // Bianchi (50) fino al 30/06, Gialli (50) da sempre, Rossi (50) dal 01/09 a mano. Il 01/07 Gialli c'è: nessun giorno senza titolare, la regola del buco non
    // vale. Pesi 50×181, 50×365, 50×365 (somma 911 × 50): Bianchi 300 × 181/911 = 59,605 → 59,60; Gialli e Rossi 300 × 365/911 = 120,197 → 120,20 e 120,20.
    $s = bftCaso(['Bianchi' => ['proprietario', 50, '2015-01-01', '2026-06-30'], 'Gialli' => ['proprietario', 50, '2015-01-01', null], 'Rossi' => ['proprietario', 50, '2026-09-01', null]]);

    [$righe, $scoperti] = bftGenera($s);

    expect($righe)->toEqual(['Bianchi' => [5960, 181, '2026-01-01'], 'Gialli' => [12020, 365, '2026-01-01'], 'Rossi' => [12020, 365, '2026-01-01']])
        ->and($scoperti)->toBe([]);
});

it('l\'inquilino nuovo dopo un vuoto, scritto a mano: conta dalla sua data e i mesi sfitti vanno al proprietario, come con «Inizio locazione»', function () {
    // Voce all'inquilino. Inquilino A fino al 28/02, B dal 01/06, nessun passaggio. € 300,00: A × 59/365 = 48,493 → 48,49; B × 214/365 = 175,890 → 175,89;
    // i 92 giorni sfitti al proprietario × 92/365 = 75,616 → 75,62 (il centesimo del resto). 4.849 + 17.589 + 7.562 = 30.000. Prima: A 41,75, B 258,25.
    $s = bftCaso(['Bianchi' => ['proprietario', 100, '2015-01-01', null], 'InqA' => ['inquilino', 100, '2020-01-01', '2026-02-28'], 'InqB' => ['inquilino', 100, '2026-06-01', null]], 'inquilino');

    [$righe, $scoperti] = bftGenera($s);

    expect($righe['InqA'][0])->toBe(4849)->and($righe['InqB'])->toBe([17589, 214, '2026-06-01'])->and($righe['Bianchi'][0])->toBe(7562)
        ->and($scoperti)->toBe([]);
});

it('due che entrano lo stesso giorno dopo il buco sono tutti e due i primi: contano dalla loro data', function () {
    // Bianchi (100) fino al 30/06; Rossi e Gialli (50 e 50) dal 15/07. € 300,00: Bianchi 148,77; Rossi e Gialli 300 × 50×170/(100×365) = 69,863 ciascuno → 69,86 e
    // 69,86; scoperti 11,51. 14.877 + 6.986 + 6.986 + 1.151 = 30.000.
    $s = bftCaso(['Bianchi' => ['proprietario', 100, '2015-01-01', '2026-06-30'], 'Rossi' => ['proprietario', 50, '2026-07-15', null], 'Gialli' => ['proprietario', 50, '2026-07-15', null]]);

    [$righe, $scoperti] = bftGenera($s);

    expect($righe)->toEqual(['Bianchi' => [14877, 181, '2026-01-01'], 'Rossi' => [6986, 170, '2026-07-15'], 'Gialli' => [6986, 170, '2026-07-15']])
        ->and(array_sum(array_column($scoperti, 1)))->toBe(1151);
});

it('la forma della query e quella della collection rispondono uguale sul buco, sulla comproprietà censita e su chi non è il primo dopo il buco', function () {
    $casi = [
        bftCaso(['Bianchi' => ['proprietario', 100, '2015-01-01', '2026-06-30'], 'Rossi' => ['proprietario', 100, '2026-07-15', null]]),
        bftCaso(['Rossi' => ['proprietario', 50, '2026-06-01', null], 'Bianchi' => ['proprietario', 50, '2015-01-01', '2026-02-28'], 'Gialli' => ['proprietario', 50, '2026-03-01', null]]),
        bftCaso(['Bianchi' => ['proprietario', 50, '2015-01-01', '2026-06-30'], 'X' => ['proprietario', 50, '2026-07-10', null], 'Rossi' => ['proprietario', 50, '2026-07-15', null]]),
    ];
    $periodi = [['2026-01-01', '2026-03-31'], ['2026-06-01', '2026-07-12'], ['2026-07-01', '2026-07-09'], ['2026-07-11', '2026-07-14'], ['2026-07-15', '2026-12-31']];
    $r = app(RisolutoreTitolari::class);
    foreach ($casi as $s) {
        $iid = $s['unita']['U2']->id;
        foreach ($periodi as [$dal, $al]) {
            $p = new PeriodoCompetenza($dal, $al);
            $query = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $iid), $p)->pluck('anagrafica_id')->sort()->values()->all();
            $collection = $r->attiviAlla(Immobile::find($iid)->anagrafiche()->get(), $p)->pluck('id')->sort()->values()->all();
            expect($query)->toBe($collection, "unità {$iid}, {$dal}–{$al}");
        }
    }
    // Nel primo caso Rossi non c'è dal 01/06 al 12/07, c'è dal 15/07; nel terzo X c'è dal 10/07 e Rossi, che non è il primo dopo il buco, sempre.
    $u = $casi[0]['unita']['U2']->id;
    expect($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $u), new PeriodoCompetenza('2026-07-01', '2026-07-14'))->count())->toBe(0);
    $u3 = $casi[2]['unita']['U2']->id;
    expect($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $u3), new PeriodoCompetenza('2026-07-01', '2026-07-09'))->pluck('anagrafica_id')->all())
        ->toBe([$casi[2]['persone']['Rossi']->id]);
});

// ─── La catena: dal preventivo al consuntivo per persona, sullo stesso buco (Via Verdi, U2 300/1000) ─────────────────────────────────────────────

/** Via Verdi con il buco su U2: Bianchi fino al 30/06, Rossi dal `$rossiDal`, senza passaggio. Il piano nato a gennaio si rifà dopo. */
function bftViaVerdiConBuco($test, string $rossiDal = '2026-07-15'): array
{
    $s = cpcViaVerdi($test);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-06-30']);
    $s['righe']['Rossi'] = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => $rossiDal, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return $s;
}

it('la catena: il preventivo rifatto e il consuntivo per persona contano Rossi dal 15/07 e lasciano i 14 giorni senza titolare, con la frase che lo dice', function () {
    $s = bftViaVerdiConBuco($this);

    // Il preventivo rifatto (le rate del piano di gennaio, mai emesse, si tolgono come fa il ricalcolo): senza la presa d'atto si ferma sugli scoperti, con la
    // presa d'atto li scrive fra le quote non assegnate.
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id');
    DB::table('rate_quote')->whereIn('rata_id', $rate)->delete();
    DB::table('rate')->whereIn('id', $rate)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']))
        ->toThrow(\App\Exceptions\Gestionale\ScopertiNonAccettatiException::class);
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true, notaScoperti: 'Prova', accettaDestinatari: true,
        notaDestinatari: 'Prova', esercizio: $s['e']);
    $riparto = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('immobile_id', $s['unita']['U2']->id)->where('tipo', 'riparto')
        ->selectRaw('anagrafica_id, SUM(importo) as tot')->groupBy('anagrafica_id')->pluck('tot', 'anagrafica_id')->map(fn ($v) => (int) $v)->all();
    $scoperto = (int) collect($s['piano']->fresh()->quote_non_assegnate['voci'] ?? [])->where('immobile_id', $s['unita']['U2']->id)->sum('importo_cents');
    // Pulizie € 900,00 di U2: 446,30 (181/365, .14), 419,18 (170/365, .81 → il centesimo del resto), 34,52 (14/365, .05). Manutenzioni € 300,00: 148,77 (.71),
    // 139,72 (.60), 11,51 (.68): i due centesimi del resto a .71 e .68. Bianchi 446,30 + 148,77 = 595,07; Rossi 419,18 + 139,72 = 558,90; scoperti 34,52 + 11,51 = 46,03.
    expect($riparto)->toBe([$s['persone']['Bianchi']->id => 59507, $s['persone']['Rossi']->id => 55890])->and($scoperto)->toBe(4603);

    // Il consuntivo per persona, sulle spese dell'anno (€ 3.300,00 + € 900,00; U2 € 1.260,00): Bianchi 1.260 × 181/365 = 624,82 (.19); Rossi × 170/365 = 586,849
    // → 586,85; i 14 giorni × 14/365 = 48,329 → 48,33, non attribuiti. 62.482 + 58.685 + 4.833 = 126.000.
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $a = fn (string $chi) => (int) array_sum(cpcDi($c, $s, $chi, 'U2')['voci'] ?? []);
    expect([$a('Bianchi'), $a('Rossi')])->toBe([62482, 58685])
        ->and((int) array_sum($c['gestioni'][$s['g']->id]['non_attribuite']))->toBe(4833)
        ->and($c['passaggi_non_registrati'][$s['unita']['U2']->id] ?? [])->toBe(['Int. U2: Bianchi fino al 30/06/2026, Rossi dal 15/07/2026, senza un passaggio registrato. '
            .'Rossi conta dal 15/07/2026, come se il passaggio fosse registrato: i giorni in mezzo non hanno un titolare e la loro parte non è divisa fra le persone.']);
});

it('la spesa personale divisa per giorni nel buco divide come la spesa comune: € 61,00 dal 01/06 al 31/07, Bianchi € 30,00, Rossi € 21,00, € 10,00 senza titolare', function () {
    // Bianchi fino al 30/06, nessuno dal 01/07 al 10/07, Rossi dall'11/07. 61 giorni a € 1,00: giugno a Bianchi (30), luglio dall'11 a Rossi (21), i 10 giorni
    // in mezzo a nessuno. Prima: Bianchi € 15,00 e Rossi € 46,00 (giugno a metà fra i due, luglio a Rossi); la regola della comune di allora € 20,11 e € 40,89.
    $s = bftViaVerdiConBuco($this, '2026-07-11');
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U2', 6100, '2026-06-01', null, '2026-06-01');
    DB::table('fatture_passive')->where('id', DB::table('righe_fattura')->where('id', $riga)->value('fattura_passiva_id'))->update(['competenza_al' => '2026-07-31']);
    $c = cpcLeggi($this, $s);
    $personale = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['personali']['non_chieste'] ?? 0);

    expect([$personale('Bianchi'), $personale('Rossi')])->toBe([3000, 2100]);
    // La spesa comune degli stessi giorni: le righe valgono 30 e 21 giorni sul periodo, come la personale.
    $r = app(RisolutoreTitolari::class);
    $righe = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['U2']->id)->get();
    $periodo = new PeriodoCompetenza('2026-06-01', '2026-07-31');
    expect($r->giorniDiTitolarita($righe->firstWhere('id', $s['righe']['Bianchi']), $periodo, $righe))->toBe(30)
        ->and($r->giorniDiTitolarita($righe->firstWhere('id', $s['righe']['Rossi']), $periodo, $righe))->toBe(21);
});

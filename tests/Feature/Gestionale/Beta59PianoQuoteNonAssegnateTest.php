<?php

/**
 * Coda 414 — un piano giusto con una quota non assegnata accettata non è «Disallineato: ricalcola!».
 *
 * Cosa resta scoperto: le casistiche rare della Coda 415 (piano straordinario, voci di entrata, già versato o gruppo frazionato con una quota non
 * assegnata); «Sposta spesa» con un già versato; MySQL. Il disegno della pagina e del cruscotto ha i suoi vitest
 * (`PianiRateShowQuoteNonAssegnate.test.ts`, `DashboardQuotaAperta.test.ts`).
 *
 * La regola (decisione 153.6): alla generazione, quando si accettano gli scoperti, il piano registra in `piani_rate.quote_non_assegnate` le voci
 * (chiave, motivo, unità, voce, tabella, cifra in centesimi) e lo scarto della generazione (atteso − generato, `PianoRateQuoteService`); senza
 * scoperti la colonna e la nota si svuotano. Lo stato del piano ha tre valori: allineato (scarto zero), quota aperta (lo scarto di adesso è
 * quello registrato, e viene dalle quote non assegnate), disallineato (altrimenti); `eDisallineato()` vuol dire il terzo.
 *
 * Il caso del v34: la voce «Riscaldamento» preventivata a € 4.000,00 sulla tabella «Riscaldamento» (A di Ugo 500, C di Bice 470, Cantina 1 30
 * millesimi senza proprietario), nel piano per € 2.500,00. Le cifre vengono dalla regola del motore (`CalcoloQuoteService::distribuisciSuTabelle`:
 * la quota della Cantina è l'importo per il suo peso sul peso totale, arrotondata; il resto si divide fra chi ha un titolare): Cantina
 * 2.500,00 × 30 / 1000 = € 75,00, A 2.425,00 × 500 / 970 = € 1.250,00, C 2.425,00 × 470 / 970 = € 1.175,00; nelle rate € 2.425,00.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59PianoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

// ── Il caso del v34 e i quattro casi della riga ──────────────────────────────────────────────────────────────────

it('Coda 414 — il piano del v34 con la Cantina 1 accettata ha € 2.425,00 nelle rate e la pagina non lo dice disallineato: quota aperta di € 75,00', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 242500, 'disallineato' => false]);

    $p = b59pPagina($this, $s, $piano);
    expect($p['disallineato'])->toBeFalse();
    expect($p['allineamento'])->toBe(['stato' => 'quota_aperta', 'atteso_cents' => 250000, 'generato_cents' => 242500, 'scarto_cents' => 7500, 'scarto_registrato_cents' => 7500]);
});

it('Coda 414 — la generazione registra la quota accettata: la Cantina 1, la voce e la tabella del riscaldamento, € 75,00, e lo scarto di € 75,00', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);

    $colonna = b59pColonna($piano);
    expect($colonna['scarto_cents'])->toBe(7500);
    expect($colonna['voci'])->toHaveCount(1);
    expect(collect($colonna['voci'][0])->only(['immobile_id', 'immobile_nome', 'conto_id', 'conto_nome', 'tabella_id', 'tabella_nome', 'importo_cents'])->all())->toBe([
        'immobile_id' => $s['unita']['Cantina']->id, 'immobile_nome' => 'Cantina 1', 'conto_id' => $s['riscaldamento']->id, 'conto_nome' => 'Riscaldamento',
        'tabella_id' => $s['risc']->id, 'tabella_nome' => 'Riscaldamento', 'importo_cents' => 7500,
    ]);
    // la chiave dice motivo, unità, voce e tabella: è il riferimento stabile della voce
    expect($colonna['voci'][0]['chiave'])->toContain('|'.$s['unita']['Cantina']->id.'|'.$s['riscaldamento']->id.'|'.$s['risc']->id.'|');
    expect($colonna['registrate_da'])->toBe($this->user->id);

    // la pagina riceve la colonna con il piano, e la voce del riscaldamento sa quanto le manca
    $p = b59pPagina($this, $s, $piano)['pianoRate'];
    expect($p['quote_non_assegnate']['scarto_cents'])->toBe(7500);
    expect(collect($p['capitoli'])->firstWhere('id', $s['riscaldamento']->id)['non_assegnati_cents'])->toBe(7500);
});

it('Coda 414 — il cruscotto non mette il piano in quota aperta nell\'elenco «Ricalcolo necessario»', function () {
    $s = b59pScenarioV34($this);
    b59pPianoV34($this, $s);

    expect(b59pCruscotto($this, $s)['pianiDisallineati'])->toBe([]);
});

it('Coda 414 — controprova: un piano senza quote non assegnate è allineato, e la colonna e la nota restano vuote', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    $piano = b59pPianoV34($this, $s, procedi: false);

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);
    expect(b59pColonna($piano))->toBeNull();
    expect(PianoRate::find($piano->id)->nota_scoperti)->toBeNull();
});

it('Coda 414 — controprova: un già versato registrato dopo la generazione, senza quote non assegnate, rende il piano disallineato di − € 100,00', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, procedi: false, extra: b59pConPulizie($s));

    // il già versato di Ugo sulle pulizie, scritto dopo: l'atteso scende di € 100,00 (la voce è nel piano per intero), le rate no
    b52tGiaVersato($this, $s, $s['pulizie'], 'A', 10_000);

    expect(b59pMisura($piano))->toBe(['atteso' => 340000, 'generato' => 350000, 'disallineato' => true]);
    expect(collect(b59pCruscotto($this, $s)['pianiDisallineati'])->map(fn ($d) => [$d['id'], $d['delta']])->all())->toBe([[$piano->id, -10000]]);
});

it('Coda 414 — la quota accettata e un già versato scritto dopo, insieme: il piano è disallineato di − € 25,00 (€ 75,00 − € 100,00), la quota aperta non lo nasconde', function () {
    $s = b59pScenarioV34($this);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, extra: b59pConPulizie($s));
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');

    b52tGiaVersato($this, $s, $s['pulizie'], 'A', 10_000);

    expect(b59pMisura($piano))->toBe(['atteso' => 340000, 'generato' => 342500, 'disallineato' => true]);
    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'disallineato', 'scarto_cents' => -2500, 'scarto_registrato_cents' => 7500]);
    expect(collect(b59pCruscotto($this, $s)['pianiDisallineati'])->map(fn ($d) => [$d['id'], $d['delta']])->all())->toBe([[$piano->id, -2500]]);

    // «Ricalcola» (con la presa d'atto, la Cantina è ancora senza proprietario) riporta le rate al già versato: torna la sola quota aperta
    b59pRicalcola($this, $s, $piano, 'Cantina 1 ancora senza proprietario')->assertSessionHasNoErrors();
    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'quota_aperta', 'scarto_cents' => 7500]);
});

it('Coda 414 — il preventivo della voce alzato dopo la generazione non sposta il piano, che chiede l\'importo scritto nel piano: resta in quota aperta', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);

    // da € 4.000,00 a € 5.000,00 dalla pagina della voce: il piano chiede i suoi € 2.500,00 (`piano_rate_capitoli.importo`)
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['riscaldamento']]), [
        'nome' => 'Riscaldamento', 'codice' => '', 'tipo' => 'spesa', 'importo' => '5.000,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['risc']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    expect(Conto::find($s['riscaldamento']->id)->importo)->toBe(500000);

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 242500, 'disallineato' => false]);
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
});

// ── Il ritorno ad allineato e i percorsi che rigenerano ──────────────────────────────────────────────────────────

it('Coda 414 — censita la Cantina 1 e premuto «Ricalcola», il piano torna allineato e la colonna, la nota e lo «scoperto documentato» del cruscotto si spengono', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);
    expect(b59pCruscotto($this, $s)['copertura']['has_scoperti_documentati'])->toBeTrue();

    b59pCensisci($s, 'Cantina', 'ugo');
    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors()->assertSessionMissing('scoperti_warning');

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);
    expect(b59pColonna($piano))->toBeNull();
    expect(PianoRate::find($piano->id)->nota_scoperti)->toBeNull();
    expect(b59pPagina($this, $s, $piano)['pianoRate']['nota_scoperti'])->toBeNull();
    expect(b59pCruscotto($this, $s)['copertura']['has_scoperti_documentati'])->toBeFalse();
});

it('Coda 414 — «Rimuovi voce e ricalcola» sulla voce della quota (rigenera accettando, senza nota): la quota non c\'è più e la colonna e la nota si svuotano', function () {
    $s = b59pScenarioV34($this);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, extra: b59pConPulizie($s));
    expect(b59pColonna($piano)['scarto_cents'])->toBe(7500);

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $s['riscaldamento']->id]))->assertSessionHasNoErrors();

    expect(b59pMisura($piano))->toBe(['atteso' => 100000, 'generato' => 100000, 'disallineato' => false]);
    expect(b59pColonna($piano))->toBeNull();
    expect(PianoRate::find($piano->id)->nota_scoperti)->toBeNull();
});

it('Coda 414 — «Rimuovi voce e ricalcola» su un\'altra voce: la quota resta, la colonna si riscrive con la nuova generazione e la nota di prima resta', function () {
    $s = b59pScenarioV34($this);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, extra: b59pConPulizie($s));

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $s['pulizie']->id]))->assertSessionHasNoErrors();

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 242500, 'disallineato' => false]);
    $colonna = b59pColonna($piano);
    expect($colonna['scarto_cents'])->toBe(7500)
        ->and(collect($colonna['voci'])->pluck('immobile_nome')->all())->toBe(['Cantina 1']);
    expect(PianoRate::find($piano->id)->nota_scoperti)->toBe('Cantina 1 senza proprietario al rogito');
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
});

it('Coda 414 — un piano accettato prima dell\'aggiornamento (la nota, la colonna vuota) resta disallineato; «Ricalcola» chiede la presa d\'atto e la registra: quota aperta', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);
    DB::table('piani_rate')->where('id', $piano->id)->update(['quote_non_assegnate' => null]); // come lo lascia la versione di prima

    expect(b59pMisura($piano)['disallineato'])->toBeTrue();
    $allineamento = b59pPagina($this, $s, $piano)['allineamento'];
    expect([$allineamento['stato'], $allineamento['scarto_cents']])->toBe(['disallineato', 7500])
        ->and($allineamento['scarto_registrato_cents'])->toBeNull();
    expect(collect(b59pCruscotto($this, $s)['pianiDisallineati'])->pluck('delta')->all())->toBe([7500]);

    b59pRicalcola($this, $s, $piano)->assertSessionHas('scoperti_warning');
    b59pRicalcola($this, $s, $piano, 'Cantina 1 senza proprietario al rogito')->assertSessionHasNoErrors();

    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
});

it('Coda 414 — un piano accettato prima dell\'aggiornamento, censita la Cantina 1 e premuto «Ricalcola»: la nota di prima si spegne e il piano è allineato', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);
    DB::table('piani_rate')->where('id', $piano->id)->update(['quote_non_assegnate' => null]); // come lo lascia la versione di prima

    b59pCensisci($s, 'Cantina', 'ugo');
    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors()->assertSessionMissing('scoperti_warning');

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);
    expect(PianoRate::find($piano->id)->nota_scoperti)->toBeNull();
    expect(b59pCruscotto($this, $s)['copertura']['has_scoperti_documentati'])->toBeFalse();
});

// ── Le altre casistiche comuni della voce (g) ────────────────────────────────────────────────────────────────────

it('Coda 414 — una voce senza tabella millesimale accettata: l\'intero importo (€ 600,00) è la quota aperta, e la voce lo dice senza unità', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    b59pPulizie($this, $s);
    $ascensore = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Ascensore', 'tipo' => 'spesa', 'importo' => 60000]);
    $piano = b59pPianoV34($this, $s, nota: 'Tabella dell\'ascensore in approvazione', extra: ['capitoli_ids' => [$s['pulizie']->id, $ascensore->id], 'capitoli_config' => [
        ['id' => $s['pulizie']->id, 'importo' => '1.000,00', 'note' => ''], ['id' => $ascensore->id, 'importo' => '600,00', 'note' => ''],
    ]]);

    // la regola: un capitolo con l'importo del piano e senza tabelle è scoperto per intero (`CalcoloQuoteService::processaConti`)
    expect(b59pMisura($piano))->toBe(['atteso' => 160000, 'generato' => 100000, 'disallineato' => false]);
    $voce = b59pColonna($piano)['voci'][0];
    expect(collect($voce)->only(['motivo', 'immobile_id', 'conto_nome', 'importo_cents'])->all())->toBe(['motivo' => 'conto_senza_tabella', 'immobile_id' => null, 'conto_nome' => 'Ascensore', 'importo_cents' => 60000]);
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
});

it('Coda 414 — una tabella senza millesimi accettata: la sua fetta (€ 300,00) è la quota aperta', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    b59pPulizie($this, $s);
    $giardino = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Giardino', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $s['giardino'] = b52tVoce($this, $s, 'Giardino', 'spesa', '300,00', $giardino);
    $piano = b59pPianoV34($this, $s, nota: 'Millesimi del giardino dal tecnico', extra: ['capitoli_ids' => [$s['pulizie']->id, $s['giardino']->id], 'capitoli_config' => [
        ['id' => $s['pulizie']->id, 'importo' => '1.000,00', 'note' => ''], ['id' => $s['giardino']->id, 'importo' => '300,00', 'note' => ''],
    ]]);

    // la regola: la fetta della tabella senza millesimi è scoperta (coefficiente 100: l'intero importo della voce)
    expect(b59pMisura($piano))->toBe(['atteso' => 130000, 'generato' => 100000, 'disallineato' => false]);
    $voce = b59pColonna($piano)['voci'][0];
    expect(collect($voce)->only(['motivo', 'tabella_nome', 'importo_cents'])->all())->toBe(['motivo' => 'tabella_senza_millesimi', 'tabella_nome' => 'Giardino', 'importo_cents' => 30000]);
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
});

it('Coda 414 — un millesimo non compilato accettato non è una quota aperta: la sua parte va alle altre unità, il piano resta allineato, e la colonna lo registra senza cifra', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    DB::table('quote_tabella')->where('tabella_id', $s['risc']->id)->where('immobile_id', $s['unita']['Cantina']->id)->update(['valore' => null]);
    $piano = b59pPianoV34($this, $s, nota: 'Millesimo della cantina dal tecnico');

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);
    // lo scarto registrato è zero, e la voce senza cifra non spiega niente: per la pagina non c'è uno scarto registrato
    $allineamento = b59pPagina($this, $s, $piano)['allineamento'];
    expect($allineamento['stato'])->toBe('allineato')
        ->and($allineamento['scarto_registrato_cents'])->toBeNull(); // `toMatchArray` confronta largo: 0 passerebbe per null
    $colonna = b59pColonna($piano);
    expect($colonna['scarto_cents'])->toBe(0)
        ->and(collect($colonna['voci'])->map(fn ($v) => [$v['motivo'], $v['immobile_nome'], $v['importo_cents']])->all())->toBe([['millesimo_non_compilato', 'Cantina 1', 0]]);
});

it('Coda 414 — con la prima rata emessa il piano in quota aperta non si ricalcola più, ma non è disallineato e il cruscotto non lo elenca', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);
    b52tApprova($this, $s, $piano);
    b52tEmetti($this, $s, $piano, 1, false);

    $p = b59pPagina($this, $s, $piano);
    expect($p['fermo_del_piano'])->not->toBeNull();
    expect($p['disallineato'])->toBeFalse();
    expect($p['allineamento']['stato'])->toBe('quota_aperta');
    expect(b59pCruscotto($this, $s)['pianiDisallineati'])->toBe([]);
});

it('Coda 414 — due cantine senza proprietario sulla stessa voce: le voci valgono € 2,45 ciascuna, lo scarto € 4,89 (un centesimo di arrotondamento), ed è quota aperta', function () {
    $s = b52tCondominio();
    $e = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $e, 'ordinaria', '2026-01-01', '2026-12-31');
    $risc = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    // pesi su 1024: 1/1024 è esatto in binario, e la quota di ciascuna cantina è un mezzo centesimo esatto
    b52tUnita($s, 'A', 'ugo', $risc, 512.0);
    b52tUnita($s, 'C', 'bice', $risc, 510.0);
    foreach (['K1', 'K2'] as $k) {
        $s['unita'][$k] = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'cantina', 'codice_immobile' => "B59P-{$s['seq']}-{$k}", 'nome' => "Cantina {$k}", 'interno' => $k]);
        DB::table('quote_tabella')->insert(['tabella_id' => $risc->id, 'immobile_id' => $s['unita'][$k]->id, 'valore' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s += ['e' => $e, 'g' => $g, 'pc' => \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']), 'risc' => $risc];
    $s['riscaldamento'] = b52tVoce($this, $s, 'Riscaldamento', 'spesa', '2.503,68', $risc);
    $piano = b59pPianoV34($this, $s, extra: ['capitoli_config' => [['id' => $s['riscaldamento']->id, 'importo' => '2.503,68', 'note' => '']]]);

    // ogni cantina: round(250368 × 1 / 1024) = round(244,5) = 245; la decurtazione è una sola: round(250368 × 2 / 1024) = 489
    expect(collect(b59pColonna($piano)['voci'])->pluck('importo_cents')->all())->toBe([245, 245]);
    expect(b59pMisura($piano))->toBe(['atteso' => 250368, 'generato' => 249879, 'disallineato' => false]);
    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'quota_aperta', 'scarto_cents' => 489, 'scarto_registrato_cents' => 489]);
});

// ── Lo stato si deriva dalle quote registrate, non dalla nota ───────────────────────────────────────────────────

it('Coda 414 — lo scarto registrato che le voci non spiegano non fa quota aperta: con le sole voci senza cifra, o con una cifra lontana, il piano è disallineato', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);
    $colonna = b59pColonna($piano);
    $svc = app(PianoRateQuoteService::class);

    // la stessa colonna con la voce a zero (un millesimo non compilato): lo scarto di € 75,00 non viene da nessuna quota non assegnata
    $senzaCifra = $colonna;
    $senzaCifra['voci'][0]['importo_cents'] = 0;
    DB::table('piani_rate')->where('id', $piano->id)->update(['quote_non_assegnate' => json_encode($senzaCifra)]);
    expect($svc->statoAllineamento(PianoRate::find($piano->id)))->toBe('disallineato');

    // una voce da € 1,00 per uno scarto di € 75,00: non è un arrotondamento
    $lontana = $colonna;
    $lontana['voci'][0]['importo_cents'] = 100;
    DB::table('piani_rate')->where('id', $piano->id)->update(['quote_non_assegnate' => json_encode($lontana)]);
    expect($svc->statoAllineamento(PianoRate::find($piano->id)))->toBe('disallineato');

    // un centesimo per voce è l'arrotondamento del motore: resta quota aperta
    $vicina = $colonna;
    $vicina['voci'][0]['importo_cents'] = 7501;
    DB::table('piani_rate')->where('id', $piano->id)->update(['quote_non_assegnate' => json_encode($vicina)]);
    expect($svc->statoAllineamento(PianoRate::find($piano->id)))->toBe('quota_aperta');

    // due centesimi con una voce sola non sono più un arrotondamento
    $oltre = $colonna;
    $oltre['voci'][0]['importo_cents'] = 7502;
    DB::table('piani_rate')->where('id', $piano->id)->update(['quote_non_assegnate' => json_encode($oltre)]);
    expect($svc->statoAllineamento(PianoRate::find($piano->id)))->toBe('disallineato');
});

it('Coda 414 — la tolleranza resta zero: un già versato di € 0,01 scritto dopo la generazione sposta lo scarto da € 75,00 a € 74,99, e il piano è disallineato', function () {
    $s = b59pScenarioV34($this);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, extra: b59pConPulizie($s));

    b52tGiaVersato($this, $s, $s['pulizie'], 'A', 1);

    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'disallineato', 'scarto_cents' => 7499, 'scarto_registrato_cents' => 7500]);
});

it('Coda 414 e Coda 433 — una quota non assegnata su un sottoconto si conta sotto il capitolo che il piano ha nella sua riga, con la sua chiave', function () {
    $s = b59pScenarioV34($this);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, procedi: false, extra: ['capitoli_ids' => [$s['pulizie']->id], 'capitoli_config' => [['id' => $s['pulizie']->id, 'importo' => '1.000,00', 'note' => '']]]);
    sleep(1); // il capitolo e il sottoconto nascono dopo il piano, come quelli che «Ricalcola con voci orfane» porta dentro (`b52tScenarioConPadre`)
    $padre = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Manutenzione', 'tipo' => 'spesa', 'importo' => 0]);
    $caldaia = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $padre->id, 'nome' => 'Caldaia', 'tipo' => 'spesa', 'importo' => 100000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $caldaia->id, 'tabella_id' => $s['risc']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'orphan_ids' => [$padre->id], 'accetta_scoperti' => true, 'nota_scoperti' => 'Cantina 1 senza proprietario al rogito',
    ])->assertSessionHasNoErrors();

    // la regola: la Cantina 1 ha 30 millesimi della caldaia da € 1.000,00, cioè € 30,00, registrati sul sottoconto
    $voce = b59pColonna($piano)['voci'][0];
    expect([$voce['conto_id'], $voce['importo_cents']])->toBe([$caldaia->id, 3000]);
    $capitoli = collect(b59pPagina($this, $s, $piano)['pianoRate']['capitoli'])->keyBy('nome');
    expect($capitoli['Manutenzione']['non_assegnati_cents'])->toBe(3000)
        ->and($capitoli['Pulizie']['non_assegnati_cents'])->toBe(0);

    // Coda 433: accanto alla cifra, le chiavi delle stesse quote, dalla stessa regola (la voce e i suoi sottoconti): la pagina trova con
    // queste la quota del sottoconto e nomina la sua unità sotto il capitolo
    expect($capitoli['Manutenzione']['non_assegnati_chiavi'])->toBe([$voce['chiave']])
        ->and($capitoli['Pulizie']['non_assegnati_chiavi'])->toBe([]);
});

it('Coda 414 — controprova: un piano senza rate non è disallineato (non è ancora generato)', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s, extra: ['genera_subito' => false]);

    expect(PianoRate::find($piano->id)->rate()->count())->toBe(0);
    expect(app(PianoRateQuoteService::class)->eDisallineato(PianoRate::find($piano->id)))->toBeFalse();
});

it('Coda 414 — un piano senza rate è allineato anche per lo stato a tre valori, e la pagina lo dice', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s, extra: ['genera_subito' => false]);

    expect(app(PianoRateQuoteService::class)->statoAllineamento(PianoRate::find($piano->id)))->toBe('allineato');
    expect(b59pPagina($this, $s, $piano)['allineamento'])->toBe(['stato' => 'allineato', 'atteso_cents' => 250000, 'generato_cents' => 0, 'scarto_cents' => 250000, 'scarto_registrato_cents' => null]);
});

it('Coda 414 e Coda 433 — l\'elenco dei piani non riceve le quote non assegnate né la cifra e le chiavi per voce, che legge solo la pagina del piano; la pagina del piano le riceve', function () {
    $s = b59pScenarioV34($this);
    $piano = b59pPianoV34($this, $s);

    $riga = collect(b59pProps($this, 'admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']])['pianiRate'])->firstWhere('id', $piano->id);

    expect($riga)->not->toBeNull();
    expect($riga)->not->toHaveKey('quote_non_assegnate');
    expect($riga['capitoli'])->not->toBeEmpty();
    foreach ($riga['capitoli'] as $voce) {
        expect($voce)->not->toHaveKey('non_assegnati_cents');
        expect($voce)->not->toHaveKey('non_assegnati_chiavi'); // Coda 433: nemmeno le chiavi per voce
    }

    // controprova: la pagina del piano le riceve, con la cifra per voce (€ 75,00 sulla voce «Riscaldamento») e, Coda 433, la chiave della Cantina 1
    $p = b59pPagina($this, $s, $piano)['pianoRate'];
    expect($p['quote_non_assegnate']['scarto_cents'])->toBe(7500);
    $riscaldamento = collect($p['capitoli'])->firstWhere('id', $s['riscaldamento']->id);
    expect($riscaldamento['non_assegnati_cents'])->toBe(7500);
    expect($riscaldamento['non_assegnati_chiavi'])->toBe([$p['quote_non_assegnate']['voci'][0]['chiave']]);
});

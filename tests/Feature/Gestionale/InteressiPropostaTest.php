<?php

/**
 * La proposta degli interessi di mora dalla rotta dell'elenco «Rate scadute», sul giornale vero di Via Verdi (`AccessoriScenario.php`): Neri, € 300,00
 * della rata 1 scaduta il 31/03/2026. Il tasso legale del 2026 è 1,60 % (`config/fiscale.php`); i giorni vanno dal giorno dopo la scadenza alla data della
 * proposta compresa, su 365. Ogni cifra è rifatta a mano accanto al suo caso.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegolaRecupero;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('Neri, € 100,00 versati il 30/04: al 29/06 la proposta ha due tratti, 30 giorni su € 300,00 e 60 su € 200,00, € 0,92; niente si scrive', function () {
    $s = accViaVerdi($this);
    expect((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'Neri', 1))->value('importo'))->toBe(30000);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');

    $this->travelTo('2026-06-29 10:00:00');
    $prima = DB::table('scritture_contabili')->count();
    $r = accProposta($this, $s, ['Neri'])->assertOk()->json();

    $neri = accDi($r, $s, 'Neri');
    // 300,00 × 1,60 % × 30/365 = 0,394521 + 200,00 × 1,60 % × 60/365 = 0,526027 = 0,920548 → € 0,92.
    expect($r['alla'])->toBe('2026-06-29')->and($neri['totale'])->toBe(92)->and($r['totale'])->toBe(92)
        ->and(count($neri['righe']))->toBe(1)
        ->and($neri['righe'][0]['scadenza'])->toBe('2026-03-31')->and($neri['righe'][0]['dal'])->toBe('2026-04-01')
        ->and($neri['righe'][0]['rata'])->toBe('Rata 1 — Preventivo 2026')->and($neri['righe'][0]['unita'])->not->toBeNull()
        ->and(array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['capitale'], $t['tasso'], $t['divisore'], $t['causa']], $neri['righe'][0]['tratti']))->toBe([
            ['2026-04-01', '2026-04-30', 30, 30000, '1,60 %', 365, 'scadenza'],
            ['2026-05-01', '2026-06-29', 60, 20000, '1,60 %', 365, 'versamento'],
        ])
        ->and($r['frase_domanda_giudiziale'])->toBe(RegoleDelRecupero::FRASE_DOMANDA_GIUDIZIALE)
        ->and($r['regole']['di_oggi'])->toBe(RegoleDelRecupero::FRASE_SENZA_REGOLE)
        ->and(DB::table('scritture_contabili')->count())->toBe($prima);

    // Senza il versamento sarebbero € 300,00 × 1,60 % × 90/365 = 1,183562 → € 1,18: lo dice Bianchi, con € 180,00 per 90 giorni: 0,710137 → € 0,71.
    $bianchi = accDi(accProposta($this, $s, ['Bianchi'])->json(), $s, 'Bianchi');
    expect($bianchi['totale'])->toBe(71)->and($bianchi['righe'][0]['tratti'][0]['giorni'])->toBe(90);
});

it('un versamento tornato indietro il 31/05 spezza il tratto: tre tratti, € 1,05; stornato come registrazione sbagliata il versamento non c\'è mai stato: € 1,18', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    $incasso = lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $this->travelTo('2026-05-31 10:00:00');
    lcdStorna($this, $s, $incasso);

    $this->travelTo('2026-06-29 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri');
    // 300,00 × 30/365 + 200,00 × 31/365 + 300,00 × 29/365, all'1,60 %: 0,394521 + 0,271781 + 0,381370 = 1,047672 → € 1,05.
    expect(array_map(fn ($t) => [$t['dal'], $t['giorni'], $t['capitale'], $t['causa']], $neri['righe'][0]['tratti']))->toBe([
        ['2026-04-01', 30, 30000, 'scadenza'], ['2026-05-01', 31, 20000, 'versamento'], ['2026-06-01', 29, 30000, 'storno'],
    ])->and($neri['totale'])->toBe(105);

    // Lo stesso versamento stornato come registrazione sbagliata: la rettifica ha la data del versamento, e i due si annullano quel giorno.
    $altro = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    $incasso = lcdIncassa($this, $altro, 'Neri', [lcdQuota($altro, 'Neri', 1) => 10000], '2026-04-30');
    $this->travelTo('2026-05-31 10:00:00');
    lcdStorna($this, $altro, $incasso, ['natura_storno' => 'errore_registrazione']);
    $this->travelTo('2026-06-29 10:00:00');
    expect(accDi(accProposta($this, $altro, ['Neri'])->json(), $altro, 'Neri')['totale'])->toBe(118);
});

it('€ 300,00 versati il 01/04 su una rata non ancora emessa coprono la rata scaduta dal 02/04: un giorno solo, € 0,01; il credito si dice accanto', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-01 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 2) => 30000], '2026-04-01');

    $this->travelTo('2026-06-29 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri');
    // 300,00 × 1,60 % × 1/365 = 0,013151 → € 0,01.
    expect($neri['totale'])->toBe(1)->and($neri['righe'][0]['tratti'][0]['giorni'])->toBe(1)->and($neri['crediti_sulle_rate'])->toBe(30000);
});

it('un credito sulla rata: la rata 2 versata con € 100,00 in più il 15/05 copre la rata 1 dal 16/05, € 0,99', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-01-15 10:00:00');
    lcdEmetti($this, $s, [2], '2026-01-15');
    $this->travelTo('2026-05-15 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 2) => 30000], '2026-05-15', 10000);

    $this->travelTo('2026-06-29 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri');
    // 300,00 × 1,60 % × 45/365 = 0,591781 + 200,00 × 1,60 % × 45/365 = 0,394521 = 0,986302 → € 0,99.
    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['capitale'], $t['causa']], $neri['righe'][0]['tratti']))->toBe([
        ['2026-04-01', '2026-05-15', 30000, 'scadenza'], ['2026-05-16', '2026-06-29', 20000, 'credito'],
    ])->and($neri['totale'])->toBe(99)->and($neri['crediti_sulle_rate'])->toBe(10000);
});

it('mai gli accessori nella base, sì i contributi: con € 50,00 di contributo e € 25,00 di sollecito scaduti il 31/03, € 1,18 + € 0,20', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-03-31 10:00:00');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-03-31']])->assertCreated();
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-03-31', 'natura' => 'accessorio',
        'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])->assertCreated();

    $this->travelTo('2026-06-29 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri');
    // Rata 1: 300,00 × 1,60 % × 90/365 = 1,183562 → 1,18; contributo: 50,00 × 1,60 % × 90/365 = 0,197260 → 0,20; il sollecito no.
    expect(array_map(fn ($r) => [$r['rata'], $r['interessi']], $neri['righe']))->toBe([['Rata 1 — Preventivo 2026', 118], ['Contributo cantina', 20]])
        ->and($neri['totale'])->toBe(138);
});

it('la quota di Ines, inquilina di U2, € 90,00 scaduti: non si propone, è contata a parte; Bianchi, proprietario, ha la sua', function () {
    $s = accViaVerdi($this, ['inquilino' => ['U2', 'Ines', 50, '2020-01-01', null]]);
    expect((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'Ines', 1))->value('importo'))->toBe(9000);

    $this->travelTo('2026-06-29 10:00:00');
    $r = accProposta($this, $s, ['Ines', 'Bianchi'])->json();
    $ines = accDi($r, $s, 'Ines');
    // Bianchi: 90,00 × 1,60 % × 90/365 = 0,355068 → € 0,36. Ines lo stesso, ma non si propone.
    expect($ines['righe'])->toBe([])->and($ines['totale'])->toBe(0)->and($ines['quote_di_inquilino'])->toBe(9000)
        ->and(accDi($r, $s, 'Bianchi')['totale'])->toBe(36)->and($r['totale'])->toBe(36);
});

it('le regole con la storia: gli interessi registrati al 29/06 non cambiano; con un tasso scritto del 3,00 % dal 01/07 la proposta al 31/07 riparte dal 30/06', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['totale'])->toBe(118);
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');

    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-07-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3,00', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento',
        'giorni_decorrenza' => 0,
    ])->assertSessionHasNoErrors();

    $this->travelTo('2026-07-31 10:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->json();
    // Un tasso dal regolamento: vale se il regolamento è contrattuale, e l'avviso lo dice.
    expect($proposta['regole']['avviso'])->toBe(RegoleDelRecupero::AVVISO_REGOLAMENTO);
    $neri = accDi($proposta, $s, 'Neri');
    // Il 30/06 all'1,60 % e 31 giorni di luglio al 3,00 %: 300,00 × 1,60 % × 1/365 = 0,013151 + 300,00 × 3,00 % × 31/365 = 0,764384 = 0,777535 → € 0,78.
    expect(array_map(fn ($t) => [$t['dal'], $t['al'], $t['tasso'], $t['fonte'], $t['causa']], $neri['righe'][0]['tratti']))->toBe([
        ['2026-06-30', '2026-06-30', '1,60 %', 'tasso legale', 'scadenza'], ['2026-07-01', '2026-07-31', '3,00 %', 'art. 12 del regolamento', 'regola'],
    ])->and($neri['righe'][0]['gia_registrati_al'])->toBe('2026-06-29')->and($neri['totale'])->toBe(78)
        ->and((int) RataQuote::where('rata_id', $rataId)->value('importo'))->toBe(118);

    // Senza gli interessi registrati, la stessa proposta: 300,00 × 1,60 % × 91/365 = 1,196712 + 0,764384 = 1,961096 → € 1,96.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rataId]))->assertOk();
    expect(accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri')['totale'])->toBe(196);
});

it('un tasso scritto da una delibera ha l\'avviso, mai un blocco; la regola usata non si elimina, quella mai usata sì', function () {
    $s = accViaVerdi($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-04-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '5', 'fonte' => 'delibera', 'riferimento' => 'delibera del 14/03/2026, verbale n. 1',
    ])->assertSessionHasNoErrors();
    $regola = RegolaRecupero::where('condominio_id', $s['c']->id)->firstOrFail();
    expect($regola->tasso_annuo)->toBe('5.0000');

    $this->travelTo('2026-06-29 10:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->assertOk()->json();
    // 300,00 × 5,00 % × 90/365 = 3,698630 → € 3,70: la delibera non ferma niente, lo dice.
    expect($proposta['totale'])->toBe(370)->and($proposta['regole']['avviso'])->toBe(RegoleDelRecupero::AVVISO_DELIBERA);

    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    $dati = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati(RataQuote::where('rata_id', $rataId)->firstOrFail());
    expect([$dati['tipo'], $dati['base'], $dati['riferimento']])->toBe(['interessi', 'delibera', 'delibera del 14/03/2026, verbale n. 1'])
        ->and(app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class)->dellaPersona($s['c']->id, $s['persone']['Neri']->id)[0]['avvisi'])
        ->toBe([RegoleDelRecupero::AVVISO_DELIBERA]);

    // Usata dagli interessi del 29/06: resta.
    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$s['c'], $regola->id]))->assertSessionHasErrors('regola');
    expect(RegolaRecupero::count())->toBe(1);

    // Una regola mai usata si elimina; quella di un altro condominio è un 404.
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-09-01', 'tasso_tipo' => 'legale'])->assertSessionHasNoErrors();
    $nuova = RegolaRecupero::where('condominio_id', $s['c']->id)->whereDate('dal', '2026-09-01')->firstOrFail();
    $altro = \App\Models\Condominio::factory()->create();
    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$altro, $nuova->id]))->assertNotFound();
    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$s['c'], $nuova->id]))->assertSessionHasNoErrors();
    expect(RegolaRecupero::count())->toBe(1);

    // Una regola con lo stesso giorno di inizio non si scrive: una regola non si riscrive.
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-04-01', 'tasso_tipo' => 'legale'])->assertSessionHasErrors('dal');
});

it('una regola scritta dopo con il giorno di inizio nel passato non è «usata» dagli interessi registrati con il tasso di prima: lo dice, si elimina e si riscrive', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    $dati = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati(RataQuote::where('rata_id', $rataId)->firstOrFail());
    // Il tasso legale senza regole: nessuna regola dietro il tratto.
    expect(array_column($dati['interessi']['righe'][0]['tratti'], 'regola_id'))->toBe([null]);

    // Il 02/07 una regola dal 01/04, con la misura sbagliata: 50 invece di 5. Registrata, con l'avviso dei giorni già registrati.
    $this->travelTo('2026-07-02 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-04-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '50', 'fonte' => 'regolamento', 'riferimento' => 'art. 12',
    ])->assertSessionHasNoErrors()->assertSessionHas('message', fn ($m) => $m['type'] === 'warning'
        && str_contains($m['message'], 'Dal 01/04/2026 al 29/06/2026 ci sono interessi registrati con il tasso di prima: restano come sono.'));
    $regola = RegolaRecupero::where('condominio_id', $s['c']->id)->firstOrFail();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()->viewData('page')['props']['regole'];
    expect(array_column($pagina, 'usata'))->toBe([null]);

    // Si elimina, e la stessa regola con la misura giusta si scrive dal 01/04.
    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$s['c'], $regola->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-04-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '5', 'fonte' => 'regolamento', 'riferimento' => 'art. 12',
    ])->assertSessionHasNoErrors();
    expect(RegolaRecupero::count())->toBe(1);

    // Usata davvero: gli interessi di Bianchi registrati al 02/07 con il 5 %. 180,00 × 5,00 % × 93/365 = 2,293151 → € 2,29.
    $p = accProposta($this, $s, ['Bianchi'])->json();
    expect(accDi($p, $s, 'Bianchi')['totale'])->toBe(229);
    accRegistra($this, $s, $p)->assertCreated();
    $nuova = RegolaRecupero::where('condominio_id', $s['c']->id)->firstOrFail();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()->viewData('page')['props']['regole'];
    expect(array_column($pagina, 'usata'))->toBe(['2026-07-02']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$s['c'], $nuova->id]))->assertSessionHasErrors('regola');
});

it('una regola che sposta il primo giorno con i giorni di decorrenza è usata dagli interessi che ha spostato, anche senza un loro tratto', function () {
    $s = accViaVerdi($this);
    // Dal 01/04 dieci giorni di decorrenza; dal 05/04 un'altra regola, con il tasso legale: i tratti sono suoi.
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-04-01', 'tasso_tipo' => 'legale', 'giorni_decorrenza' => 10])
        ->assertSessionHasNoErrors()->assertSessionHas('message', fn ($m) => $m['type'] === 'success');
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-04-05', 'tasso_tipo' => 'legale'])->assertSessionHasNoErrors();
    $this->travelTo('2026-06-29 10:00:00');
    $p = accProposta($this, $s, ['Neri'])->json();
    // Dal 11/04 al 29/06, 80 giorni: 300,00 × 1,60 % × 80/365 = 1,052055 → € 1,05.
    expect(accDi($p, $s, 'Neri')['totale'])->toBe(105);
    accRegistra($this, $s, $p)->assertCreated();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()->viewData('page')['props']['regole'];
    expect(array_map(fn ($r) => [$r['dal'], $r['usata']], $pagina))->toBe([['2026-04-05', '2026-06-29'], ['2026-04-01', '2026-06-29']]);
});

it('un tasso scritto con la fonte «altro»: la base degli interessi è «altro», con il suo riferimento; nessun avviso', function () {
    $s = accViaVerdi($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), [
        'dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '2', 'fonte' => 'altro', 'riferimento' => 'accordo scritto con il condòmino del 10/01/2026',
    ])->assertSessionHasNoErrors();
    $this->travelTo('2026-06-29 10:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->json();
    expect($proposta['regole']['avviso'])->toBeNull();
    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    $dati = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati(RataQuote::where('rata_id', $rataId)->firstOrFail());
    expect([$dati['base'], $dati['riferimento']])->toBe(['altro', 'accordo scritto con il condòmino del 10/01/2026'])
        ->and(\App\Services\Gestionale\Accessori\BaseDegliAccessori::testo($dati['base'], $dati['riferimento']))->toBe('altro, accordo scritto con il condòmino del 10/01/2026');
});

it('l\'arrotondamento è rata per rata, anche nel totale e nella rata registrata: al 06/07 € 1,28 + € 0,08 = € 1,36, non € 1,35 del totale arrotondato', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-01-15 10:00:00');
    lcdEmetti($this, $s, [2], '2026-01-15');
    $this->travelTo('2026-07-06 10:00:00');
    $p = accProposta($this, $s, ['Neri'])->json();
    $neri = accDi($p, $s, 'Neri');
    // 300,00 × 1,60 % × 97/365 = 1,275616 → 1,28; 300,00 × 1,60 % × 6/365 = 0,078904 → 0,08; arrotondato sul totale, 1,354521 → 1,35.
    expect(array_column($neri['righe'], 'interessi'))->toBe([128, 8])->and($neri['totale'])->toBe(136)->and($p['totale'])->toBe(136);
    $rataId = accRegistra($this, $s, $p)->assertCreated()->json('rate.0');
    expect((int) RataQuote::where('rata_id', $rataId)->value('importo'))->toBe(136);
});

it('un versamento registrato dopo con una data di prima cambia i giorni già registrati: la riga lo dice, gli interessi registrati restano', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();

    // Il 05/07 si registra un bonifico di € 100,00 arrivato il 30/04: dal 01/05 al 29/06 il capitale era € 200,00, e quei giorni darebbero € 0,92.
    $this->travelTo('2026-07-05 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $this->travelTo('2026-07-31 10:00:00');
    $riga = accDi(accProposta($this, $s, ['Neri'])->json(), $s, 'Neri')['righe'][0];
    // Dal 30/06 al 31/07, 32 giorni su € 200,00: 200,00 × 1,60 % × 32/365 = 0,280548 → € 0,28.
    expect($riga['interessi'])->toBe(28)->and($riga['riallineo'])
        ->toBe('Gli interessi registrati al 29/06/2026 erano € 1,18; con il giornale di oggi quei giorni darebbero € 0,92: per cambiarli, annulla l\'addebito e rifai la proposta.');

    // Senza versamenti di prima, niente da dire.
    $b = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $b, accProposta($this, $b, ['Bianchi'])->json())->assertCreated();
    $this->travelTo('2026-07-31 10:00:00');
    expect(accDi(accProposta($this, $b, ['Bianchi'])->json(), $b, 'Bianchi')['righe'][0]['riallineo'])->toBeNull();
});

it('la quota di un proprietario con la riga di titolarità spenta a mano resta la sua: il ruolo è quello del riparto della quota, non della titolarità di oggi', function () {
    $s = accViaVerdi($this, ['inquilino' => ['U2', 'Ines', 50, '2020-01-01', null]]);
    DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['Bianchi']->id)->update(['attivo' => false]);
    $this->travelTo('2026-06-29 10:00:00');
    $r = accProposta($this, $s, ['Ines', 'Bianchi'])->json();
    // Bianchi: 90,00 × 1,60 % × 90/365 = 0,355068 → € 0,36, come con la riga accesa; Ines resta inquilina.
    expect(accDi($r, $s, 'Bianchi')['totale'])->toBe(36)->and(accDi($r, $s, 'Bianchi')['quote_di_inquilino'])->toBe(0)
        ->and(accDi($r, $s, 'Ines')['quote_di_inquilino'])->toBe(9000);
});

it('registrati: una rata per persona e unità «Interessi di mora al 29/06/2026», DARE crediti con persona, unità e rata, AVERE «Accessori da incassare»; una seconda proposta alla stessa data è vuota', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $this->travelTo('2026-06-29 10:00:00');
    $proposta = accProposta($this, $s, ['Neri'])->json();

    // Le cifre viste non sono quelle di oggi: niente si scrive.
    $sbagliata = $proposta;
    $sbagliata['totale'] = 93;
    accRegistra($this, $s, $sbagliata)->assertStatus(422);
    expect(\App\Models\Gestionale\PianoRate::degliAddebiti()->count())->toBe(0);

    $rataId = accRegistra($this, $s, $proposta)->assertCreated()->json('rate.0');
    $rata = \App\Models\Gestionale\Rata::with('rateQuote')->findOrFail($rataId);
    $q = $rata->rateQuote->first();
    $dati = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($q);
    expect([$rata->descrizione, (int) $rata->importo_totale, $rata->data_scadenza->format('Y-m-d'), $q->tipo])->toBe(['Interessi di mora al 29/06/2026', 92, '2026-06-29', 'accessorio'])
        ->and([$dati['natura'], $dati['tipo'], $dati['base'], (int) $dati['conto_id'], $dati['interessi']['alla']])->toBe(['accessorio', 'interessi', 'legge', $s['interessi']->id, '2026-06-29'])
        ->and(count($dati['interessi']['righe'][0]['tratti']))->toBe(2)
        ->and(accEmissione($rataId))->toBe([
            ['crediti_condomini', 'dare', 92, true, true, true],
            ['accessori_da_incassare', 'avere', 92, false, false, false],
        ]);

    // La lettura comune lo vede come una rata; la proposta di nuovo al 29/06 non ha più niente da chiedere.
    $this->travelTo('2026-06-30 10:00:00');
    $lettura = lcdLeggi($s, 'Neri');
    expect(collect($lettura['rate'])->firstWhere('rata_id', $rataId)['residuo'])->toBe(92);
    expect(accDi(accProposta($this, $s, ['Neri'], '2026-06-29')->json(), $s, 'Neri'))->toBeNull();

    // Una persona di un altro condominio fa 404 per tutta la richiesta.
    $altra = \App\Models\Anagrafica::forceCreate(['nome' => 'Estranea', 'email' => 'estranea@test.it', 'indirizzo' => 'x', 'codice_fiscale' => 'ESTRANEA00000001']);
    $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.interessi.proposta', [$s['c']]) . '?persone[]=' . $altra->id)->assertNotFound();
});

it('gli interessi non si registrano dalla finestra degli addebiti: si propongono, con il calcolo', function () {
    $s = accViaVerdi($this);
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '0,92', 'natura' => 'accessorio', 'tipo_accessorio' => 'interessi', 'base' => 'legge',
        'conto_id' => $s['interessi']->id]])->assertStatus(422)->assertJsonValidationErrors('voci.0.tipo_accessorio');
});

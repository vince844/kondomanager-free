<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Il contenuto della ricevuta di un incasso (decisioni 197 e 200): che cosa la ricevuta dice, al centesimo, letto da ciò che l'incasso ha
 * scritto (la pivot `quota_scrittura`, le righe, le scritture figlie del credito). Le cifre attese sono fatte a mano nel test.
 *
 * Che cosa NON copre: il PDF (in `RicevutaIncassoStampaTest`), l'invio (in `RicevutaIncassoInvioTest`).
 */
function ricevutaDi($incasso): array
{
    return app(RicevutaIncasso::class)->per($incasso->fresh());
}

test('due quote su due rate, una già pagata in parte: importo, già versato prima, imputato e residuo dopo, quota per quota', function () {
    $s = scenarioRicevutaIncasso();

    // Il 10/01 Anna versa € 50,00 sulla rata 1 (€ 150,00).
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 50.00]], '2026-01-10', 50.00);
    // Il 15/04 versa € 180,50: € 100,00 chiudono la rata 1, € 80,50 vanno sulla rata 2 (€ 120,50), che resta aperta per € 40,00.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 100.00], [$s->anna2, 80.50]], '2026-04-15', 180.50);

    $r = ricevutaDi($incasso);

    expect($r['numero'])->toBe($incasso->numero_protocollo)
        ->and($r['numero'])->toStartWith('INC-')
        ->and($r['data_versamento'])->toBe('2026-04-15')
        ->and($r['importo_versato'])->toBe(18050)
        ->and($r['sopra_soglia'])->toBeTrue()
        ->and($r['annullato'])->toBeFalse()
        ->and($r['posizione']['nome'])->toBe('Anna Bruni')
        ->and($r['versato_da'])->toBeNull()
        ->and($r['cassa'])->toBe(['nome' => 'Conto BPER', 'tipo' => 'Conto corrente'])
        ->and($r['condominio']['codice_fiscale'])->toBe('90012345678')
        ->and($r['credito_usato'])->toBe(0)
        ->and($r['eccedenza'])->toBe(0);

    expect($r['quote'])->toHaveCount(2);
    [$prima, $seconda] = $r['quote'];

    expect($prima['rata'])->toBe('Rata 1')
        ->and($prima['piano'])->toBe('Preventivo 2026')
        ->and($prima['gestione'])->toBe('Ordinaria 2026')
        ->and($prima['unita'])->toBe('Int. 4')
        ->and($prima['scadenza'])->toBe('2026-01-31')
        ->and([$prima['importo'], $prima['prima'], $prima['imputato'], $prima['dopo']])->toBe([15000, 5000, 10000, 0]);

    expect($seconda['rata'])->toBe('Rata 2')
        ->and([$seconda['importo'], $seconda['prima'], $seconda['imputato'], $seconda['dopo']])->toBe([12050, 0, 8050, 4000]);

    expect($r['totali'])->toBe(['importo' => 27050, 'prima' => 5000, 'imputato' => 18050, 'dopo' => 4000]);
});

// Il già versato prima segue l'ordine di registrazione, quello con cui il programma ha imputato il denaro (decisione 202): qui la data del
// versamento e la registrazione vanno nello stesso ordine; il caso fuori ordine è nel test che segue.
test('il già versato prima conta gli incassi registrati prima di questo, e un incasso annullato non conta', function () {
    $s = scenarioRicevutaIncasso();

    // € 30,00 il 05/02, poi annullato; € 20,00 il 10/02; la ricevuta guardata è quella del 20/02 (€ 40,00).
    $annullato = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 30.00]], '2026-02-05', 30.00);
    app(StornoIncassoRateAction::class)->execute($annullato->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 20.00]], '2026-02-10', 20.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 40.00]], '2026-02-20', 40.00);
    // Un incasso dopo, che non deve entrare nel «prima».
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 10.00]], '2026-03-01', 10.00);

    $quota = ricevutaDi($incasso)['quote'][0];

    expect([$quota['importo'], $quota['prima'], $quota['imputato'], $quota['dopo']])->toBe([15000, 2000, 4000, 9000])
        ->and(ricevutaDi($incasso)['sopra_soglia'])->toBeFalse();
});

test('«Versato da» un\'altra persona: la ricevuta dice chi ha versato e per conto di chi', function () {
    $s = scenarioRicevutaIncasso();

    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['versato_da_id' => $s->carlo->id]);

    $r = ricevutaDi($incasso);

    expect($r['posizione']['nome'])->toBe('Anna Bruni')
        ->and($r['posizione']['id'])->toBe($s->anna->id)
        ->and($r['versato_da'])->toBe(['id' => $s->carlo->id, 'nome' => 'Carlo Bruni'])
        ->and($r['quote'])->toHaveCount(1)
        ->and($r['quote'][0]['imputato'])->toBe(15000);
});

test('l\'eccedenza: la parte in più va a credito sulla rata e la ricevuta dice quanto e di chi', function () {
    $s = scenarioRicevutaIncasso();

    // € 170,00 su una rata da € 150,00: € 20,00 in più restano a credito di Anna.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);

    $r = ricevutaDi($incasso);

    expect($r['importo_versato'])->toBe(17000)
        ->and($r['quote'])->toHaveCount(1)
        ->and([$r['quote'][0]['importo'], $r['quote'][0]['prima'], $r['quote'][0]['imputato'], $r['quote'][0]['dopo']])->toBe([15000, 0, 15000, 0])
        ->and($r['eccedenza'])->toBe(2000)
        ->and($r['eccedenze'])->toBe([['importo' => 2000, 'persona' => 'Anna Bruni', 'rata' => 'Rata 1, Preventivo 2026', 'riduce' => 0, 'a_credito' => 2000, 'di_chi_ha_versato' => false]]);
});

test('il credito usato: la quota coperta dal credito di un\'altra rata, e la ricevuta dice quanto credito e da quale rata', function () {
    $s = scenarioRicevutaIncasso();

    // Il 01/02 € 170,00 sulla rata 1: € 20,00 restano a credito. Il 01/04 € 100,50 e il credito pagano la rata 2 (€ 120,50).
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, -20.00], [$s->anna2, 120.50]], '2026-04-01', 100.50);

    $r = ricevutaDi($incasso);

    expect($r['importo_versato'])->toBe(10050)
        ->and($r['credito_usato'])->toBe(2000)
        // Il credito è della posizione stessa: la riga non nomina nessun'altra persona.
        ->and($r['crediti'])->toBe([['importo' => 2000, 'rata' => 'Rata 1, Preventivo 2026', 'persona' => null]])
        ->and($r['eccedenza'])->toBe(0)
        ->and($r['quote'])->toHaveCount(1)
        ->and($r['quote'][0]['rata'])->toBe('Rata 2')
        ->and([$r['quote'][0]['importo'], $r['quote'][0]['prima'], $r['quote'][0]['imputato'], $r['quote'][0]['dopo']])->toBe([12050, 0, 12050, 0]);
});

test('un incasso annullato: la ricevuta lo dice, con il protocollo e la data dello storno, e le quote restano quelle che l\'incasso aveva coperto', function () {
    $s = scenarioRicevutaIncasso();

    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);

    $r = ricevutaDi($incasso);

    expect($r['annullato'])->toBeTrue()
        ->and($r['storno']['protocollo'])->toStartWith('RET-')
        ->and($r['storno']['data'])->toBe(now()->setTimezone(config('app.user_timezone'))->format('Y-m-d'))
        ->and($r['quote'][0]['imputato'])->toBe(15000);
});

test('un incasso registrato dopo con una data prima non cambia la ricevuta di quello registrato prima, e la sua parte in più non entra nel già versato', function () {
    $s = scenarioRicevutaIncasso();

    // Registrato per primo: € 60,00 del 01/03 sulla rata 1 (€ 150,00).
    $b = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 60.00]], '2026-03-01', 60.00);
    // Registrato dopo, con la data prima: € 130,00 del 01/02. Il programma imputa € 90,00 alla rata (il residuo dopo B) e € 40,00 a credito.
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), 90.00]], '2026-02-01', 130.00);

    $rb = ricevutaDi($b);
    $ra = ricevutaDi($a);

    expect(cifreDellaQuota($rb['quote'][0]))->toBe([15000, 0, 6000, 9000])
        ->and(cifreDellaQuota($ra['quote'][0]))->toBe([15000, 6000, 9000, 0])
        ->and($ra['eccedenza'])->toBe(4000)
        ->and($ra['eccedenze'][0])->toMatchArray(['persona' => 'Anna Bruni', 'a_credito' => 4000, 'riduce' => 0]);
});

test('una riga a importo parziale con la parte in più sulla stessa rata: la ricevuta imputa tutto alla rata, come l\'estratto conto, e niente va a credito', function () {
    $s = scenarioRicevutaIncasso();

    // € 120,00 versati, € 100,00 messi a mano sulla rata 1 (€ 150,00): i € 20,00 in più restano sulla stessa rata, che non è saldata.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 100.00]], '2026-02-01', 120.00);

    $r = ricevutaDi($incasso);
    $quota = $s->anna1->fresh();

    expect(cifreDellaQuota($r['quote'][0]))->toBe([15000, 0, 12000, 3000])
        ->and($quota->importo - $quota->importo_pagato)->toBe(3000)
        ->and($r['eccedenza'])->toBe(0)
        ->and($r['eccedenze'])->toBe([]);
});

test('chi versa per un altro più del debito: la parte in più che riduce una sua rata da pagare si dice «riduce», non «a credito»', function () {
    $s = scenarioRicevutaIncasso();
    $carlo3 = quotaDaPagareDiCarlo($s);

    // Carlo versa € 200,00 per Anna: € 150,00 chiudono la rata 1 di Anna, € 50,00 vanno sulla rata 3 di Carlo (€ 100,00), che scende a € 50,00.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 200.00, ['versato_da_id' => $s->carlo->id]);

    $r = ricevutaDi($incasso);

    expect($carlo3->fresh()->importo_pagato)->toBe(5000)
        ->and($r['posizione']['id'])->toBe($s->anna->id)
        ->and($r['quote'])->toHaveCount(1)
        ->and(cifreDellaQuota($r['quote'][0]))->toBe([15000, 0, 15000, 0])
        ->and($r['eccedenza'])->toBe(5000)
        ->and($r['eccedenze'])->toBe([['importo' => 5000, 'persona' => 'Carlo Bruni', 'rata' => 'Rata 3, Preventivo 2026', 'riduce' => 5000, 'a_credito' => 0, 'di_chi_ha_versato' => true]]);

    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => $r, 'bollo' => 'assolta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();
    expect($html)->toContain('riduce la sua Rata 3, Preventivo 2026')
        ->and($html)->not->toContain('a credito di Carlo Bruni');
});

test('il credito della posizione che copre da solo tutto il debito, con il versamento tutto a chi ha versato: la ricevuta è di chi ha versato, per la sua rata, e la compensazione dell\'altra persona non c\'è', function () {
    $s = scenarioRicevutaIncasso();
    $carlo3 = quotaDaPagareDiCarlo($s);

    // Anna ha € 20,00 di credito sulla rata 1. Carlo versa € 50,00 per lei, «il credito si usa adesso»: il credito copre la rata 2 di Anna
    // (una riga di € 20,00), e i € 50,00 di Carlo vanno tutti sulla sua rata 3 (€ 100,00).
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 50.00,
        ['versato_da_id' => $s->carlo->id, 'credito_prima' => true]);

    $r = ricevutaDi($incasso);

    expect($carlo3->fresh()->importo_pagato)->toBe(5000)
        ->and($r['posizione']['id'])->toBe($s->carlo->id)
        ->and($r['versato_da'])->toBeNull()
        ->and($r['quote'])->toHaveCount(1)
        ->and($r['quote'][0]['quota_id'])->toBe($carlo3->id)
        ->and($r['quote'][0]['unita'])->toBe('Int. 9')
        ->and(cifreDellaQuota($r['quote'][0]))->toBe([10000, 0, 5000, 5000])
        ->and($r['credito_usato'])->toBe(0)
        ->and($r['crediti'])->toBe([])
        ->and($r['eccedenza'])->toBe(0)
        ->and($r['compensazioni_escluse'])->toBe([['persona' => 'Anna Bruni', 'importo' => 2000]]);

    // A chi va: solo Carlo, la posizione di questa ricevuta. Le rate di Anna non ci sono.
    $destinatari = app(\App\Services\Gestionale\Ricevute\EmissioneRicevute::class)->destinatari($r);
    expect(collect($destinatari)->pluck('anagrafica_id')->all())->toBe([$s->carlo->id]);
    // La finestra lo dice: il credito di Anna ha coperto la sua rata nello stesso incasso, e non è in questa ricevuta.
    expect($this->actingAs(amministratoreRicevute())->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->json('compensazioni_escluse'))->toBe([['persona' => 'Anna Bruni', 'importo' => 2000]]);
});

test('una quota tornata a debito oltre il suo importo: il già versato prima si mostra a zero, e la ricevuta dice quanto era tornato a debito', function () {
    $s = scenarioRicevutaIncasso();

    // € 170,00 sulla rata 1 (€ 150,00): € 20,00 a credito, poi usati sulla rata 2; poi il primo incasso si storna: la rata 1 torna a debito per € 170,00.
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 120.50]], '2026-03-01', 100.50);
    app(StornoIncassoRateAction::class)->execute($a->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    $d = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), 170.00]], '2026-04-01', 170.00);

    $q = ricevutaDi($d)['quote'][0];

    expect(cifreDellaQuota($q))->toBe([15000, 0, 17000, 0])
        ->and($q['riaddebito'])->toBe(2000);

    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => ricevutaDi($d), 'bollo' => 'assolta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();
    expect($html)->toContain('tornati a debito € 20,00');
});

test('il versamento e il credito usato sulla stessa quota: la riga dice quanto viene dal credito, e il titolo dice tutti e due', function () {
    $s = scenarioRicevutaIncasso();
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, -20.00], [$s->anna2, 120.50]], '2026-04-01', 100.50);

    $r = ricevutaDi($incasso);
    expect($r['quote'][0]['dal_credito'])->toBe(2000)
        ->and($r['importo_versato'] + $r['credito_usato'])->toBe($r['totali']['imputato'] + $r['eccedenza']);

    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => $r, 'bollo' => 'assolta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();
    expect($html)->toContain('Quote a cui sono imputati il versamento e il credito usato')
        ->and($html)->toContain('di cui dal credito € 20,00');
});

test('il credito di un comproprietario usato per le rate della posizione: la ricevuta dice di chi è il credito', function () {
    $s = scenarioRicevutaIncasso();
    $marco = \App\Models\Anagrafica::create(['nome' => 'Marco Bruni', 'email' => 'marco@prova.it', 'indirizzo' => 'Via dei Pini 12', 'codice_fiscale' => 'MRCBRN80A01L219X']);
    $marco->condomini()->attach($s->condominio->id);
    $s->int4->anagrafiche()->attach($marco->id, ['tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $marco1 = \App\Models\Gestionale\RataQuote::create(['rata_id' => $s->rate[1]->id, 'anagrafica_id' => $marco->id, 'immobile_id' => $s->int4->id,
        'importo' => 5000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $s->rate[1]->data_scadenza]);
    // Marco versa € 80,00 sulla sua quota da € 50,00: € 30,00 restano a credito. Anna paga la rata 2 (€ 120,50) con quel credito e € 90,50.
    incassaPerRicevuta($s, $marco, [[$marco1, 50.00]], '2026-01-20', 80.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$marco1->fresh(), -30.00], [$s->anna2, 120.50]], '2026-02-01', 90.50);

    $r = ricevutaDi($incasso);
    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => $r, 'bollo' => 'assolta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();

    expect($r['posizione']['id'])->toBe($s->anna->id)
        ->and($r['credito_usato'])->toBe(3000)
        ->and($r['crediti'])->toBe([['importo' => 3000, 'rata' => 'Rata 1, Preventivo 2026', 'persona' => 'Marco Bruni']])
        ->and($r['compensazioni_escluse'])->toBe([])
        ->and($html)->toContain('dal credito di Marco Bruni, sulla Rata 1, Preventivo 2026')
        ->and($html)->not->toContain('dal credito della Rata 1');
});

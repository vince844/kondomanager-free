<?php

/**
 * Il foglio della persona, il conguaglio per persona, i crediti per anzianità e il frontespizio letti da chi li riceve, il condòmino: che cosa deve già
 * e che cosa è solo proposto, in due righe distinte; le rate non ancora chieste dette con quello che succede quando si chiedono; le spese non ancora
 * divise; il perché senza le istruzioni per l'amministratore; nessun «programma» e nessun altro programma nominato; i controlli in parole sue; la
 * colonna dell'ultima lettera di sollecito che nel fascicolo si sceglie; i fogli «a debito» che dicono chi resta fuori.
 *
 * Le cifre sono rifatte a mano, in centesimi; positivo = da versare.
 */

use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\FogliDelConguaglio;
use App\Services\Gestionale\ParoleDelConguaglio;
use App\Services\Gestionale\PosizioniDeiCondomini;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Il testo della stampa per persona e del foglio di Bice di Via Roma al 15/01/2027. */
function pcdViaRoma($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e'], [], true);
    $fuori = app(\App\Services\Gestionale\RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e'])['fuori_capitoli'];
    $righe = $c->righe($s['c'], $s['e'], $letto, $fuori, true);
    $foglio = app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $letto, $s['persone']['bice']->id);

    return [$s, $righe, $foglio,
        vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render()),
        vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $foglio])->render())];
}

it('il foglio divide quello che è già da versare dalla proposta da approvare, e il totale è il saldo se l\'assemblea approva: Bice € 410,00, € −65,75, € 344,25', function () {
    [, , $f, , $foglio] = pcdViaRoma($this);

    // Bice: tre rate scadute non pagate, € 67,50 + € 171,25 + € 171,25 = € 410,00; la proposta 1.989,25 − 2.055,00 = −65,75; 410,00 − 65,75 = 344,25.
    expect([$f['totale']['gia'], $f['totale']['conguaglio'], $f['totale']['saldo']])->toBe([41000, -6575, 34425])
        ->and($foglio)->toContain('Già da versare da versare € 410,00 rate già chieste e non pagate')
        ->toContain('Proposta di conguaglio, da approvare a credito € 65,75 solo se l\'assemblea approva')
        ->toContain('Saldo se l\'assemblea approva da versare € 344,25')
        ->toContain('= Proposta di conguaglio, da approvare € -65,75')
        ->toContain('Già da versare: rate già chieste e non pagate € 410,00')
        ->toContain('= Saldo se l\'assemblea approva € +344,25')
        ->not->toContain('Saldo finale')->not->toContain('Posizione sulle rate');
});

it('il conguaglio per persona ha la stessa forma: già da versare, la proposta da approvare, il saldo se l\'assemblea approva', function () {
    [, $righe, , $persona] = pcdViaRoma($this);

    foreach (['Proposta di conguaglio, da approvare', 'Già da versare', 'Saldo se l\'assemblea approva'] as $colonna) {
        expect($persona)->toContain($colonna);
    }
    expect($persona)->not->toContain('Saldo finale')->not->toContain('Posizione sulle rate')->not->toContain('Saldi fuori dalle rate')
        // Il totale: già da versare € 410,00, la proposta € −131,50, il saldo se l'assemblea approva € 278,50.
        ->toContain('Totale € 3.978,50 € 0,00 € 4.110,00 € -131,50 € 410,00 € +278,50');
});

it('le rate non ancora chieste: una riga nel foglio, con il saldo quando saranno chieste; nella testata, accanto al saldo', function () {
    // Via Verdi con la rata 4 non emessa: Neri ha la proposta € 100,00 e il saldo € 100,00; € 500,00 di rate non ancora chieste: chieste, il saldo è
    // 100,00 + 500,00 = 600,00.
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [1, 2, 3], ['Neri' => [1 => null, 2 => null, 3 => null]]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $f = app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $c->leggi($s['e'], [], true), $s['persone']['Neri']->id);
    $foglio = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $f])->render());

    expect([$f['totale']['saldo'], $f['totale']['non_emesse']])->toBe([10000, 50000])
        ->and($foglio)->toContain('Rate dei piani non ancora chieste: quando saranno chieste, il saldo se l\'assemblea approva diventa da versare € 600,00 € 500,00')
        ->toContain('quando saranno chieste le rate che mancano (€ 500,00): da versare € 600,00')
        ->not->toContain('più le rate non ancora emesse');
});

it('il foglio con un\'unità non ancora calcolata lo dice senza il programma; le rate già chieste restano da versare', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli']);
    $registro = $passaggio->registro;
    unset($registro['regolato_fuori']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $f = app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $c->leggi($s['e'], [], true), $s['persone']['Bianchi']->id);
    $foglio = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $f])->render());

    // Lo studio può non esserci nelle impostazioni: la cifra la manda l'amministratore.
    expect($foglio)->toContain('Il conguaglio di questa unità non è ancora calcolato: l\'amministratore le manderà la cifra.')->not->toContain('lo studio')
        ->toContain('Proposta di conguaglio, da approvare non ancora calcolato')
        ->toContain('Già da versare')->not->toContain('programma')->not->toContain('non disponibile');
});

it('le spese non ancora divise fra i condòmini: nel conguaglio per persona e nel foglio, con la cifra e che il conguaglio può cambiare', function () {
    [, , , $persona, $foglio] = pcdViaRoma($this);

    // Via Roma: la fattura contestata € 150,00 e le commissioni bancarie senza voce € 2,50, fuori dai capitoli: € 152,50.
    expect($persona)->toContain('Queste cifre non comprendono € 152,50 di spese non ancora divise fra i condòmini (fatture contestate, commissioni bancarie): quando saranno divise, il conguaglio può cambiare.')
        ->and($foglio)->toContain('Le spese dell\'esercizio divise fra i condòmini sono € 3.978,50. Altre € 152,50 (fatture contestate, commissioni bancarie) non sono ancora divise: quando lo saranno, il conguaglio può cambiare.');
});

it('il perché delle spese non divise è per il condòmino: la causa, senza l\'istruzione; una frase per ogni motivo della scheda', function () {
    [, , , $persona] = pcdViaRoma($this);
    $istruzioni = '/\b(Collega|Scrivi|Correggi|Correggile|Correggili|Aggiungi|Ripristina|Ripristinala|Controlla|Assegna|Guarda|Sposta)\b/u';

    foreach (ConguaglioDelRendiconto::MOTIVI as $codice => $frase) {
        expect(ParoleDelConguaglio::MOTIVI)->toHaveKey($codice)
            ->and(ParoleDelConguaglio::perche($frase))->toBe(ParoleDelConguaglio::MOTIVI[$codice])
            ->and(preg_match($istruzioni, ParoleDelConguaglio::MOTIVI[$codice]))->toBe(0, $codice)
            ->and(ParoleDelConguaglio::MOTIVI[$codice])->not->toContain('programma')->toMatch('/^[A-Z].*\.$/u');
    }
    foreach (ConguaglioDelRendiconto::FUORI_DAI_CAPITOLI as [$nome]) {
        expect(preg_match($istruzioni, ParoleDelConguaglio::fuoriDaiCapitoli($nome)[1]))->toBe(0, $nome);
    }
    // La voce ferma per i periodi diversi di due piani: la frase della scheda nomina i piani, quella per chi legge no.
    expect(ParoleDelConguaglio::perche(ConguaglioDelRendiconto::frasePeriodiDiversi(['Preventivo 2026', 'Piano luglio'])))->toBe(ParoleDelConguaglio::MOTIVI['periodi_diversi']);
    expect(ParoleDelConguaglio::perche(ConguaglioDelRendiconto::MOTIVO_DI_RIPIEGO))->toBe(ParoleDelConguaglio::MOTIVO_DI_RIPIEGO)
        ->and($persona)->toContain('Uscite non divise fra i condòmini (per ora)')->toContain('Non sono ancora attribuite a una voce di spesa.')
        ->toContain('Restano fuori finché la contestazione non è chiusa.')->not->toContain('Assegna la voce')->not->toContain('nessuno paga');
});

it('il conguaglio per persona dice il secondo momento con le parole di chi legge: quanto avranno da versare i condòmini e quanto restituirà il condominio', function () {
    [, , , $persona] = pcdViaRoma($this);

    expect($persona)->toContain('Se l\'assemblea approva, i condòmini avranno da versare € 410,00, tra le rate già chieste e i conguagli a debito.')
        // Il credito non torna come denaro: a chi ha anche rate da versare si toglie da quelle (Bice: 65,75 dalle sue 410,00).
        ->toContain('Il condominio riconoscerà € 131,50 di conguagli a credito: a chi ha anche rate da versare si tolgono da quelle.')
        ->not->toContain('restituire')->not->toContain('lo studio')
        ->not->toContain('scrittura del conguaglio');
});

it('nessuna stampa per i condòmini nomina altri programmi o «il programma»; il foglio spiega le sue parole in quattro frasi, senza quella dei centesimi', function () {
    [$s, , , $persona, $foglio] = pcdViaRoma($this);
    $crediti = vrmTesto(view('pdf.gestionale.rendiconto_crediti', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'crediti' => app(PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'])])->render());

    foreach (['persona' => $persona, 'foglio' => $foglio, 'crediti' => $crediti] as $nome => $testo) {
        expect($testo)->not->toContain('Danea', $nome)->not->toContain('programma', $nome);
    }
    expect($foglio)->toContain(ConguaglioDelRendiconto::FRASE_SEGNO)->toContain('Il segno più vuol dire da versare')
        ->toContain('Le spese dell\'esercizio sono la sua parte delle spese di tutto l\'anno.')
        ->toContain('Le quote dei piani sono le rate dei piani dell\'esercizio, versate o no, comprese quelle non ancora chieste.')
        // La proposta confronta le spese con le quote, non con i versamenti: Bice ha la proposta a credito e tre rate non pagate (ha versato
        // 2.055,00 − 410,00 = 1.645,00, meno delle sue spese di 1.989,25).
        ->toContain('La proposta di conguaglio è la differenza: con il segno meno le quote dei piani sono più delle sue spese, e la differenza è a suo credito; '
            .'con il segno più sono meno, e la differenza è da versare.')
        ->not->toContain('ha versato più')
        ->toContain('Già da versare sono le rate già chieste e non pagate, e le altre somme fuori dalle rate.')->not->toContain('a suo carico')
        ->not->toContain(ConguaglioDelRendiconto::FRASE_CENTESIMI)
        // Bice ha un'unità sola: le cifre di «In tutto» sono quelle della tabella, e non si ripetono.
        ->not->toContain('In tutto')
        // L'affitto dell'antenna è un'entrata: il meno lo spiega.
        ->toContain('Affitto antenna Proprietà 500,00 su 1.000,00 € -120,00 (riduce le spese)');
});

it('il consuntivo per tabella di una gestione che non si calcola lo dice al condòmino come il conguaglio per persona: senza «il programma» e senza l\'istruzione per l\'amministratore', function () {
    // Il perché che il motore dà all'amministratore, con l'istruzione: le parole di `CalcoloQuoteService::lavoriStraordinari()`, che la scheda mostra.
    $perche = 'I piani della gestione hanno delibere con date diverse: una spesa straordinaria senza competenza scritta sulla fattura si divide fra chi '
        .'era titolare il giorno della delibera, e il programma non sceglie quale vale. Scrivi la competenza sulla fattura.';
    $consuntivo = ['data' => ['alla' => '2026-12-31', 'frase' => 'Alla fine dell\'esercizio.'],
        'gestioni' => [['id' => 1, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'non_calcolabile' => $perche, 'tabelle' => [], 'righe' => [], 'totali' => [],
            'gia_versato' => 0, 'totale' => 0, 'non_attribuite' => 0]]];
    $testo = vrmTesto(view('pdf.gestionale.consuntivo_per_tabella', ['condominio' => \App\Models\Condominio::factory()->make(['nome' => 'Prova']),
        'esercizio' => new \App\Models\Esercizio(['nome' => '2026']), 'consuntivo' => $consuntivo])->render());

    expect($testo)->toContain('Facciata')->toContain(ParoleDelConguaglio::GESTIONE_NON_CALCOLATA)
        ->not->toContain('programma')->not->toContain('Scrivi la competenza')->not->toContain('delibere con date diverse');
});

it('i crediti per anzianità per i condòmini: una colonna dell\'esercizio, l\'ultima lettera in due parole, senza la nota di legge', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $crediti = vrmTesto(view('pdf.gestionale.rendiconto_crediti', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'crediti' => app(PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'])])->render());

    expect($crediti)->toContain('Esercizio')->toContain('Ultima lettera spedita')
        ->not->toContain('Annata della scadenza')->not->toContain('Annata del piano')->not->toContain('1129')->not->toContain('partita o spedita');
});

it('nel fascicolo la colonna dell\'ultima lettera di sollecito c\'è solo se l\'amministratore la sceglie; la stampa dei crediti da sola la tiene', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = [];
    View::composer('pdf.gestionale.rendiconto_crediti', function ($v) use (&$dati) {
        $dati[] = $v->getData();
    });
    $base = route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]);
    $this->actingAs($this->user)->get($base.'?parti[]=condomini')->assertOk();
    $this->actingAs($this->user)->get($base.'?parti[]=condomini&lettere=1')->assertOk();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]))->assertOk();
    $testi = array_map(fn ($d) => vrmTesto(view('pdf.gestionale.rendiconto_crediti', $d)->render()), $dati);

    expect($testi)->toHaveCount(3)
        ->and($testi[0])->not->toContain('Ultima lettera spedita')
        ->and($testi[1])->toContain('Ultima lettera spedita')
        ->and($testi[2])->toContain('Ultima lettera spedita');
});

it('il frontespizio: i controlli in parole di chi legge, senza «ammanco»; la data in una frase; le tre cifre delle spese legate', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e']);
    $pagina = collect($t['fascicolo']['indice'])->firstWhere('parte', 'controlli')['pagina'];
    $testo = $t['frontespizio']['testo'];

    expect($testo)->toContain('I controlli: 13 tornano, 1 da leggere (l\'amministratore lo spiegherà in assemblea). Sono quadrature interne dei numeri; non sostituiscono il confronto con l\'estratto conto della banca.')
        ->toContain('Le righe dei controlli, per chi vuole il dettaglio, sono a pagina '.$pagina.'.')
        ->toContain('Le cifre sono quelle dell\'esercizio finito il 31/12/2026. I vincoli dei fondi sono invece di oggi.')
        // Via Roma: spese dei capitoli del riepilogo 4.218,50 − entrate da terzi 240,00 = 3.978,50; più 152,50 fuori dai capitoli, i costi 4.131,00.
        ->toContain('Spese del riepilogo finanziario € 4.218,50 − entrate da terzi € 240,00 = € 3.978,50 da dividere fra i condòmini; con € 152,50 di spese non ancora divise (fatture contestate, commissioni bancarie) i costi sono € 4.131,00.')
        ->not->toContain('ammanco')->not->toContain('programma')->not->toContain('sono alla data di fine la situazione patrimoniale');
});

it('«con il conguaglio a debito» dice quante persone restano fuori perché il loro conguaglio non è disponibile', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli']);
    $registro = $passaggio->registro;
    unset($registro['regolato_fuori']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e']);
    $elenco = [];
    foreach ($c->righe($s['c'], $s['e'], $letto)['gestioni'] as $g) {
        array_push($elenco, ...$g['righe']);
    }
    $conteggi = app(FogliDelConguaglio::class)->conteggi($letto, $elenco, 1, 200);

    // Bianchi e Rossi hanno il conguaglio non disponibile sull'unità del passaggio, e nessuna unità a debito.
    expect($conteggi['tutto']['non_disponibili'])->toBe(2);
});

it('il registro nel fascicolo: il saldo di cassa alla fine dell\'esercizio, la stessa data della riga di fine mese; i totali per tipo in una tabella che non si spezza', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['registro'], false);
    $html = $t['registro']['html'];
    preg_match('#<table[^>]*>(?:(?!</table>).)*Totali per tipo di movimento#s', $html, $tabella);
    preg_match('#<table[^>]*>#', $tabella[0] ?? '', $apertura);

    // Via Roma: l'ultimo movimento è del 16/12/2026; l'esercizio finisce il 31/12/2026, e la riga del mese dice «Saldo al 31/12/2026».
    expect($t['registro']['testo'])->toContain('Saldo di cassa al 31/12/2026')->not->toContain('Saldo di cassa al 16/12/2026')->toContain('Saldo al 31/12/2026')
        ->and($apertura[0] ?? '')->toContain('page-break-inside: avoid')->toContain('autosize="1"');
});

it('le note per chi legge, nelle stampe orizzontali, in un corpo che si legge: il «Come leggere» a 8 punti, le note sotto le cifre a 7', function () {
    [$s, , , ] = pcdViaRoma($this);
    $t = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo', 'registro', 'condomini'], false);
    $persona = view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'conguaglio' => pcdViaRoma($this)[1]])->render();

    foreach (['riepilogo' => $t['riepilogo']['html'], 'registro' => $t['registro']['html'], 'crediti' => $t['crediti']['html'], 'persona' => $persona] as $nome => $html) {
        expect(preg_match('/\.chiusura \{[^}]*font-size: 8pt/', $html))->toBe(1, $nome);
    }
    foreach (['riepilogo' => $t['riepilogo']['html'], 'crediti' => $t['crediti']['html']] as $nome => $html) {
        expect(preg_match('/\.d \{[^}]*font-size: 7pt/', $html))->toBe(1, $nome);
    }
});

it('il riepilogo e il frontespizio non nominano il programma: il primo esercizio registrato, il deliberato dei piani di prima', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    // Il piano senza le righe del riparto e con i millesimi cambiati dopo l'emissione: il deliberato non si ricostruisce.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('quote_tabella')->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    $t = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo'], false);

    expect($t['riepilogo']['testo'])->toContain('La colonna «Anno prima» è vuota: è il primo esercizio registrato.')
        ->toContain('Il deliberato con le rate non è disponibile per i piani approvati prima che il dettaglio del riparto fosse conservato: è nel verbale dell\'assemblea che li ha approvati.')
        ->not->toContain('programma')
        ->and($t['frontespizio']['testo'])->toContain('prima che il dettaglio del riparto fosse conservato')->not->toContain('programma');
});

it('il riepilogo: le uscite fuori dai capitoli e il risultato in un blocco che non si spezza, e il risultato non resta da solo su una pagina', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo'], false);

    expect(preg_match('#<div style="page-break-inside: avoid;">\s*<h2>Fuori dai capitoli</h2>(?:(?!</div>).)*<h2>Il risultato</h2>(?:(?!</div>).)*Le non pagate sono contate(?:(?!</div>).)*</div>#su', $t['riepilogo']['html']))->toBe(1);
});

it('il conguaglio per persona: la tabella del condominio e le frasi che la seguono in un blocco solo, e l\'ultima frase non va da sola sulla pagina dopo', function () {
    [$s, $righe] = pcdViaRoma($this);
    $html = view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render();

    expect(preg_match('#<div style="page-break-inside: avoid;">\s*<table[^>]*>(?:(?!</div>).)*Il condominio(?:(?!</div>).)*Il condominio riconoscerà(?:(?!</div>).)*</div>#su', $html))->toBe(1);
});

/**
 * Le cifre per persona dalla scheda (le righe, non i subtotali), dalla stampa per persona e dal foglio, con il testo del foglio: «già da versare» è la
 * posizione sulle rate più i saldi fuori dalle rate, e le righe da altre gestioni e da esercizi prima.
 */
function pcdCifreDelleTre($c, $e): array
{
    $k = app(ConguaglioDelRendiconto::class);
    $letto = $k->leggi($e, [], true);
    $per = [];
    foreach (['scheda' => $k->righe($c, $e, $letto), 'stampa' => $k->righe($c, $e, $letto, null, true)] as $nome => $r) {
        $tutte = [];
        foreach ($r['gestioni'] as $g) {
            array_push($tutte, ...array_filter($g['righe'], fn ($x) => ! $x['subtotale']));
        }
        array_push($tutte, ...array_map(fn ($x) => $x + ['fuori' => 0], array_filter($r['da_altre_gestioni']['righe'], fn ($x) => ! $x['subtotale'])));
        foreach ($tutte as $x) {
            $p = $per[$x['anagrafica_id']][$nome] ?? ['gia' => 0, 'conguaglio' => 0, 'saldo' => 0];
            $p['gia'] += (int) $x['posizione'] + (int) ($x['fuori'] ?? 0);
            $p['conguaglio'] += (int) $x['conguaglio'];
            $p['saldo'] += (int) $x['saldo'];
            $per[$x['anagrafica_id']][$nome] = $p;
        }
    }
    $esito = [];
    foreach ($per as $aid => $v) {
        $f = app(FogliDelConguaglio::class)->datiDelFoglio($c, $e, $letto, $aid);
        $esito[$aid] = $v + ['foglio' => ['gia' => $f['totale']['gia'], 'conguaglio' => $f['totale']['conguaglio'], 'saldo' => $f['totale']['saldo']],
            'testo' => vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $c, 'esercizio' => $e, 'foglio' => $f])->render())];
    }

    return $esito;
}

it('il «già da versare» del foglio comprende i saldi fuori dalle rate e le rate di altre gestioni: le stesse cifre nel foglio, nella scheda e nella stampa per persona', function () {
    // Via Verdi con il passaggio di U2 da Bianchi a Rossi e la sua coppia fuori dalle rate, una rata del 2025 di Neri non pagata, un saldo dal 2025 di
    // Verdi non applicato.
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    [$e25, $g25] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e25, $g25, 'Neri');
    cpcSaldoDaAnnoPrima($s, $e25, 'Verdi', 'U3', 7000);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $esito = pcdCifreDelleTre($s['c'], $s['e']->fresh());

    foreach ($esito as $aid => $v) {
        expect($v['foglio']['gia'])->toBe($v['scheda']['gia'], "foglio $aid")->and($v['stampa']['gia'])->toBe($v['scheda']['gia'], "stampa $aid")
            ->and($v['foglio']['saldo'])->toBe($v['foglio']['gia'] + $v['foglio']['conguaglio'], "somma $aid")
            ->and($v['testo'])->toContain(($v['foglio']['gia'] < 0 ? 'Già a suo credito ' : 'Già da versare ').ParoleDelConguaglio::conIlVerbo($v['foglio']['gia']));
    }
    // Bianchi: nessuna rata da versare, € −604,93 tutti della coppia del passaggio, fuori dalle rate; Neri € 800,00 con la rata del 2025 di un'altra gestione;
    // Verdi € 70,00 dal saldo del 2025 non applicato.
    $bianchi = $esito[$s['persone']['Bianchi']->id];
    expect($bianchi['foglio']['gia'])->toBe(-60493)
        ->and($esito[$s['persone']['Neri']->id]['foglio']['gia'])->toBe(80000)
        ->and($esito[$s['persone']['Verdi']->id]['foglio']['gia'])->toBe(7000)
        // Nella testata e in tabella le somme fuori dalle rate si dicono, e un credito non è «a suo carico».
        ->and($bianchi['testo'])->toContain('Già a suo credito a credito € 604,93 rate già chieste e non pagate e altre somme fuori dalle rate')
        ->toContain('Già da versare: altre somme fuori dalle rate € -604,93')->not->toContain('a suo carico');
});

it('la stampa del conguaglio per persona: il «già da versare» del condominio comprende i saldi fuori dalle rate', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    [$e25, $g25] = cpcAnnoPrima($s);
    cpcSaldoDaAnnoPrima($s, $e25, 'Verdi', 'U3', 7000);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $k = app(ConguaglioDelRendiconto::class);
    $righe = $k->righe($s['c'], $s['e']->fresh(), $k->leggi($s['e']->fresh(), [], true), null, true);
    $t = $righe['condominio'];
    $testo = vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render());
    $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
    $segno = fn (int $c) => '€ '.($c > 0 ? '+' : '').number_format($c / 100, 2, ',', '.');

    // Le rate da versare € 300,00 e € 70,00 fuori dalle rate (il saldo del 2025 di Verdi; la coppia del passaggio fa zero fra Bianchi e Rossi): € 370,00.
    expect([(int) $t['posizione'], (int) $t['fuori_dalle_rate']])->toBe([30000, 7000])
        ->and($testo)->toContain('Totale del condominio '.$euro($t['a']).' '.$euro($t['personali']).' '.$euro($t['b']).' '.$segno($t['conguaglio']).' € 370,00 '.$segno($t['saldo']));
});

it('la testata del foglio: l\'etichetta della cifra già da versare segue il segno; a credito «Già a suo credito», a zero «niente da versare»; in tabella le cifre con il segno', function () {
    // Da versare: Bice, € 410,00 di rate non pagate.
    [, , , , $bice] = pcdViaRoma($this);
    expect($bice)->toContain('Già da versare da versare € 410,00')->not->toContain('Già a suo credito');

    // A credito: Bianchi con la coppia del passaggio fuori dalle rate, € −604,93; in tabella la cifra resta con il meno.
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $k = app(ConguaglioDelRendiconto::class);
    $letto = $k->leggi($s['e']->fresh(), [], true);
    $testo = fn (string $chi) => vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'foglio' => app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e']->fresh(), $letto, $s['persone'][$chi]->id)])->render());
    expect($testo('Bianchi'))->toContain('Già a suo credito a credito € 604,93')->not->toContain('Già da versare a credito')
        ->toContain('Già da versare: altre somme fuori dalle rate € -604,93');
    $this->travelBack();
});

it('la testata del foglio a zero: «Già da versare», niente da versare', function () {
    // Via Verdi con tutte le rate pagate: nessuno ha qualcosa già da versare.
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], ['Neri' => [1 => null, 2 => null, 3 => null, 4 => null], 'Bianchi' => [1 => null, 2 => null, 3 => null, 4 => null], 'Verdi' => [1 => null, 2 => null, 3 => null, 4 => null]]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $k = app(ConguaglioDelRendiconto::class);
    $f = app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e']->fresh(), $k->leggi($s['e']->fresh(), [], true), $s['persone']['Neri']->id);
    $testo = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $f])->render());

    expect($f['totale']['gia'])->toBe(0)->and($testo)->toContain('Già da versare niente da versare')->not->toContain('Già a suo credito');
});

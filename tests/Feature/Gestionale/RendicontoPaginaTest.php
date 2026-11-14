<?php

/**
 * La pagina «Rendiconto»: le sei schede, la Sintesi con i quattro riquadri, le fasce e il prospetto della gestione; la scheda «Controlli» con l'elenco
 * oltre il confine e i suoi filtri; la scheda «Cassa»; la stampa del riepilogo con il dettaglio per spesa a scelta; la stampa della situazione con
 * l'esito in testa; il fascicolo per l'assemblea con le parti nell'ordine fisso. Le rotte dietro il pannello, nel condominio giusto.
 *
 * Le cifre sono quelle del caso «Via Roma» del progetto, fatte a mano. Centesimi.
 */

use App\Models\Esercizio;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Le proprietà della pagina Inertia di una rotta del rendiconto. */
function rpgProps($test, string $rotta, array $s, array $query = []): array
{
    return $test->actingAs($test->user)->get(route($rotta, [$s['c'], $s['e']]).($query ? '?'.http_build_query($query) : ''))->assertOk()->viewData('page')['props'];
}

/** Le cartelle temporanee dei fascicoli che ci sono ora. */
function rpgCartelle(): array
{
    return File::isDirectory(FascicoloDelRendiconto::cartellaTemporanea()) ? File::directories(FascicoloDelRendiconto::cartellaTemporanea()) : [];
}

/** Il limite di tempo o di memoria che il fascicolo legge; nullo, quello del processo. */
function rpgLimite(string $nome, int|string|null $valore): void
{
    (new ReflectionProperty(FascicoloDelRendiconto::class, $nome))->setValue(null, $valore);
}

/** Le pagine di un PDF, contate con FPDI (la libreria che mPDF porta con sé). */
function rpgPagine(string $pdf): int
{
    return (new \Mpdf\Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]))->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
}

/** La misura di ogni pagina di un PDF come si vede, in punti arrotondati: [larghezza, altezza] dal MediaBox della pagina, con la sua rotazione. */
function rpgMisure(string $pdf): array
{
    $documento = new \setasign\Fpdi\PdfReader\PdfReader(new \setasign\Fpdi\PdfParser\PdfParser(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf)));
    $misure = [];
    for ($i = 1; $i <= $documento->getPageCount(); $i++) {
        [$larghezza, $altezza] = $documento->getPage($i)->getWidthAndHeight(\setasign\Fpdi\PdfReader\PageBoundaries::MEDIA_BOX);
        $misure[] = [round($larghezza, 2), round($altezza, 2)];
    }

    return $misure;
}

// ─── La Sintesi ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la Sintesi di Via Roma: deliberato € 4.110,00; costi € 4.131,00 = 100,5 % con «di cui per capitolo € 3.978,50»; quote pagate € 3.700,00 su € 4.110,00; liquidità € 5.219,00 secondo la contabilità; le due fasce; il risultato −21,00 con i suoi due termini', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $props = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    $si = $props['sintesi'];
    $fasce = collect($si['fasce'])->keyBy('tipo');

    expect($si['riquadri']['deliberato'])->toBe(['deliberato' => 411000, 'preventivo' => 411000, 'solo_ordinarie' => false, 'ricostruito' => false])
        ->and($si['riquadri']['costi'])->toBe(['costi' => 413100, 'per_capitolo' => 397850, 'permille' => 1005, 'base' => 'deliberato', 'base_importo' => 411000])
        ->and($si['riquadri']['quote'])->toBe(['pagate' => 370000, 'emesse' => 411000])
        ->and($si['riquadri']['liquidita']['liquidita'])->toBe(521900)->and($si['riquadri']['liquidita']['frase'])->toContain('econdo la contabilità')
        ->and($si['riquadri']['liquidita']['frase'])->toContain('non è il saldo dell\'estratto conto della banca')
        ->and($fasce['commissioni']['frase'])->toBe('€ 2,50 di commissioni senza voce: non stanno in nessun capitolo, e il conguaglio non le divide.')
        ->and($fasce['commissioni']['da_assegnare'])->toBe(1)
        ->and($fasce['contestate']['importo'])->toBe(15000)->and($fasce['contestate']['frase'])->toStartWith('€ 150,00 di fatture contestate')
        ->and($si['prospetto']['risultato'])->toBe(-2100)->and($si['prospetto']['quote_giornale'])->toBe(411000)->and($si['prospetto']['costi'])->toBe(413100)
        ->and($si['esito']['frase'])->toBe('13 tornano, 0 non tornano, 1 da leggere')
        ->and($si['stato']['frase'])->toBe('Esercizio finito al 31/12/2026: sono alla data di fine la situazione patrimoniale, le fatture aperte, il pagato e le quote dei piani; costi, quote registrate, risultato ed entrate e uscite per cassa contano le registrazioni dell\'esercizio, anche con la data dopo la fine; i vincoli dei fondi sono a oggi.')
        ->and($props['parti'])->toBe(FascicoloDelRendiconto::PARTI);

    // Una fonte sola: ogni cifra della Sintesi è quella della scheda da cui viene.
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);
    // La Sintesi di Via Roma, sotto la soglia, legge il conguaglio e lo passa ai controlli.
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e'], null, [], null, null, [], app(\App\Services\Gestionale\ConguaglioDelRendiconto::class)->leggi($s['e']));
    expect($si['riquadri']['quote']['pagate'])->toBe($r['quote']['totale']['pagate'])
        ->and($si['riquadri']['costi']['costi'])->toBe($r['risultato']['costi'])->and($si['riquadri']['costi']['per_capitolo'])->toBe($r['riconciliazione']['capitoli_netti'])
        ->and($si['prospetto']['risultato'])->toBe($r['risultato']['risultato'])->and($si['prospetto']['risultato'])->toBe($p['risultato_gestione']['risultato'])
        ->and($si['riquadri']['liquidita']['liquidita'])->toBe($p['sintesi']['liquidita'])
        ->and($si['esito'])->toBe($c['esito']);
});

it('un esercizio aperto: la Sintesi dice che i numeri cambiano a ogni registrazione', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');

    expect(rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi']['stato']['frase'])->toBe('Esercizio aperto: i numeri sono a oggi e cambiano a ogni registrazione.');
});

it('alle 00:30 di Roma del 01/01/2027 il 2026 è finito, alla data del 31/12; alle 00:30 del 31/12/2026 è ancora aperto, a oggi', function () {
    $s = vrmScenario($this);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    $finito = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi']['stato'];
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-31 00:30', 'Europe/Rome'));
    $aperto = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi']['stato'];

    expect($finito)->toMatchArray(['finito' => true, 'alla' => '2026-12-31'])->and($aperto)->toMatchArray(['finito' => false, 'alla' => '2026-12-31']);
});

// ─── Le schede ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('le schede dalla rotta: i dati che la pagina riceve sono quelli dei servizi; la rotta di sempre apre la situazione patrimoniale', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $controlli = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s);
    $cassa = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.cassa', $s);
    // La scheda «Controlli» legge il conguaglio: i quattro controlli del conguaglio vengono dalla stessa lettura.
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e'], null, [], null, null, [], app(\App\Services\Gestionale\ConguaglioDelRendiconto::class)->leggi($s['e']));
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);

    expect($controlli['controlli'])->toEqual($c['controlli'])->and($controlli['esito'])->toEqual($c['esito'])->and($controlli['anno_a_cavallo'])->toEqual($c['anno_a_cavallo'])
        ->and($cassa['cassa']['riepilogo'])->toEqual($p['riepilogo'])->and($cassa['cassa']['raccordo'])->toEqual($p['raccordo']);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('gestionale/movimenti/statoPatrimoniale/Show'));
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->assertInertia(fn ($page) => $page->component('gestionale/movimenti/rendiconto/Sintesi'));
});

it('i filtri dell\'elenco oltre il confine arrivano dalla rotta; un valore che non è un filtro non filtra', function () {
    $s = vrmScenario($this);
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    foreach (['2027-01-10', '2027-01-11'] as $d) {
        $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => $d,
            'data_competenza' => $d, 'numero_protocollo' => 'RPG-'.$d, 'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('righe_scritture')->insert([['scrittura_id' => $id, 'conto_contabile_id' => $banca, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'dare', 'importo' => 1000, 'created_at' => now(), 'updated_at' => now()],
            ['scrittura_id' => $id, 'conto_contabile_id' => $crediti, 'cassa_id' => null, 'tipo_riga' => 'avere', 'importo' => 1000, 'created_at' => now(), 'updated_at' => now()]]);
    }
    vrmGiorno($this, '2027-01-15');

    $tutte = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s)['anno_a_cavallo'];
    $rosse = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s, ['colore' => 'rosso'])['anno_a_cavallo'];
    $strano = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s, ['verso' => 'C', 'tipo' => 'nulla'])['anno_a_cavallo'];

    expect($tutte['righe_filtrate'])->toBe(2)->and($tutte['righe'][0]['protocollo'])->toBe('RPG-2027-01-10')->and($tutte['righe'][0]['esercizio_id'])->toBe((int) $s['e']->id)
        ->and($rosse['righe_filtrate'])->toBe(0)->and($rosse['filtri']['colore'])->toBe('rosso')
        ->and($strano['filtri'])->toBe(['verso' => null, 'tipo' => null, 'colore' => null])->and($strano['righe_filtrate'])->toBe(2);
});

// ─── La sicurezza ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('le rotte nuove: l\'esercizio di un altro condominio fa 404 per tutta la richiesta, e chi non ha il pannello non entra', function () {
    $s = vrmScenario($this);
    $altro = be52Condominio();
    $rotte = ['admin.gestionale.esercizi.rendiconto.sintesi', 'admin.gestionale.esercizi.rendiconto.cassa', 'admin.gestionale.esercizi.rendiconto.controlli',
        'admin.gestionale.esercizi.rendiconto.fascicolo', 'admin.gestionale.esercizi.rendiconto.riepilogo.stampa'];
    $senzaRuolo = \App\Models\User::factory()->create();
    foreach ($rotte as $rotta) {
        $this->actingAs($this->user)->get(route($rotta, [$s['c'], $altro['e']]))->assertNotFound();
        $this->actingAs($senzaRuolo)->get(route($rotta, [$s['c'], $s['e']]))->assertForbidden();
    }
    // Nessuna rotta del rendiconto fra quelle del portale.
    $delPortale = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'rendiconto') && ! str_starts_with((string) $r->getName(), 'admin.gestionale.'));
    expect($delPortale->map(fn ($r) => $r->uri())->values()->all())->toBe([]);
});

// ─── Le stampe ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la stampa del riepilogo si compila, con il riquadro «Come leggere» e le sue convenzioni; con il dettaglio per spesa ha una riga per fattura', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = null;
    View::composer('pdf.gestionale.rendiconto_riepilogo', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    $senza = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$s['c'], $s['e']]))->assertOk();
    $html = view('pdf.gestionale.rendiconto_riepilogo', $dati)->render();
    $con = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$s['c'], $s['e']]).'?dettaglio=1')->assertOk();
    $htmlDettaglio = view('pdf.gestionale.rendiconto_riepilogo', $dati)->render();

    expect(substr((string) $senza->getContent(), 0, 4))->toBe('%PDF')->and(substr((string) $con->getContent(), 0, 4))->toBe('%PDF')
        ->and($senza->headers->get('Content-Disposition'))->toContain('riepilogo-finanziario-')
        ->and($html)->toContain('Come leggere questo prospetto')->and($html)->toContain('Per competenza:')
        ->and($html)->toContain('alla data del 31/12/2026')->and($html)->toContain('positivi quando devono')
        ->and($html)->toContain('in proporzione al loro costo')->and($html)->toContain('Fatture contestate')
        ->and($html)->toContain('«Scritto sul capitolo»')->and($html)->toContain('«pagato in più»')->and($html)->toContain('credito riportato')
        ->and($html)->toContain('€ 4.131,00')->and($html)->toContain('€ 3.978,50')->and($html)->toContain('€ -21,00')
        ->and($html)->not->toContain('Dettaglio per spesa')
        ->and($htmlDettaglio)->toContain('Dettaglio per spesa')->and($htmlDettaglio)->toContain('FT-1')->and($htmlDettaglio)->toContain('Fornitore Test');
});

it('la stampa della situazione ha in testa l\'esito dei controlli con la riga fissa; le parti si stampano da sole', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = null;
    View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    $html = [];
    $controlliLetti = [];
    foreach ([null, 'situazione', 'cassa', 'controlli'] as $parte) {
        $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).($parte ? '?parte='.$parte : ''))->assertOk();
        expect(substr((string) $r->getContent(), 0, 4))->toBe('%PDF');
        $html[$parte ?? 'intera'] = view('pdf.gestionale.stato_patrimoniale', $dati)->render();
        $controlliLetti[$parte ?? 'intera'] = $dati['controlli'] !== null;
    }

    // La parte «cassa» non stampa i controlli, e non li legge; le altre sì.
    expect($controlliLetti)->toBe(['intera' => true, 'situazione' => true, 'cassa' => false, 'controlli' => true])
        // Il «Come leggere» della sola situazione non parla dei controlli né delle entrate e uscite, che non ha; la stampa intera sì.
        ->and($html['situazione'])->not->toContain('I controlli in fondo')->and($html['situazione'])->not->toContain('Entrate e uscite del riepilogo')
        ->and($html['intera'])->toContain('I controlli in fondo')
        // La destinazione dell'avanzo la decide l'assemblea.
        ->and($html['intera'])->not->toContain('andrà a conguaglio')->and($html['situazione'])->toContain('che cosa farne lo decide l\'assemblea')
        // I controlli stampati da soli non sono «art. 1130-bis»; le loro parole sono quelle dell'esito in testa.
        ->and($html['controlli'])->not->toContain('1130-bis')->and($html['situazione'])->toContain('1130-bis')
        ->and($html['controlli'])->toContain('<span class="badge ok">torna</span>')->and($html['controlli'])->toContain('<span class="badge warn">da leggere</span>')
        ->and($html['controlli'])->not->toContain('>quadra</span>');

    expect($html['intera'])->toContain('I controlli: 13 tornano, 0 non tornano, 1 da leggere.')->and($html['intera'])->toContain(e(ControlliDelRendiconto::RIGA_FISSA))
        ->and($html['intera'])->toContain('Registrazioni oltre il confine dell&#039;esercizio')->and($html['intera'])->toContain('I costi tornano con il riepilogo')
        ->and($html['situazione'])->toContain('I controlli: 13 tornano')->and($html['situazione'])->toContain('Fatture aperte al 31/12/2026')->and($html['situazione'])->not->toContain('Controlli di quadratura')
        ->and($html['cassa'])->toContain('Entrate e uscite per cassa')->and($html['cassa'])->not->toContain('Fatture aperte al')->and($html['cassa'])->not->toContain('I controlli: 12 tornano')
        ->and($html['controlli'])->toContain('<span class="sec">§ 7</span> I controlli</h2>')->and($html['controlli'])->not->toContain('Fatture aperte al');
});

// ─── Il fascicolo ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('il fascicolo: le parti nell\'ordine fisso qualunque sia l\'ordine delle spunte; il frontespizio con l\'esito e l\'indice; le pagine sono quelle dell\'indice; i file temporanei non restano', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = null;
    View::composer('pdf.gestionale.rendiconto_frontespizio', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    $prima = rpgCartelle();
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['controlli', 'registro', 'cassa', 'riepilogo']);
    $html = view('pdf.gestionale.rendiconto_frontespizio', $dati)->render();

    expect(array_column($f['indice'], 'parte'))->toBe(['riepilogo', 'cassa', 'registro', 'controlli'])
        ->and($f['indice'][0]['pagina'])->toBe(2)
        ->and(rpgPagine($f['pdf']))->toBe(1 + array_sum(array_column($f['indice'], 'pagine')))
        ->and($f['indice'][1]['pagina'])->toBe(2 + $f['indice'][0]['pagine'])
        // Senza la parte del conguaglio il fascicolo non lo legge: i suoi quattro controlli sono «non calcolati», a parte, e la riga degli esiti dice perché.
        ->and(vrmTesto($html))->toContain('I controlli: 9 tornano, 1 da leggere (l\'amministratore lo spiegherà in assemblea); 4 non sono calcolati: leggono il conguaglio, '
            .'che non è in questo fascicolo. Sono quadrature interne dei numeri; non sostituiscono il confronto con l\'estratto conto della banca.')
        ->and($html)->not->toContain(e(ControlliDelRendiconto::RIGA_FISSA))
        ->and($html)->toContain('Indice')->and($html)->toContain('L\'amministratore')->and($html)->toContain('€ -21,00')
        // Il registro nomina chi ha versato: il frontespizio lo dice in prima pagina.
        ->and($html)->toContain(e(\App\Services\Gestionale\PosizioniDeiCondomini::RISERVATO))
        // La cartella di questo fascicolo non resta; quelle degli altri non sono affar suo.
        ->and(rpgCartelle())->toBe($prima);

    // Il risultato zero è in pareggio; con una straordinaria il preventivo è delle sole ordinarie, e lo dice.
    $pari = $dati;
    $pari['sintesi']['prospetto'] = ['risultato' => 0, 'preventivo_solo_ordinarie' => true] + $pari['sintesi']['prospetto'];
    expect(view('pdf.gestionale.rendiconto_frontespizio', $pari)->render())->toContain('(in pareggio)')->toContain('Preventivo delle gestioni ordinarie')
        ->and($html)->not->toContain('Preventivo delle gestioni ordinarie');

    // Senza le parti con i dati dei condòmini il frontespizio non lo dice.
    app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['cassa', 'riepilogo']);
    expect(view('pdf.gestionale.rendiconto_frontespizio', $dati)->render())->not->toContain('Contiene dati personali');

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=registro&parti[]=riepilogo&dettaglio=1')->assertOk();
    expect(substr((string) $r->getContent(), 0, 4))->toBe('%PDF')->and($r->headers->get('Content-Disposition'))->toContain('fascicolo-rendiconto-');
});

it('il fascicolo: ogni parte esce con la misura delle pagine della sua stampa da sola; il riepilogo, le posizioni, i crediti per anzianità, le due tabelle del conguaglio e il registro restano in orizzontale', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], [], true);
    $fascicolo = rpgMisure($f['pdf']);
    $daSola = fn (string $rotta, string $query = '') => rpgMisure((string) $this->actingAs($this->user)->get(route($rotta, [$s['c'], $s['e']]).$query)->assertOk()->getContent());
    $stampe = [
        'Riepilogo finanziario, con il dettaglio per spesa' => $daSola('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', '?dettaglio=1'),
        'Situazione patrimoniale' => $daSola('admin.gestionale.esercizi.stato-patrimoniale.print', '?parte=situazione'),
        'Entrate e uscite per cassa' => $daSola('admin.gestionale.esercizi.stato-patrimoniale.print', '?parte=cassa'),
        'Posizioni dei condòmini' => $daSola('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa'),
        'Crediti per anzianità' => $daSola('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa'),
        'Consuntivo per tabella' => $daSola('admin.gestionale.esercizi.rendiconto.conguaglio.per-tabella.stampa'),
        'Conguaglio per persona' => $daSola('admin.gestionale.esercizi.rendiconto.conguaglio.stampa'),
        'Registro di contabilità' => $daSola('admin.gestionale.esercizi.registro-contabilita.print'),
        'Controlli del rendiconto' => $daSola('admin.gestionale.esercizi.stato-patrimoniale.print', '?parte=controlli'),
    ];
    // A4: 595,28 × 841,89 punti in verticale.
    $verticale = [595.28, 841.89];
    $orizzontale = [841.89, 595.28];
    $diverse = fn (array $pagine) => array_values(array_unique($pagine, SORT_REGULAR));

    expect(array_column($f['indice'], 'titolo'))->toBe(array_keys($stampe))
        ->and(count($fascicolo))->toBe(1 + array_sum(array_column($f['indice'], 'pagine')))
        ->and($fascicolo[0])->toBe($verticale);
    foreach ($f['indice'] as $v) {
        $pagine = array_slice($fascicolo, $v['pagina'] - 1, $v['pagine']);
        $atteso = in_array($v['parte'], ['riepilogo', 'condomini', 'conguaglio', 'registro'], true) ? $orizzontale : $verticale;
        expect($v['pagine'])->toBeGreaterThan(0)
            ->and($diverse($pagine))->toBe($diverse($stampe[$v['titolo']]), $v['titolo'])
            ->and($diverse($pagine))->toBe([$atteso], $v['titolo']);
    }
});

it('il fascicolo di Via Roma parla a chi lo riceve: che cosa vogliono dire gli esiti, le parole di tutti al posto di quelle del programma, il nome della parte accanto al numero di pagina', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e']);
    $pagina = collect($t['fascicolo']['indice'])->firstWhere('parte', 'controlli')['pagina'];
    $riservato = 'Contiene i dati personali dei condòmini: è per i condòmini e per l\'assemblea. Non va esposto in bacheca né girato ad altri, nemmeno in una chat.';

    // Il frontespizio.
    // I controlli per chi legge: quanti tornano, chi spiega l'altro, che cosa non sono; le righe, con le parole della contabilità, nella loro pagina.
    expect($t['frontespizio']['testo'])->toContain('I controlli: 13 tornano, 1 da leggere (l\'amministratore lo spiegherà in assemblea).')
        ->not->toContain('Vincoli e casse fondo: da leggere')->not->toContain('ammanco')
        ->toContain('Le righe dei controlli, per chi vuole il dettaglio, sono a pagina '.$pagina.'.')
        ->toContain('Risultato: quote registrate in questo esercizio € 4.110,00 − costi € 4.131,00')
        ->toContain('Secondo la contabilità: non è il saldo dell\'estratto conto della banca, che si confronta a parte.')
        ->toContain('Preventivo: le spese previste, meno le entrate da terzi. Deliberato con le rate: quanto chiedono i piani delle rate approvati. '
            .'Quote dei piani dell\'esercizio: le rate di quei piani già emesse.')
        ->toContain($riservato)
        ->not->toContain('controllo tuo')->not->toContain('a giornale')->not->toContain('qualunque sia l\'ordine in cui')
        // Via Roma non ha registrazioni oltre il confine: il risultato non ne parla.
        ->not->toContain('Il risultato comprende');

    // Il riepilogo, con il dettaglio per spesa.
    expect($t['riepilogo']['testo'])
        ->toContain('Come leggere questo prospetto. Per ogni spesa: il previsto (preventivo), il chiesto con le rate (deliberato), lo speso (consuntivo), lo speso dell\'anno prima, '
            .'la differenza fra speso e chiesto (scostamento: con il meno si è speso meno), il pagato e il da pagare ai fornitori. Le spese sono raccolte per capitolo; ogni capitolo ha le sue voci.')
        ->toContain('Quando la gestione è già passata all\'esercizio dopo, il preventivo di quest\'anno è nel verbale dell\'assemblea.')
        ->toContain('Il pagato comprende la ritenuta d\'acconto quando è versata allo Stato con l\'F24; fino ad allora è nel da pagare.')
        ->toContain('Commissioni bancarie non attribuite a una spesa (1)')
        ->toContain('Le fatture di un anno passato registrate come pregresse non sono costi di questo esercizio e non compaiono qui')
        ->toContain('Risultato: quote registrate in questo esercizio € 4.110,00 − costi € 4.131,00 = € -21,00 (disavanzo).')
        ->toContain('Costi dell\'esercizio € 4.131,00 = spese per capitolo meno entrate')
        ->toContain('fatture contestate € 150,00.')
        ->toContain('Quote dei piani dell\'esercizio € 4.110,00 = quote registrate in questo esercizio € 4.110,00 +')
        ->toContain('Le non pagate sono contate senza togliere i crediti sulle rate: € 410,00, e accanto € 0,00 di crediti sulle rate.')
        ->toContain('Commissione del pagamento: Pag. FT-1 - Fornitore Test Srl')->toContain('RIM-2026-00001 Canone e bolli')
        ->not->toContain('chi arriva da un altro programma')->not->toContain('Commissioni senza voce')->not->toContain('a giornale')
        ->not->toContain('come in «Rate scadute»')->not->toContain('finché la gestione non passa');

    // La situazione, la cassa e i controlli.
    expect($t['situazione']['testo'])->toContain(ControlliDelRendiconto::RIGA_ESITI)
        ->toContain('I debiti sono quelli registrati come fatture. Un debito che non è una fattura (per esempio da una sentenza) non compare qui: se c\'è, l\'amministratore lo dice a parte.')
        ->toContain('«Fondo Passate Gestioni» è quanto riportato dagli esercizi prima, non un fondo accantonato. I fondi sono parte dello stesso conto corrente e si contano nella liquidità una volta sola.')
        ->toContain('Risultato di gestione € -21,00 (disavanzo)')
        ->toContain('Quadratura: torna.')
        ->toContain('= debiti verso i fornitori € 650,00.')
        ->toContain('Senza una gestione: commissioni e altre spese non attribuite a una voce, e registrazioni senza gestione')
        ->toContain('Per un esercizio finito questo confronto non si fa: i vincoli si leggono a oggi, non alla data di fine.')
        ->toContain('Un fondo è parte dello stesso conto corrente')
        ->not->toContain('(in arrivo)')->not->toContain('Disavanzo di gestione')->not->toContain('Libro Giornale')->not->toContain('a giornale')
        ->not->toContain('Gestione ordinaria · ordinaria')->not->toContain('partizion');
    expect($t['cassa']['testo'])->toContain('Conta le scritture di questo esercizio, anche quelle con una data dopo la fine: il saldo a una data è nella situazione patrimoniale.');
    expect($t['controlli']['html'])->toContain('<span class="sec">§ 7</span> I controlli</h2>')->not->toContain('Controlli di quadratura');
    expect($t['controlli']['testo'])->toContain(ControlliDelRendiconto::RIGA_ESITI)->toContain('Per un esercizio finito questo confronto non si fa: i vincoli si leggono a oggi, non alla data di fine.')
        ->toContain('Costi dell\'esercizio € 4.131,00 = spese per capitolo meno entrate')
        ->not->toContain('(in arrivo)')->not->toContain('a giornale');
    // Una gestione il cui nome non dice il tipo: il tipo accanto.
    $dati = $t['situazione']['dati'];
    $dati['pagina']['per_gestione']['righe'][0]['gestione']['nome'] = 'Riscaldamento';
    expect(vrmTesto(view('pdf.gestionale.stato_patrimoniale', $dati)->render()))->toContain('Riscaldamento · ordinaria');

    // Le posizioni, i crediti per anzianità, il registro: la testata non ripete il titolo; le frasi senza rimandi a pagine o colonne che non ci sono.
    expect($t['posizioni']['dati'])->not->toHaveKey('titolo_stampa')->and($t['crediti']['dati'])->not->toHaveKey('titolo_stampa');
    expect($t['posizioni']['testo'])->toContain('Il totale è senza gli anticipi, che sono un debito del condominio verso la persona.')->toContain($riservato)
        ->not->toContain('nella loro colonna');
    expect($t['crediti']['testo'])->toContain('Le rate non pagate, per ritardo.')
        ->toContain('Il credito di una persona non si toglie dalle sue rate: è nelle posizioni dei condòmini.')
        // Per chi legge: una colonna dell'esercizio, senza la nota di legge; nel fascicolo, di serie, senza la colonna dell'ultima lettera.
        ->toContain('«Esercizio»: l\'esercizio del piano a cui appartiene la rata')->not->toContain('si legge nei due modi')
        ->not->toContain('Ultima lettera spedita')
        ->toContain($riservato)
        ->not->toContain('(§ 9)')->not->toContain('il programma mostra')->not->toContain('solo stampata');
    expect($t['registro']['testo'])->toContain('Spese pagate subito, senza fattura')->toContain($riservato)->not->toContain('Regolazioni immediate');

    // Il numero di pagina dice la parte: nel fascicolo «Situazione patrimoniale, pagina 1 di 2», il numero che l'indice non dice.
    foreach (['riepilogo' => 'Riepilogo finanziario', 'situazione' => 'Situazione patrimoniale', 'cassa' => 'Entrate e uscite per cassa', 'controlli' => 'Controlli del rendiconto',
        'posizioni' => 'Posizioni dei condòmini', 'crediti' => 'Crediti per anzianità', 'registro' => 'Registro di contabilità'] as $parte => $nome) {
        expect($t[$parte]['html'])->toContain($nome.', pagina {PAGENO} di {nbpg}');
    }

    // Le stampe da sole: la testata delle posizioni e dei crediti non ripete il titolo; il numero di pagina è quello di sempre.
    $dati = [];
    View::composer(['pdf.gestionale.rendiconto_posizioni', 'pdf.gestionale.rendiconto_crediti'], function ($v) use (&$dati) {
        $dati[$v->getName()] = $v->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa', [$s['c'], $s['e']]))->assertOk();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]))->assertOk();
    expect($dati['pdf.gestionale.rendiconto_posizioni'])->not->toHaveKey('titolo_stampa')->and($dati['pdf.gestionale.rendiconto_crediti'])->not->toHaveKey('titolo_stampa')
        ->and(view('pdf.gestionale.rendiconto_posizioni', $dati['pdf.gestionale.rendiconto_posizioni'])->render())->toContain('Pagina {PAGENO} di {nbpg}')->not->toContain(', pagina {PAGENO}');

    // La stampa intera della situazione patrimoniale: che cosa non contiene, senza promesse.
    $intera = null;
    View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$intera) {
        $intera = $v->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]))->assertOk();
    expect(vrmTesto(view('pdf.gestionale.stato_patrimoniale', $intera)->render()))->toContain('Che cosa questo prospetto non contiene.')
        ->toContain('Il confronto con l\'estratto conto della banca: si fa a parte.')
        ->not->toContain('non fa ancora')->not->toContain('diventerà definitiva')->not->toContain('finché non ci sarà')->not->toContain('Pagina {PAGENO} di {nbpg},');
});

it('una nota di credito fra le fatture aperte della stampa ha il meno come le altre cifre: «€ -30,00»', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    vrmFattura($this, $s, $s['fornitore'], 'NC-9', [[$s['pulizie']->id, 30.00]], '2026-12-20', ['tipo_documento' => 'nota_credito']);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['situazione'], false);

    expect($t['situazione']['testo'])->toContain('NC-9 del 20/12/2026 20/12/2026 nota di credito € -30,00')->not->toContain('−€');
});

it('il § 9 e il § 10 scrivono il meno come il resto del fascicolo, con l\'euro prima: il credito di Ugo è «€ -50,00»', function () {
    $s = vrmScenario($this, 5000);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['condomini']);

    expect($t['posizioni']['testo'])->toContain('€ -50,00')->not->toContain('−€');
    // Il § 10 ha lo stesso modo: una cifra sotto zero (qui scritta a mano nei dati della stampa) esce «€ -50,00».
    $dati = $t['crediti']['dati'];
    $dati['crediti']['righe'][0]['resta_oggi'] = -5000;
    expect(vrmTesto(view('pdf.gestionale.rendiconto_crediti', $dati)->render()))->toContain('€ -50,00')->not->toContain('−€');
});

// ─── Le tre frasi che i fascicoli veri non facevano capire ─────────────────────────────────────────────────────────────────────

it('il deliberato con le rate che un piano vecchio non ha conservato: nella stampa la cella dice «—» o «non disponibile» e una frase sola dice perché; con il deliberato di tutte le gestioni la frase non c\'è', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    // La frase della stampa: per chi legge, senza «il programma».
    $frase = \App\Services\Gestionale\ParoleDellaStampa::frase(\App\Services\Gestionale\RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE);
    expect($frase)->toBe('Il deliberato con le rate non è disponibile per i piani approvati prima che il dettaglio del riparto fosse conservato: è nel verbale dell\'assemblea che li ha approvati.');

    // Il deliberato c'è (le righe del riparto sono scritte): nessuna cella senza numero, nessuna frase.
    $con = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo'], false);
    expect($con['frontespizio']['testo'])->toContain('Deliberato con le rate € 4.110,00')->not->toContain('non disponibile')->not->toContain($frase)->not->toContain('non conservato')
        ->and($con['riepilogo']['testo'])->toContain('Pulizie € 3.000,00 € 3.000,00 € 2.900,00')->toContain('Quote ordinarie € 4.110,00 € 4.110,00')
        ->not->toContain('non disponibile')->not->toContain($frase)->not->toContain('non conservato');

    // Il piano di prima che il programma conservasse il riparto: il numero non c'è. Dalla 1.12.0-beta.6 si ricostruirebbe (Coda 557); con i millesimi cambiati
    // dopo l'emissione non torna più, e il numero resta assente.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('quote_tabella')->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    $senza = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo'], false);

    // Il frontespizio ha una riga sola: «non disponibile», e la frase nella nota sotto il prospetto.
    expect($senza['frontespizio']['testo'])->toContain('Deliberato con le rate non disponibile Quote dei piani dell\'esercizio € 4.110,00')
        ->toContain('Quote dei piani dell\'esercizio: le rate di quei piani già emesse. '.$frase)
        ->and(substr_count($senza['frontespizio']['testo'], $frase))->toBe(1)
        ->and($senza['frontespizio']['testo'])->not->toContain('non conservato')
        // Con la frase in più il frontespizio sta ancora in una pagina: la prima parte comincia alla seconda.
        ->and($senza['fascicolo']['indice'][0]['pagina'])->toBe(2);
    // Il riepilogo ha una colonna: «—» riga per riga, e la frase una volta sola, sotto le tabelle.
    expect($senza['riepilogo']['testo'])->toContain('Pulizie € 3.000,00 — € 2.900,00')->toContain('Manutenzioni € 1.200,00 — € 1.220,00')->toContain('Quote ordinarie — € 4.110,00')
        ->and(substr_count($senza['riepilogo']['testo'], $frase))->toBe(1)
        ->and($senza['riepilogo']['testo'])->not->toContain('non conservato')->not->toContain('non disponibile');
    // Il preventivo che le voci non portano più resta «nel verbale dell'assemblea»: non è il deliberato.
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e2027, $s['g']->id);
    $dopo = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo'], false);
    expect($dopo['riepilogo']['testo'])->toContain('Pulizie nel verbale dell\'assemblea — € 2.900,00')->and($dopo['frontespizio']['testo'])->toContain('Preventivo nel verbale dell\'assemblea');
});

it('la Sintesi e il riepilogo a video portano la stessa frase, e solo quando una gestione ha il deliberato non disponibile', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $frase = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: è nel verbale dell\'assemblea che li ha approvati.';
    $leggi = fn () => [
        rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi']['prospetto']['avvertenza_deliberato'],
        rpgProps($this, 'admin.gestionale.esercizi.rendiconto.riepilogo', $s)['riepilogo']['avvertenze']['deliberato'],
    ];

    expect($leggi())->toBe([null, null]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    // Dalla 1.12.0-beta.6 il piano senza righe si ricostruisce (Coda 557): nessuna frase del «non disponibile» finché torna.
    expect($leggi())->toBe([null, null]);
    DB::table('quote_tabella')->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    expect($leggi())->toBe([$frase, $frase]);
});

it('il § 4 della situazione non mette un esito accanto ad «Accantonato nelle casse fondo»: non è un controllo, e non dice «quadra»', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    be52Fondo($s, 'Fondo riserva');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['situazione'], false);

    // Un esercizio aperto e uno finito: la riga ha la cifra, l'esito lo dice il controllo dei vincoli nel § 7.
    expect($t['situazione']['html'])->toContain('Accantonato nelle casse fondo</td>')->not->toContain('Accantonato nelle casse fondo <span class="badge')->not->toContain('>quadra</span>');
    vrmGiorno($this, '2027-01-15');
    $finito = vrmTestiDelFascicolo($s, $s['e'], ['situazione'], false);
    expect($finito['situazione']['html'])->toContain('Accantonato nelle casse fondo</td>')->not->toContain('Accantonato nelle casse fondo <span class="badge');
});

it('un fondo senza sottotipo si legge «Fondo riserva · vincolato» nel § 4 della stampa, senza «sottotipo non indicato»; con il sottotipo, il sottotipo e poi il vincolo; la pagina lo dice ancora', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    foreach ([['Fondo riserva', null], ['Fondo lavori', 'vincolato_lavori'], ['Fondo libero', 'generico']] as [$nome, $sottotipo]) {
        be52Fondo($s, $nome);
        DB::table('casse')->where('condominio_id', $s['c']->id)->where('nome', $nome)->update(['sottotipo_fondo' => $sottotipo]);
    }
    $t = vrmTestiDelFascicolo($s, $s['e'], ['situazione'], false);

    expect($t['situazione']['testo'])->not->toContain('sottotipo non indicato')
        ->and($t['situazione']['testo'])->toMatch('/Fondo riserva · vincolato (€|—)/')
        ->and($t['situazione']['testo'])->toContain('Fondo lavori · vincolato ai lavori')->not->toContain('vincolato ai lavori, vincolato')->toContain('Fondo libero · generico, liberamente utilizzabile');
    // A video la pagina dice ancora il suo sottotipo, e il servizio lo dà come sempre.
    $fondi = collect($t['situazione']['dati']['pagina']['liquidita']['fondi'])->keyBy('cassa');
    expect($fondi['Fondo riserva']['sottotipo_label'])->toBe('sottotipo non indicato')->and($fondi['Fondo lavori']['sottotipo_label'])->toBe('vincolato ai lavori');
});

it('il frontespizio dice «Contiene dati personali» solo se una parte con i dati dei condòmini ha pagine: con le parti vuote la frase non c\'è', function () {
    vrmGiorno($this, '2026-01-05');
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 0]);
    $dati = null;
    View::composer('pdf.gestionale.rendiconto_frontespizio', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    // Un esercizio nuovo, senza apertura né movimenti di cassa né rate: il registro, le posizioni e i crediti sono vuoti, e il fascicolo lo dice nell'indice.
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['condomini', 'registro']);
    $html = view('pdf.gestionale.rendiconto_frontespizio', $dati)->render();

    expect(array_column($f['indice'], 'pagine'))->toBe([0, 0, 0])
        ->and(array_column($f['indice'], 'nota'))->each->toStartWith('vuota:')
        ->and($html)->not->toContain(e(\App\Services\Gestionale\PosizioniDeiCondomini::RISERVATO));
});

it('il fascicolo: una parte vuota si salta e l\'indice lo dice (nessuna rata non pagata alla data); senza spunte, tutte le parti', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $bice = vrmQuote($s, 'bice');
    vrmIncassa($this, $s, 'bice', [$bice[10] => 6750, $bice[11] => 17125, $bice[12] => 17125], '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], []);
    $indice = collect($f['indice'])->keyBy('titolo');

    expect(array_values(array_unique(array_column($f['indice'], 'parte'))))->toBe(FascicoloDelRendiconto::PARTI)
        ->and($indice['Crediti per anzianità']['nota'])->toBe('vuota: nessuna rata non pagata alla data')->and($indice['Crediti per anzianità']['pagina'])->toBeNull()
        ->and($indice['Posizioni dei condòmini']['nota'])->toBe('vuota: nessuna posizione diversa da zero alla data')
        ->and(rpgPagine($f['pdf']))->toBe(1 + array_sum(array_column($f['indice'], 'pagine')));
});

it('il tempo e la memoria del fascicolo sono stimati sul totale delle parti, con le misure delle stampe: i due fascicoli misurati al tetto non partono con 256M e 120 s; il registro al suo tetto da solo sì, a 128M e a 256M', function () {
    $tetti256 = FascicoloDelRendiconto::tetti('256M', 120);
    $tetti128 = FascicoloDelRendiconto::tetti('128M', 120);
    // Le righe dei due fascicoli misurati: il registro a 13.295 righe con i crediti a 12.000 (94,2 s) e a 14.484 (110,9 s).
    $misurato = ['registro' => 13295, 'crediti' => 12000, 'posizioni' => 1000];
    $alTetto = ['registro' => 13295, 'crediti' => 14484, 'posizioni' => 1207];

    // I tetti: la metà di 120 s; l'85 % del limite meno 78 MB di base (256 × 0,85 − 78 = 139,6; 128 × 0,85 − 78 = 30,8).
    expect($tetti256)->toEqual(['secondi' => 60.0, 'mb' => 139.6])->and($tetti128)->toEqual(['secondi' => 60.0, 'mb' => 30.8])
        ->and(FascicoloDelRendiconto::tetti('-1', 300)['secondi'])->toEqual(150.0)
        // La stima non sta sotto le misure: 5 + 13.295 × 0,0028 + 12.000 × 0,0046 + 1.000 × 0,0021 = 99,5 s; con 14.484 e 1.207 righe, 111,4 s.
        ->and(FascicoloDelRendiconto::stima($misurato)['secondi'])->toBeGreaterThanOrEqual(94.2)->and(FascicoloDelRendiconto::stima($alTetto)['secondi'])->toBeGreaterThanOrEqual(110.9)
        ->and(round(FascicoloDelRendiconto::stima($misurato)['secondi'], 1))->toBe(99.5)->and(round(FascicoloDelRendiconto::stima($alTetto)['secondi'], 1))->toBe(111.4)
        ->and(round(FascicoloDelRendiconto::stima(['registro' => 13295])['mb'], 4))->toBe(139.5975)
        // Oltre: si toglie il meno che basta. Senza i condòmini il registro al tetto ci sta (42,2 s, 139,5975 MB su 139,6); senza il registro i condòmini no
        // (62,3 s su 60). Con i crediti a 6.000 righe e il registro a 13.295 basta togliere uno dei due: il più leggero, i condòmini (0,46 del tempo e 0,46
        // della memoria contro 1,00 del registro). Con il registro e i crediti a 9.000 righe e la situazione a 15.000 fatture nessuna basta da sola (senza il
        // registro 71,9 s; senza i condòmini 192 MB; senza la situazione 71,6 s): via la più pesante, la situazione (0,698 della memoria), poi la più leggera
        // fra le due che bastano, il registro (0,677 contro 0,69).
        ->and(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, $misurato, $tetti256, '256M')['togliere'])->toBe(['condomini'])
        ->and(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, $alTetto, $tetti256, '256M')['togliere'])->toBe(['condomini'])
        ->and(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, ['registro' => 13295, 'crediti' => 6000], $tetti256, '256M')['togliere'])->toBe(['condomini'])
        ->and(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, ['registro' => 9000, 'crediti' => 9000, 'situazione' => 15000], $tetti256, '256M')['togliere'])->toBe(['situazione', 'registro'])
        // Il registro al tetto della sua stampa, da solo: 13.295 righe con 256M, 2.933 con 128M; una riga in più non ci sta.
        ->and(FascicoloDelRendiconto::troppo(['registro'], ['registro' => 13295], $tetti256, '256M'))->toBeNull()
        ->and(FascicoloDelRendiconto::troppo(['registro'], ['registro' => 2933], $tetti128, '128M'))->toBeNull()
        ->and(FascicoloDelRendiconto::troppo(['registro'], ['registro' => 2934], $tetti128, '128M'))->not->toBeNull()
        // Il tempo da solo: 60 s di stima sono 19.642 righe del registro (5 s di base); con 2G di memoria il tetto è il tempo.
        ->and(FascicoloDelRendiconto::troppo(['registro'], ['registro' => 19642], FascicoloDelRendiconto::tetti('2G', 120), '2G'))->toBeNull()
        ->and(FascicoloDelRendiconto::troppo(['registro'], ['registro' => 19643], FascicoloDelRendiconto::tetti('2G', 120), '2G')['troppo'])->toStartWith('Con il tempo che il server concede a una stampa il fascicolo non si può fare')
        ->and(FascicoloDelRendiconto::troppo(['registro', 'cassa'], ['registro' => 19643], FascicoloDelRendiconto::tetti('2G', 120), '2G')['troppo'])
        ->toBe('Il fascicolo con queste parti supererebbe il tempo che il server concede a una stampa: togli «Registro di contabilità», che si scarica da sola dalla sua scheda, e scarica di nuovo il fascicolo.')
        // Quando nessuna basta da sola si tolgono due parti, e la frase nomina quelle due, non le alternative dell'ultimo passo: tempo 97,1 s (5 + 25,2 + 41,4 + 25,5)
        // e memoria 287,4 MB (94,5 + 95,4 + 97,5), tutti e due oltre i tetti.
        ->and(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, ['registro' => 9000, 'crediti' => 9000, 'situazione' => 15000], $tetti256, '256M')['troppo'])
        ->toBe('Il fascicolo con queste parti supererebbe il tempo e la memoria che il server concede a una stampa: togli «Situazione patrimoniale» e «Registro di contabilità», '
            .'che si scaricano da sole, ognuna dalla sua scheda, e scarica di nuovo il fascicolo.')
        // Quando più parti, da sole, bastano, la frase le nomina tutte (nell'ordine del fascicolo); la più leggera è quella che il calcolo toglie.
        // 128M: tetto 30,8 MB. Riepilogo 500 × 0,007 + situazione 100 × 0,0065 + posizioni 200 × 0,0108 + crediti 300 × 0,0106 + registro 2.500 × 0,0105 = 35,74 MB.
        // Senza il registro 9,49 MB; senza i condòmini 30,40 MB: bastano tutti e due. Senza il riepilogo 32,24 e senza la situazione 35,09: no.
        ->and(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, ['riepilogo' => 500, 'situazione' => 100, 'posizioni' => 200, 'crediti' => 300, 'registro' => 2500], $tetti128, '128M'))
        ->toBe(['troppo' => 'Il fascicolo con queste parti supererebbe la memoria che il server concede a una stampa: togli una di queste: '
            .'«Condòmini: posizioni e crediti per anzianità» o «Registro di contabilità», che si scaricano da sole, ognuna dalla sua scheda, e scarica di nuovo il fascicolo.', 'togliere' => ['condomini']])
        // 256M: tetto 139,6 MB. Riepilogo, situazione e registro a 6.000 righe: 42 + 39 + 63 = 144 MB, 4,4 oltre; ognuno dei tre, tolto, basta.
        ->and(FascicoloDelRendiconto::troppo(['riepilogo', 'situazione', 'registro'], ['riepilogo' => 6000, 'situazione' => 6000, 'registro' => 6000], $tetti256, '256M'))
        ->toBe(['troppo' => 'Il fascicolo con queste parti supererebbe la memoria che il server concede a una stampa: togli una di queste: '
            .'«Riepilogo finanziario», «Situazione patrimoniale» o «Registro di contabilità», che si scaricano da sole, ognuna dalla sua scheda, e scarica di nuovo il fascicolo.', 'togliere' => ['situazione']])
        // Una parte più grande anche della sua stampa, da sola: la frase lo dice.
        ->and(FascicoloDelRendiconto::troppo(['registro', 'cassa'], ['registro' => 13296], $tetti256, '256M')['troppo'])->toEndWith('«Registro di contabilità» è più grande anche della sua stampa: la sua scheda dice come fare.');
});

it('la frase del fascicolo che non parte non dice «si scarica da sola» di una parte più grande anche della sua stampa: lo dice solo di quelle che lo fanno', function () {
    $tetti256 = FascicoloDelRendiconto::tetti('256M', 120);
    $grande = 'Il fascicolo con queste parti supererebbe la memoria che il server concede a una stampa: togli «Registro di contabilità» e scarica di nuovo il fascicolo. '
        .'«Registro di contabilità» è più grande anche della sua stampa: la sua scheda dice come fare.';

    // Una sola parte, e più grande della sua stampa (13.296 righe con 256M, una più del tetto): la sua scheda non la scarica, e la frase non lo dice.
    expect(FascicoloDelRendiconto::troppo(['registro', 'cassa'], ['registro' => 13296], $tetti256, '256M')['troppo'])->toBe($grande)
        ->and(FascicoloDelRendiconto::troppo(['registro', 'riepilogo'], ['registro' => 13296, 'riepilogo' => 10], $tetti256, '256M')['troppo'])->toBe($grande);

    // Due parti da togliere, una più grande della sua stampa e una no: «si scarica da sola» solo della seconda.
    expect(FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, ['posizioni' => 9000, 'crediti' => 9000, 'registro' => 14000], $tetti256, '256M')['troppo'])
        ->toBe('Il fascicolo con queste parti supererebbe il tempo e la memoria che il server concede a una stampa: togli «Condòmini: posizioni e crediti per anzianità» e «Registro di contabilità», e scarica di nuovo il fascicolo. '
            .'«Registro di contabilità» è più grande anche della sua stampa: la sua scheda dice come fare. '
            .'«Condòmini: posizioni e crediti per anzianità» si scarica da sola dalla sua scheda.');

    // Due parti da togliere, tutte e due più grandi della loro stampa: niente «si scaricano da sole», e il verbo è al plurale.
    $due = FascicoloDelRendiconto::troppo(FascicoloDelRendiconto::PARTI, ['situazione' => 30000, 'registro' => 14000], $tetti256, '256M')['troppo'];
    expect($due)->toContain('togli «Situazione patrimoniale» e «Registro di contabilità», e scarica di nuovo il fascicolo. ')
        ->toEndWith('«Situazione patrimoniale» e «Registro di contabilità» sono più grandi anche della loro stampa: la scheda di ognuna dice come fare.')
        ->not->toContain('si scarica da sola')->not->toContain('si scaricano da sole');
});

it('il limite di tempo del fascicolo è quello vero del processo: dove PHP concede di alzarlo si porta a 120 s, dove l\'hosting lo vieta resta il suo, e la stima conta quello', function () {
    // Un processo a parte per ogni caso: il limite e le funzioni vietate sono del processo, e la prova non li tocca in questo.
    $limite = function (string ...$ini): int {
        $comando = [PHP_BINARY];
        foreach ($ini as $i) {
            array_push($comando, '-d', $i);
        }
        array_push($comando, '-r', 'require getcwd()."/vendor/autoload.php"; echo \\App\\Services\\Gestionale\\FascicoloDelRendiconto::limiteTempoDelProcesso();');
        $processo = new \Symfony\Component\Process\Process($comando, base_path());
        $processo->run();

        return (int) $processo->getOutput();
    };

    expect($limite('max_execution_time=30'))->toBe(120)
        ->and($limite('max_execution_time=119'))->toBe(120)
        ->and($limite('max_execution_time=120'))->toBe(120)
        ->and($limite('max_execution_time=300'))->toBe(300)
        // Senza limite (da riga di comando) la stampa conta 120 s, come le stampe che lo portano a 120.
        ->and($limite('max_execution_time=0'))->toBe(120)
        // Dove l'hosting vieta di cambiarlo, il processo resta al suo limite: la stima non conta 120 s che non ha.
        ->and($limite('max_execution_time=30', 'disable_functions=set_time_limit'))->toBe(30)
        ->and($limite('max_execution_time=45', 'disable_functions=set_time_limit'))->toBe(45);
});

it('oltre il tetto il fascicolo non parte: torna alla Sintesi e dice quale parte togliere, che si scarica da sola; senza memoria per la base, dice di stampare le parti una per una', function () {
    $s = vrmScenario($this);
    // Il registro con 300 movimenti di cassa in più (60 incassi e 60 pagamenti dello stesso importo, cinque volte): 0,84 s di stima in più.
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    foreach (range(1, 300) as $i) {
        $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => '2026-05-10',
            'data_competenza' => '2026-05-10', 'numero_protocollo' => 'RPG-'.$i, 'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('righe_scritture')->insert([['scrittura_id' => $id, 'conto_contabile_id' => $banca, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'dare', 'importo' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['scrittura_id' => $id, 'conto_contabile_id' => $crediti, 'cassa_id' => null, 'tipo_riga' => 'avere', 'importo' => 100, 'created_at' => now(), 'updated_at' => now()]]);
    }
    vrmGiorno($this, '2027-01-15');
    $prima = rpgCartelle();
    // 11 s di limite: 5,5 s di stima. Il registro (più di 300 righe) li supera; tolto il registro, il resto ci sta.
    rpgLimite('limiteTempo', 11);
    try {
        $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]));
        rpgLimite('limiteMemoria', '64M');
        $base = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=cassa');
    } finally {
        rpgLimite('limiteTempo', null);
        rpgLimite('limiteMemoria', null);
    }

    $r->assertRedirect(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c']->id, $s['e']->id]));
    $base->assertRedirect(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c']->id, $s['e']->id]));
    expect(session('message')['message'] ?? '')->toBe('Con la memoria che il server concede a una stampa il fascicolo non si può fare: stampa le parti una per una, ognuna dalla sua scheda.')
        ->and(rpgCartelle())->toBe($prima);

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]));
    rpgLimite('limiteTempo', 11);
    try {
        $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]));
    } finally {
        rpgLimite('limiteTempo', null);
    }
    expect(session('message')['message'] ?? '')->toBe('Il fascicolo con queste parti supererebbe il tempo che il server concede a una stampa: togli «Registro di contabilità», che si scarica da sola dalla sua scheda, e scarica di nuovo il fascicolo.');

    // Chi ha cliccato ritrova la frase nella pagina che si apre: la richiesta del fascicolo, seguita fino alla Sintesi, la porta nel messaggio.
    rpgLimite('limiteTempo', 11);
    try {
        $pagina = $this->actingAs($this->user)->followingRedirects()->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]))->assertOk()->viewData('page');
    } finally {
        rpgLimite('limiteTempo', null);
    }
    expect($pagina['component'])->toBe('gestionale/movimenti/rendiconto/Sintesi')
        ->and($pagina['props']['flash']['message'])->toEqual(['message' => 'Il fascicolo con queste parti supererebbe il tempo che il server concede a una stampa: togli «Registro di contabilità», che si scarica da sola dalla sua scheda, e scarica di nuovo il fascicolo.', 'type' => 'error']);
});

it('all\'inizio di ogni fascicolo si tolgono le cartelle temporanee più vecchie di un\'ora (un fascicolo interrotto da fuori); quelle recenti, di un fascicolo in corso, restano', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $vecchia = FascicoloDelRendiconto::cartellaTemporanea().'/vecchia-'.uniqid();
    $recente = FascicoloDelRendiconto::cartellaTemporanea().'/recente-'.uniqid();
    $quasiUnOra = FascicoloDelRendiconto::cartellaTemporanea().'/quasi-un-ora-'.uniqid();
    $unOraEUnMinuto = FascicoloDelRendiconto::cartellaTemporanea().'/un-ora-e-un-minuto-'.uniqid();
    File::ensureDirectoryExists($vecchia);
    File::ensureDirectoryExists($recente);
    File::ensureDirectoryExists($quasiUnOra);
    File::ensureDirectoryExists($unOraEUnMinuto);
    File::put($vecchia.'/3.pdf', '%PDF posizioni');
    touch($vecchia, time() - 7200);
    // Il confine è l'ora: 59 minuti sono ancora di un fascicolo che può essere in corso (un fascicolo lento), 61 no.
    touch($quasiUnOra, time() - 59 * 60);
    touch($unOraEUnMinuto, time() - 61 * 60);
    try {
        app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['cassa']);
        expect(File::isDirectory($vecchia))->toBeFalse()->and(File::isDirectory($unOraEUnMinuto))->toBeFalse()
            ->and(File::isDirectory($recente))->toBeTrue()->and(File::isDirectory($quasiUnOra))->toBeTrue();
    } finally {
        File::deleteDirectory($vecchia);
        File::deleteDirectory($recente);
        File::deleteDirectory($quasiUnOra);
        File::deleteDirectory($unOraEUnMinuto);
    }
});

it('le righe che il fascicolo conta prima di stampare sono quelle delle stampe: il registro e i crediti per anzianità', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']);
    $righe = app(FascicoloDelRendiconto::class)->righeDelleParti(FascicoloDelRendiconto::PARTI, $s['e'], $c, true);

    // Via Roma: il riepilogo con il dettaglio 8 righe, la situazione 2 fatture aperte (FT-1 per € 500,00 e la contestata), una posizione (Bice: Ugo ha
    // pagato tutto, e le posizioni a zero non si elencano), le tre rate di Bice non pagate, il registro una riga per movimento di cassa. Il conguaglio
    // si stima prima di leggerlo: il peso della lettura (2 unità × una tabella), le righe dalle titolarità della gestione (Ugo e Bice) e il consuntivo per
    // tabella in celle (2 righe × (1 tabella + 9)).
    expect($righe)->toBe(['riepilogo' => 8, 'situazione' => 2, 'posizioni' => 1, 'crediti' => 3, 'conguaglio_lettura' => 2, 'conguaglio_persona' => 2, 'conguaglio_tabella' => 20,
        'registro' => count(app(\App\Services\Gestionale\RegistroContabilitaService::class)->registroConRiepilogo($s['e'])['righe'])]);

    expect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->numeroRighe($s['e']))
        ->toBe(count(app(\App\Services\Gestionale\RegistroContabilitaService::class)->registroConRiepilogo($s['e'])['righe']))
        ->and($c['conteggi']['rate_non_pagate'])->toBe(count(app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'])['righe']))
        ->and($c['conteggi']['rate_non_pagate'])->toBe(3)
        ->and($c['conteggi']['posizioni'])->toBe(count(app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->posizioni($s['c'], $s['e'])['righe']));
});

it('la percentuale dei costi è in millesimi, arrotondata al più vicino; senza una base non c\'è', function () {
    expect(\App\Services\Gestionale\SintesiDelRendiconto::permille(413100, 411000))->toBe(1005)
        ->and(\App\Services\Gestionale\SintesiDelRendiconto::permille(2, 3))->toBe(667)
        ->and(\App\Services\Gestionale\SintesiDelRendiconto::permille(1, 3))->toBe(333)
        ->and(\App\Services\Gestionale\SintesiDelRendiconto::permille(-2, 3))->toBe(-667)
        ->and(\App\Services\Gestionale\SintesiDelRendiconto::permille(100, null))->toBeNull()
        ->and(\App\Services\Gestionale\SintesiDelRendiconto::permille(100, 0))->toBeNull();
});

it('quando la gestione è già nell\'esercizio dopo, la Sintesi rimanda al verbale dell\'assemblea per il preventivo e la percentuale resta sul deliberato', function () {
    $s = vrmScenario($this);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e2027, $s['g']->id);
    vrmGiorno($this, '2027-01-15');
    $si = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi'];

    expect($si['riquadri']['deliberato'])->toBe(['deliberato' => 411000, 'preventivo' => null, 'solo_ordinarie' => false, 'ricostruito' => false])
        ->and($si['riquadri']['costi']['base'])->toBe('deliberato')->and($si['riquadri']['costi']['permille'])->toBe(1005)
        ->and($si['prospetto']['preventivo'])->toBeNull();
});

it('la percentuale dei costi è sul deliberato con le rate anche quando il preventivo scritto oggi sulla voce è diverso', function () {
    $s = vrmScenario($this);
    // Dopo il piano la voce «Pulizie» passa a € 3.300,00: il preventivo netto diventa € 4.410,00, il deliberato resta € 4.110,00.
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 330000]);
    vrmGiorno($this, '2027-01-15');
    $si = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi'];

    expect($si['riquadri']['deliberato'])->toBe(['deliberato' => 411000, 'preventivo' => 441000, 'solo_ordinarie' => false, 'ricostruito' => false])
        ->and($si['riquadri']['costi']['base'])->toBe('deliberato')->and($si['riquadri']['costi']['permille'])->toBe(1005);
});

it('con una gestione straordinaria il preventivo è delle sole gestioni ordinarie e lo dice; senza il deliberato non c\'è una percentuale, perché i costi comprendono anche la straordinaria', function () {
    $s = vrmScenario($this);
    $facciata = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $facciata->id);
    $pcF = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $facciata->id, 'nome' => 'Piano facciata']);
    be52Voce($this, $s, $pcF, 'Ponteggio', 'spesa', '5.000,00');
    vrmGiorno($this, '2027-01-15');
    $conDeliberato = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi'];
    // Il piano senza le righe del riparto, come quelli generati prima del 14/09/2026, che con i millesimi cambiati dopo l'emissione non si ricostruisce più
    // (Coda 557): il deliberato non è conservato.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('quote_tabella')->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    $senza = rpgProps($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['sintesi'];

    expect($conDeliberato['riquadri']['deliberato'])->toBe(['deliberato' => 411000, 'preventivo' => 411000, 'solo_ordinarie' => true, 'ricostruito' => false])
        ->and($conDeliberato['riquadri']['costi']['base'])->toBe('deliberato')->and($conDeliberato['riquadri']['costi']['permille'])->toBe(1005)
        ->and($senza['riquadri']['deliberato'])->toBe(['deliberato' => null, 'preventivo' => 411000, 'solo_ordinarie' => true, 'ricostruito' => false])
        ->and($senza['prospetto']['preventivo'])->toBe(411000)->and($senza['prospetto']['preventivo_solo_ordinarie'])->toBeTrue()
        ->and($senza['riquadri']['costi']['permille'])->toBeNull()->and($senza['riquadri']['costi']['base'])->toBeNull();
});

it('le righe della stampa del riepilogo: una per voce, e con il dettaglio una per fattura o movimento (Via Roma: 4, e 8 con il dettaglio)', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);

    expect(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::righeDellaStampa($r, false))->toBe(4)
        ->and(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::righeDellaStampa($r, true))->toBe(8);
});

it('oltre il suo tetto la stampa del riepilogo non parte e torna alla scheda con il numero delle righe', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    (new ReflectionProperty(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::class, 'limiteMemoria'))->setValue(null, '56M');
    try {
        $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$s['c'], $s['e']]).'?dettaglio=1');
    } finally {
        (new ReflectionProperty(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::class, 'limiteMemoria'))->setValue(null, null);
    }

    $r->assertRedirect(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $s['e']]));
    expect(session('message')['message'] ?? '')->toContain('Il riepilogo con il dettaglio per spesa ha 8 righe');
});

it('il fascicolo del primo esercizio, prima di ogni movimento di cassa e con la banca a zero: il registro vuoto si salta e l\'indice lo dice', function () {
    vrmGiorno($this, '2026-01-05');
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 0]);
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['registro', 'cassa']);
    $indice = collect($f['indice'])->keyBy('parte');

    expect($indice['registro']['nota'])->toBe('vuota: nessun movimento di cassa nell\'esercizio')->and($indice['registro']['pagina'])->toBeNull()
        ->and($indice['cassa']['pagina'])->toBe(2)->and(rpgPagine($f['pdf']))->toBe(1 + $indice['cassa']['pagine']);
});

it('le rate non pagate che il fascicolo conta sono le righe del § 10 anche quando una rata pagata porta un credito (Ugo, € 50,00 in più sulla rata 3)', function () {
    $s = vrmScenario($this, 5000);
    vrmGiorno($this, '2027-01-15');
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']);

    expect($c['conteggi']['rate_non_pagate'])->toBe(count(app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->crediti($s['c'], $s['e'])['righe']))
        ->and($c['conteggi']['rate_non_pagate'])->toBe(3);
});

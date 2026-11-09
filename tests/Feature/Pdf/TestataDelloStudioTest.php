<?php

/**
 * La testata dello studio sulle stampe dell'esercizio, dalla rotta di ognuna, sul condominio «Via Roma» (`RendicontoViaRomaScenario`).
 *
 * Con lo studio, in testa alla prima pagina: il logo, lo studio con tutti i suoi dati (nome, indirizzo, partita IVA, codice fiscale, telefono, email,
 * PEC, sito) e il condominio con il suo codice fiscale; dalla seconda pagina una riga con lo studio e il condominio, senza il logo. Il modello F24
 * non ha lo studio. Ogni stampa dice il suo gruppo: il registro di contabilità cambia gruppo con la copia di controllo; il fascicolo passa il gruppo
 * del rendiconto a tutte le sue parti, e le parti dopo il frontespizio cominciano con la riga dello studio.
 *
 * Senza lo studio (il nome vuoto, anche con un logo caricato), ogni stampa resta com'è: nessun dato dello studio, nessun logo, il condominio in
 * testa, la configurazione di oggi. Le eccezioni volute: l'estratto conto a 32 mm (a 30 la testata scendeva sotto il margine), le rate scadute a
 * 38 mm (a 32 il filetto della testata con la riga del titolo attraversava il titolo del corpo), il libro mastro con la riga in testa dalla
 * seconda pagina e il piede con il numero di pagina.
 */

use App\Models\Gestionale\DelegaF24;
use App\Services\PDF\GruppoCarta;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('public');
});

/** Via Roma al 15/01/2027, con il condominio che ha indirizzo e codice fiscale noti. */
function tdsScenario($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $s['c']->update(['indirizzo' => 'Via Roma 23, 20100 Milano', 'codice_fiscale' => '90012345678']);
    $s['c'] = $s['c']->fresh();

    return $s;
}

/** Le stampe dell'esercizio: [nome, indirizzo, vista, gruppo atteso, famiglia]. La famiglia: T0 (`pdf.base`), TI (le viste Inter), F24. */
function tdsStampe(array $s): array
{
    $c = $s['c'];
    $e = $s['e'];
    $r = fn (string $nome, array $parametri, array $query = []) => route('admin.gestionale.'.$nome, $parametri).($query ? '?'.http_build_query($query) : '');
    $A = GruppoCarta::RENDICONTO_E_ASSEMBLEA;
    $P = GruppoCarta::PER_LA_PERSONA;
    $R = GruppoCarta::REGISTRI_E_ELENCHI;

    return [
        ['scadenziario', $r('esercizi.piani-rate.print-scadenziario', [$c, $e, $s['piano']]), 'pdf.gestionale.prospetto_rate', $A, 'T0'],
        ['scadenziario per unità', $r('esercizi.piani-rate.print-scadenziario', [$c, $e, $s['piano']], ['modalita' => 'entrambi']), 'pdf.gestionale.prospetto_rate', $A, 'T0'],
        ['riparto per tabella', $r('esercizi.piani-rate.print-riparto-tabelle', [$c, $e, $s['piano']]), 'pdf.gestionale.riparto_tabelle', $A, 'T0'],
        ['riparto per capitolo', $r('esercizi.piani-rate.print-riparto-capitoli', [$c, $e, $s['piano']]), 'pdf.gestionale.riparto_capitoli', $A, 'T0'],
        ['distinta base', $r('esercizi.piani-conti.print-distinta', [$c, $e, $s['pc']]), 'pdf.gestionale.distinta_spese', $A, 'T0'],
        ['ripartizione spese', $r('esercizi.piani-conti.print-riparto', [$c, $e, $s['pc']]), 'pdf.gestionale.ripartizione_spese', $A, 'T0'],
        ['estratto conto', $r('anagrafiche.estratto-conto.print', [$c, $s['persone']['bice']]), 'pdf.gestionale.estratto_conto_anagrafica', $P, 'T0'],
        ['prospetto oneri', $r('immobili.prospetto-oneri', [$c, $s['unita']['A'], 'esercizio' => $e->id]), 'pdf.gestionale.prospetto_oneri_accessori', $P, 'T0'],
        ['rate scadute', $r('rate-scadute.stampa', [$c]), 'pdf.gestionale.rate_scadute', $R, 'T0'],
        ['distinta del pagamento', $r('pagamenti-fornitori.distinta', [$c, $s['pag_ft1']]), 'pdf.gestionale.distinta', $R, 'T0'],
        ['modello F24', $r('f24.modello', [$c, DelegaF24::where('condominio_id', $c->id)->firstOrFail()]), 'pdf.gestionale.modello_f24', GruppoCarta::NESSUNO, 'F24'],
        ['libro giornale', $r('esercizi.scritture.print', [$c, $e]), 'pdf.gestionale.libro_giornale', $R, 'T0'],
        ['registro per l\'assemblea', $r('esercizi.registro-contabilita.print', [$c, $e]), 'pdf.gestionale.registro_contabilita', $A, 'TI'],
        ['registro, copia di controllo', $r('esercizi.registro-contabilita.print', [$c, $e], ['annotazioni' => 1]), 'pdf.gestionale.registro_contabilita', $R, 'TI'],
        ['mastrino', $r('esercizi.conti.movimenti.print', [$c, $e, $s['cassa']->conto_contabile_id]), 'pdf.gestionale.mastrino', $R, 'TI'],
        ['libro mastro', $r('esercizi.libro-mastro.print', [$c, $e]), 'pdf.gestionale.libro_mastro', $R, 'TI'],
        ['situazione patrimoniale', $r('esercizi.stato-patrimoniale.print', [$c, $e], ['parte' => 'situazione']), 'pdf.gestionale.stato_patrimoniale', $A, 'TI'],
        ['stato patrimoniale intero', $r('esercizi.stato-patrimoniale.print', [$c, $e]), 'pdf.gestionale.stato_patrimoniale', $A, 'TI'],
        ['entrate e uscite per cassa', $r('esercizi.stato-patrimoniale.print', [$c, $e], ['parte' => 'cassa']), 'pdf.gestionale.stato_patrimoniale', $A, 'TI'],
        ['controlli del rendiconto', $r('esercizi.stato-patrimoniale.print', [$c, $e], ['parte' => 'controlli']), 'pdf.gestionale.stato_patrimoniale', $A, 'TI'],
        ['riepilogo finanziario', $r('esercizi.rendiconto.riepilogo.stampa', [$c, $e]), 'pdf.gestionale.rendiconto_riepilogo', $A, 'TI'],
        ['riepilogo con il dettaglio', $r('esercizi.rendiconto.riepilogo.stampa', [$c, $e], ['dettaglio' => 1]), 'pdf.gestionale.rendiconto_riepilogo', $A, 'TI'],
        ['posizioni dei condòmini', $r('esercizi.rendiconto.condomini.posizioni.stampa', [$c, $e]), 'pdf.gestionale.rendiconto_posizioni', $A, 'T0'],
        ['crediti per anzianità', $r('esercizi.rendiconto.condomini.crediti.stampa', [$c, $e]), 'pdf.gestionale.rendiconto_crediti', $A, 'T0'],
    ];
}

/** Una stampa dalla sua rotta: il PDF e la stampa che la spia si ricorda. */
function tdsStampa($test, $spia, string $indirizzo): array
{
    $prima = count($spia->stampe);
    $risposta = $test->actingAs($test->user)->get($indirizzo);
    $risposta->assertOk();
    // La distinta del pagamento esce come scaricamento: il corpo è uno stream.
    $pdf = (string) ($risposta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $risposta->streamedContent() : $risposta->getContent());
    expect(substr($pdf, 0, 4))->toBe('%PDF');

    return [$pdf, $spia->stampe[$prima] ?? null];
}

it('con lo studio, ogni stampa dell\'esercizio ha in testa alla prima pagina il logo, lo studio con i suoi dati e il condominio con il C.F.; dalla seconda pagina lo studio e il condominio in una riga, senza il logo; il modello F24 niente; ogni stampa dice il suo gruppo', function () {
    $s = tdsScenario($this);
    cdsStudioPieno(true);
    $spia = cdsSpia();
    $male = [];
    $seguenti = 0;

    foreach (tdsStampe($s) as [$nome, $indirizzo, $vista, $gruppo, $famiglia]) {
        [$pdf, $stampa] = tdsStampa($this, $spia, $indirizzo);
        $prima = cdsTesto($pdf, 1);
        if (($stampa['vista'] ?? null) !== $vista || ($stampa['dati']['gruppo_carta'] ?? null) !== $gruppo) {
            $male[] = "{$nome}: la vista o il gruppo ({$vista}, {$gruppo->value})";
        }
        if ($famiglia === 'F24') {
            if (str_contains($prima, 'Studio Bianchi') || str_contains($prima, 'P.IVA 01234567890')) {
                $male[] = "{$nome}: lo studio sul modello F24";
            }

            continue;
        }
        foreach (['Studio Bianchi Amministrazioni', 'Via Garibaldi 10, 20121 Milano', 'P.IVA 01234567890 · C.F. BNCLRA80A01F205X',
            'Tel. 02 1234567 · Email studio@bianchi.it · PEC studio@pec.bianchi.it · www.studiobianchi.it', $s['c']->nome, 'C.F. 90012345678'] as $atteso) {
            if (! str_contains($prima, $atteso)) {
                $male[] = "{$nome}: la prima pagina non ha «{$atteso}»";
            }
        }
        if (! cdsHaImmagine($pdf, 1)) {
            $male[] = "{$nome}: la prima pagina non ha il logo";
        }
        if (($stampa['mpdf']->setAutoTopMargin ?? null) !== 'stretch') {
            $male[] = "{$nome}: senza la rete dello spazio";
        }
        if (cdsNumeroPagine($pdf) > 1) {
            $seguenti++;
            $seconda = cdsTesto($pdf, 2);
            if (! str_contains($seconda, 'Studio Bianchi Amministrazioni') || ! str_contains($seconda, $s['c']->nome)) {
                $male[] = "{$nome}: la seconda pagina non ha la riga dello studio e del condominio";
            }
            if (str_contains($seconda, 'P.IVA') || cdsHaImmagine($pdf, 2)) {
                $male[] = "{$nome}: la seconda pagina ripete il blocco intero";
            }
        }
    }

    expect($male)->toBe([])->and($seguenti)->toBeGreaterThan(2);
});

it('senza lo studio, anche con un logo caricato, ogni stampa resta com\'è: niente studio né logo, il condominio in testa, la configurazione di oggi, con la rete dello spazio sulla testata comune', function () {
    $s = tdsScenario($this);
    cdsSenzaStudio(true);
    $spia = cdsSpia();
    $male = [];

    foreach (tdsStampe($s) as [$nome, $indirizzo, $vista, $gruppo, $famiglia]) {
        [$pdf, $stampa] = tdsStampa($this, $spia, $indirizzo);
        $prima = cdsTesto($pdf, 1);
        if (str_contains($prima, 'P.IVA') || str_contains($prima, 'www.studiobianchi.it')) {
            $male[] = "{$nome}: i dati dello studio senza lo studio";
        }
        if ($famiglia !== 'F24' && cdsHaImmagine($pdf, 1)) {
            $male[] = "{$nome}: il logo senza lo studio";
        }
        // La testata comune ha la rete dello spazio anche senza lo studio: con un nome lungo il corpo comincia sotto il filetto.
        if (($stampa['mpdf']->setAutoTopMargin ?? false) !== ($famiglia === 'T0' ? 'stretch' : false)) {
            $male[] = "{$nome}: la rete dello spazio";
        }
        // La testata di oggi: il nome del condominio in cima alla pagina (in T0 nella testata di pagina, in TI nel corpo, accanto all'esercizio).
        if ($famiglia !== 'F24' && $nome !== 'registro, copia di controllo' && ! cdsInCima($pdf, 1, $s['c']->nome)) {
            $male[] = "{$nome}: il condominio non è in cima alla prima pagina";
        }
    }

    expect($male)->toBe([]);
});

it('il fascicolo: il gruppo del rendiconto a tutte le parti; il blocco intero dello studio solo nel frontespizio, le altre parti cominciano con la riga dello studio', function () {
    $s = tdsScenario($this);
    cdsStudioPieno(true);
    $spia = cdsSpia();

    [$pdf] = tdsStampa($this, $spia, route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]));

    $parti = array_values(array_filter($spia->stampe, fn ($x) => $x['vista'] !== 'pdf.gestionale.rendiconto_frontespizio'));
    $frontespizi = array_values(array_filter($spia->stampe, fn ($x) => $x['vista'] === 'pdf.gestionale.rendiconto_frontespizio'));
    expect($parti)->not->toBe([])
        ->and(array_unique(array_map(fn ($x) => $x['dati']['gruppo_carta'] ?? null, $spia->stampe), SORT_REGULAR))->toBe([GruppoCarta::RENDICONTO_E_ASSEMBLEA])
        ->and(array_unique(array_map(fn ($x) => (bool) ($x['dati']['comincia_con_la_testatina'] ?? false), $parti)))->toBe([true])
        ->and(array_unique(array_map(fn ($x) => (bool) ($x['dati']['comincia_con_la_testatina'] ?? false), $frontespizi)))->toBe([false]);

    $pagine = cdsNumeroPagine($pdf);
    expect(cdsHaImmagine($pdf, 1))->toBeTrue()
        ->and(cdsTesto($pdf, 1))->toContain('P.IVA 01234567890')->toContain('Studio Bianchi Amministrazioni')->toContain($s['c']->nome);
    for ($p = 2; $p <= $pagine; $p++) {
        $testo = cdsTesto($pdf, $p);
        expect(cdsHaImmagine($pdf, $p))->toBeFalse("pagina {$p}: il logo")
            ->and($testo)->not->toContain('P.IVA')
            ->and($testo)->toContain('Studio Bianchi Amministrazioni');
    }
});

it('il libro mastro ha la riga in testa dalla seconda pagina e il piede con il numero di pagina, anche senza lo studio', function () {
    $s = tdsScenario($this);
    cdsSenzaStudio();
    $spia = cdsSpia();

    [$pdf] = tdsStampa($this, $spia, route('admin.gestionale.esercizi.libro-mastro.print', [$s['c'], $s['e']]));

    $n = cdsNumeroPagine($pdf);
    expect($n)->toBeGreaterThan(1)
        ->and(cdsTesto($pdf, 1))->toContain("Pagina 1 di {$n}")->toContain('Documento emesso il')
        ->and(cdsTesto($pdf, 2))->toContain("Pagina 2 di {$n}")
        ->and(cdsInCima($pdf, 2, $s['c']->nome))->toBeTrue();
});

it('le rate scadute: la testata con la riga del titolo sta nei 38 mm del margine; l\'estratto conto: la testata sta nei 32 mm; senza lo studio', function () {
    $s = tdsScenario($this);
    cdsSenzaStudio();
    $spia = cdsSpia(true);

    foreach ([[route('admin.gestionale.rate-scadute.stampa', [$s['c']]), 38], [route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['bice']]), 32]] as [$indirizzo, $margine]) {
        [$pdf, $stampa] = tdsStampa($this, $spia, $indirizzo);
        // Il filetto della testata di oggi, letto nella pagina: deve stare sopra il margine dove comincia il corpo.
        $filetto = tdsFilettoDellaTestata($pdf);
        expect($stampa['opzioni']['margin_top'])->toBe($margine)
            ->and($filetto)->toBeLessThan($margine - 0.5);
    }
});

/** Il filetto della testata comune (o di quella dello studio): il primo filetto lungo della pagina, dall'alto. */
function tdsFilettoDellaTestata(string $pdf, int $pagina = 1): float
{
    $filetti = cdsFiletti($pdf, $pagina, 150.0);
    expect($filetti)->not->toBe([], 'la pagina non ha il filetto della testata');

    return $filetti[0][0];
}

it('senza lo studio, con il nome del condominio di 60 caratteri, il corpo comincia sotto il filetto della testata: la distinta, l\'estratto conto, il prospetto degli oneri, un riparto; con il nome di oggi la testata non sposta il corpo', function () {
    $s = tdsScenario($this);
    cdsSenzaStudio();
    $spia = cdsSpia();
    $stampe = [
        'distinta base' => [route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]), 'DISTINTA DELLE SPESE', 14],
        'estratto conto' => [route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['bice']]), 'ESTRATTO CONTO', 13],
        'prospetto oneri' => [route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $s['unita']['A'], 'esercizio' => $s['e']->id]), 'PROSPETTO DEGLI ONERI', 13],
        'riparto per tabella' => [route('admin.gestionale.esercizi.piani-rate.print-riparto-tabelle', [$s['c'], $s['e'], $s['piano']]), 'RIPARTO BILANCIO', 11],
    ];

    // Il nome di oggi: la testata sta nel margine, il corpo comincia dove cominciava.
    foreach ($stampe as $nome => [$indirizzo]) {
        [, $stampa] = tdsStampa($this, $spia, $indirizzo);
        expect($stampa['mpdf']->setAutoTopMargin)->toBe('stretch', $nome)
            ->and((float) $stampa['margine_alto'])->toEqual((float) $stampa['opzioni']['margin_top'], $nome);
    }

    $s['c']->update(['nome' => CDS_CONDOMINIO_LUNGO]);
    $male = [];
    foreach ($stampe as $nome => [$indirizzo, $titolo, $corpoPt]) {
        [$pdf] = tdsStampa($this, $spia, $indirizzo);
        $filetto = tdsFilettoDellaTestata($pdf);
        $riga = cdsDove($pdf, 1, $titolo)[1] ?? null;
        // La riga di base del titolo, meno l'altezza delle maiuscole (tre quarti del corpo del carattere): la cima del titolo, sotto il filetto.
        $cima = $riga === null ? null : $riga - 0.75 * $corpoPt * 25.4 / 72;
        if ($cima === null || $cima < $filetto) {
            $male[] = sprintf('%s: il filetto a %.2f mm, la cima del titolo a %s mm', $nome, $filetto, $cima === null ? '—' : sprintf('%.2f', $cima));
        }
    }
    expect($male)->toBe([]);
});

it('il comune dello studio sta nella testata accanto all\'indirizzo, e non si ripete se l\'indirizzo lo dice già: la testata comune, quella del registro, il frontespizio', function () {
    $s = tdsScenario($this);
    $spia = cdsSpia();
    $stampe = [
        'distinta base' => route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]),
        'registro' => route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]),
        'fascicolo' => route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]),
    ];

    $male = [];
    cdsStudioPieno(true, ['studio_indirizzo' => 'Via Garibaldi 10, 20121', 'studio_comune' => 'Monza']);
    foreach ($stampe as $nome => $indirizzo) {
        [$pdf] = tdsStampa($this, $spia, $indirizzo);
        if (! str_contains(cdsTesto($pdf, 1), 'Via Garibaldi 10, 20121, Monza P.IVA')) {
            $male[] = "{$nome}: il comune non è accanto all'indirizzo";
        }
    }

    cdsStudioPieno(true, ['studio_indirizzo' => 'Via Garibaldi 10, 20121 MILANO', 'studio_comune' => ' milano ']);
    foreach ($stampe as $nome => $indirizzo) {
        [$pdf] = tdsStampa($this, $spia, $indirizzo);
        if (! str_contains(cdsTesto($pdf, 1), 'Via Garibaldi 10, 20121 MILANO P.IVA')) {
            $male[] = "{$nome}: il comune che l'indirizzo dice già è ripetuto";
        }
    }
    expect($male)->toBe([]);
});

it('con lo studio, una PEC di 78 caratteri e un sito di 80 vanno a capo dentro la colonna dello studio, senza caratteri invisibili nel testo: il condominio resta dov\'era, nella testata comune, in quella del registro e nel frontespizio', function () {
    $s = tdsScenario($this);
    $s['c']->update(['nome' => CDS_CONDOMINIO_LUNGO]);
    $spia = cdsSpia();
    $stampe = [
        'la distinta' => route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]),
        'il registro' => route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]),
        'il frontespizio' => route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]),
    ];
    $email = 'segreteria.amministrazioni@bianchi.it';
    $pec = 'amministrazione.studiobianchirossiverdiassociati@pec.ordineavvocatimilano.it';

    cdsStudioPieno(true);
    $corte = array_map(fn (string $indirizzo) => tdsStampa($this, $spia, $indirizzo)[0], $stampe);
    cdsStudioPieno(true, ['studio_email' => $email, 'studio_pec' => $pec,
        'studio_sito' => 'https://www.studioassociatobianchirossiverdiamministrazionicondominiali.it/home']);
    $male = [];
    foreach ($stampe as $nome => $indirizzo) {
        [$lunga] = tdsStampa($this, $spia, $indirizzo);
        $testo = cdsTesto($lunga, 1);
        if (cdsDove($lunga, 1, 'Condominio Residenziale') != cdsDove($corte[$nome], 1, 'Condominio Residenziale')) {
            $male[] = "{$nome}: il condominio si è spostato";
        }
        if (str_contains($testo, "\u{200B}")) {
            $male[] = "{$nome}: un carattere invisibile nel testo";
        }
        if (! str_contains($testo, "Email {$email}")) {
            $male[] = "{$nome}: l'email non è intera";
        }
        // La PEC va a capo dove la colonna finisce: lette di seguito, le righe la danno intera.
        if (! str_contains(str_replace(' ', '', $testo), $pec)) {
            $male[] = "{$nome}: la PEC non c'è";
        }
    }
    expect($male)->toBe([]);
});

it('con lo studio, la riga del titolo della stampa: nella testata della prima pagina, sopra il filetto, e nella riga delle pagine seguenti', function () {
    $s = tdsScenario($this);
    cdsStudioPieno(true);
    $spia = cdsSpia();

    [$giornale] = tdsStampa($this, $spia, route('admin.gestionale.esercizi.scritture.print', [$s['c'], $s['e']]));
    [$scadute] = tdsStampa($this, $spia, route('admin.gestionale.rate-scadute.stampa', [$s['c']]));

    $sopraIlFiletto = function (string $pdf, int $pagina, string $titolo): bool {
        $filetto = tdsFilettoDellaTestata($pdf, $pagina);
        foreach (cdsPagine($pdf)[$pagina - 1] as [$testo, , $y]) {
            if (str_starts_with($testo, $titolo) && $y < $filetto) {
                return true;
            }
        }

        return false;
    };
    expect(cdsNumeroPagine($giornale))->toBeGreaterThan(1)
        ->and($sopraIlFiletto($giornale, 1, 'Libro Giornale – '))->toBeTrue('il libro giornale, pagina 1')
        ->and($sopraIlFiletto($scadute, 1, 'Rate scadute al '))->toBeTrue('le rate scadute, pagina 1')
        ->and($sopraIlFiletto($giornale, 2, 'Libro Giornale – '))->toBeTrue('il libro giornale, pagina 2');
});

it('con lo studio, un logo largo (4:1) si stampa nella testata largo al massimo 40 mm, e alto in proporzione', function () {
    $s = tdsScenario($this);
    cdsStudioPieno(false);
    Storage::disk('public')->put('settings/logo/largo.png', cdsPng(800, 200));
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->logo_stampe_path = 'settings/logo/largo.png';
    $impostazioni->save();
    $spia = cdsSpia();

    [$pdf] = tdsStampa($this, $spia, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    [$registro] = tdsStampa($this, $spia, route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]));
    foreach (['testata comune' => $pdf, 'registro' => $registro] as $nome => $stampa) {
        [$larghezza, $altezza] = cdsImmagini($stampa, 1)[0] ?? [null, null];
        expect($larghezza)->toEqualWithDelta(40.0, 0.3, $nome)->and($altezza)->toEqualWithDelta(10.0, 0.3, $nome);
    }
});

it('il piede di oggi su ogni pagina, con lo studio e senza: la data dell\'emissione e il numero della pagina anche dopo la prima', function () {
    $s = tdsScenario($this);
    $spia = cdsSpia();
    $male = [];
    foreach (['senza lo studio' => false, 'con lo studio' => true] as $forma => $conStudio) {
        $conStudio ? cdsStudioPieno(true) : cdsSenzaStudio();
        foreach (['libro giornale' => route('admin.gestionale.esercizi.scritture.print', [$s['c'], $s['e']]),
            'scadenziario' => route('admin.gestionale.esercizi.piani-rate.print-scadenziario', [$s['c'], $s['e'], $s['piano'], 'modalita' => 'entrambi'])] as $nome => $indirizzo) {
            [$pdf] = tdsStampa($this, $spia, $indirizzo);
            $n = cdsNumeroPagine($pdf);
            if ($n < 2) {
                $male[] = "{$nome}, {$forma}: una pagina sola";
            }
            for ($p = 1; $p <= $n; $p++) {
                $testo = cdsTesto($pdf, $p);
                if (! str_contains($testo, "Pagina {$p} di {$n}") || ! str_contains($testo, 'Documento emesso il')) {
                    $male[] = "{$nome}, {$forma}: la pagina {$p} senza il piede";
                }
            }
        }
    }
    expect($male)->toBe([]);
});

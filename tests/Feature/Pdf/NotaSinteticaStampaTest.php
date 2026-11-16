<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La stampa della nota sintetica, da sola e nel fascicolo, sul condominio «Via Roma» (`RendicontoViaRomaScenario`) al 01/03/2027.
 *
 * Nei tre casi della carta (senza studio, con lo studio e il logo, con la carta intestata): le parti con qualcosa, nel loro ordine; il testo
 * dell'amministratore come testo (un tag scritto nel testo esce com'è); il simbolo dell'euro mai da solo in fondo a una riga; una parte vuota non si
 * stampa. Nel fascicolo la nota è la prima parte dopo il frontespizio, nell'indice con la sua pagina; quando la nota non c'è il frontespizio lo dice.
 */

use App\Models\CartaStampa;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\PDF\CartaDiStampa;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

beforeEach(function () {
    $this->user = be52Utente();
    Permission::findOrCreate(\App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'web');
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('local');
    Storage::fake('public');
});

/** Via Roma al 01/03/2027. */
function nssScenario($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-03-01');

    return $s;
}

/** Una carta intestata in immagine A4 per il gruppo del rendiconto (come le prove della carta intestata): la fascia in alto, i margini delle prove. */
function nssCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 122, 31, 31));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-della-nota.png', (string) ob_get_clean());
    $carta = CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-della-nota.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

/** La nota delle prove: tutti i fatti tenuti, un testo con un tag, un testo lungo, i due elenchi, la dichiarazione, una cosa da decidere; «Altre informazioni» tolte. */
function nssNota(array $s, array $altro = []): array
{
    $tutti = [];
    foreach (app(\App\Services\Gestionale\FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']))['fatti'] as $f) {
        $tutti[$f['chiave']] = ['stato' => $f['parte'] === 'altre' ? 'tolto' : 'tenuto'];
    }

    return array_replace_recursive([
        'fatti' => $tutti,
        'parti' => [
            'criteri' => ['testo' => "Il condominio ha una sola tabella, quella dei millesimi di proprietà.\nTutte le spese comuni sono divise con quella. <script>alert(1)</script> <b>grassetto</b>"],
            'gestione' => ['testo' => trim(str_repeat('Il canone delle pulizie è salito con l\'indice ISTAT come dice il contratto. ', 64))],
        ],
        'cause' => [(string) $s['pulizie']->id => 'Il contratto delle pulizie è cambiato a marzo.'],
        'contratti' => [
            ['tipo' => 'contratto', 'cosa' => 'Pulizie delle scale', 'con_chi' => 'Pulizie Lucenti', 'importo' => 300000, 'scadenza' => '2027-12-31', 'rinnovo' => 'tacito, disdetta tre mesi prima'],
            ['tipo' => 'polizza', 'cosa' => 'Globale fabbricati', 'con_chi' => 'Assicurazioni Prova', 'importo' => 123456789, 'scadenza' => '2027-06-30', 'note' => 'Massimale € 2.000.000,00 per sinistro'],
        ],
        'dipendenti' => ['stato' => 'nessuno'],
        'debiti_certi' => [['creditore' => 'Studio legale Prova', 'importo' => 350000, 'fonte' => 'sentenza del Tribunale di Milano', 'scadenza' => '2027-03-31']],
        'dichiarazione' => ['liti' => ['stato' => 'nessuna'], 'impugnazioni' => ['stato' => 'nessuna'], 'sinistri' => ['stato' => 'ci_sono', 'testo' => 'Una infiltrazione dal tetto, denunciata il 10/12/2026.']],
        'da_decidere' => ['Se fare la polizza per la responsabilità civile del consiglio'],
    ], $altro);
}

/** Il testo di un PDF con pdftotext, riga per riga com'è disposto. */
function nssTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'nss').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return $testo;
}

/** Le immagini delle pagine, a 70 dpi, nella cartella data dall'ambiente (`NOTA_IMMAGINI`), per guardarle. */
function nssImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('NOTA_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png '.escapeshellarg("{$cartella}/{$nome}.pdf").' '.escapeshellarg("{$cartella}/{$nome}"));
}

function nssPdf($test, string $indirizzo): string
{
    $r = $test->actingAs($test->user)->get($indirizzo);
    $r->assertOk();

    return (string) $r->getContent();
}

dataset('carte della nota', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => nssCartaIntestata(),
]);

it('la nota da sola nei tre casi della carta: le parti con qualcosa, il testo come testo, il simbolo dell\'euro legato alla cifra', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è');
    }
    $s = nssScenario($this);
    $carta();
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(nssNota($s)), $this->user);
    $pdf = nssPdf($this, route('admin.gestionale.esercizi.rendiconto.nota.stampa', [$s['c'], $s['e']]));
    nssImmagini($pdf, 'nota-'.preg_replace('/\W+/', '-', $this->dataName()));
    $testo = nssTesto($pdf);
    $piatto = preg_replace('/\s+/u', ' ', $testo);

    expect($piatto)->toContain('Nota sintetica esplicativa')
        ->toContain('1. I criteri del rendiconto')->toContain('2. La gestione dell\'anno')->toContain('3. I rapporti in corso')->toContain('4. Le questioni pendenti')
        // «Altre informazioni»: i suoi due fatti tolti, nessun testo: la parte non si stampa.
        ->not->toContain('5. Altre informazioni')
        // Il testo dell'amministratore è testo.
        ->toContain('<script>alert(1)</script> <b>grassetto</b>')
        ->toContain('Il contratto delle pulizie è cambiato a marzo.')
        ->toContain('L\'amministratore dichiara che non ci sono cause in corso, né del condominio né contro il condominio e che nessuna delibera dell\'assemblea è stata impugnata.')
        // La risposta e il testo dell'amministratore sono due frasi: il suo testo comincia com'è scritto.
        ->toContain('Ci sono sinistri aperti. Una infiltrazione dal tetto')
        // I titoli degli elenchi sono in maiuscoletto.
        ->toContain('ALTRI DEBITI CERTI')->toContain('Studio legale Prova')
        ->toContain('DA DECIDERE IN ASSEMBLEA')->toContain('La proposta di conguaglio:')
        ->toContain('Il condominio non ha dipendenti.')
        ->toContain('Polizza: Globale fabbricati');
    // Il simbolo dell'euro non resta mai da solo in fondo a una riga.
    foreach (preg_split('/\R/u', $testo) as $riga) {
        expect(preg_match('/€\s*$/u', $riga))->toBe(0, $riga);
    }
    expect(substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'))->toBeGreaterThanOrEqual(2);
})->with('carte della nota');

it('gli elenchi: il titolo nella testa della sua tabella, che mPDF non lascia da solo in fondo alla pagina; la cifra con il carattere della riga', function () {
    $s = nssScenario($this);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(nssNota($s)), $this->user);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $html = view('pdf.gestionale.rendiconto_nota', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'nota' => $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e'])),
        'gruppo_carta' => \App\Services\PDF\GruppoCarta::RENDICONTO_E_ASSEMBLEA])->render();
    $piatto = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $html));
    foreach (['Contratti, polizze e verifiche', 'Liti, impugnazioni e sinistri', 'Altri debiti certi'] as $titolo) {
        expect($piatto)->toMatch('/<thead> ?<tr><th[^>]*class="elenco"[^>]*>'.preg_quote($titolo, '/').'<\/th>/u');
    }
    // L'importo di un contratto e di un debito: la classe della cifra della riga, non quella dei titoli («n», il carattere dei titoli in grassetto).
    expect($piatto)->toContain('<td class="cifra">€ 3.000,00</td>')->toContain('<td class="cifra">€ 3.500,00</td>')
        ->not->toContain('<td class="n">€ 3.000,00</td>');
});

it('il testo di una parte oltre i 5.000 caratteri e un elenco oltre le 30 righe si rifiutano; a 5.000 si salva', function () {
    $s = nssScenario($this);
    $rotta = route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]);
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put($rotta, ['contenuto' => ['parti' => ['altre' => ['testo' => str_repeat('a', 5001)]]]])->assertSessionHasErrors('contenuto.parti.altre.testo');
    $this->actingAs($this->user)->put($rotta, ['contenuto' => ['da_decidere' => array_fill(0, 31, 'x')]])->assertSessionHasErrors('contenuto.da_decidere');
    $this->actingAs($this->user)->put($rotta, ['contenuto' => ['parti' => ['altre' => ['testo' => str_repeat('a', 5000)]]]])->assertSessionHasNoErrors();
    expect(mb_strlen(NotaSinteticaDelRendiconto::contenuto($s['e'])['parti']['altre']['testo']))->toBe(5000);
});

it('nel fascicolo la nota è la prima parte dopo il frontespizio, con la sua pagina; senza nota il frontespizio lo dice ai condòmini', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è');
    }
    $s = nssScenario($this);
    cdsSenzaStudio();
    // Senza nota: la parte non ha una riga nell'indice, e il frontespizio dice che il fascicolo non la contiene.
    $vuoto = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['nota', 'riepilogo']);
    expect(array_column($vuoto['indice'], 'parte'))->toBe(['riepilogo'])
        ->and(preg_replace('/\s+/u', ' ', nssTesto($vuoto['pdf'])))->toContain(NotaSinteticaDelRendiconto::FRASE_SENZA_NOTA);
    // Con la nota, scelta in qualunque ordine: è la prima parte, a pagina 2; il frontespizio non dice che manca.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(nssNota($s)), $this->user);
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['riepilogo', 'nota', 'situazione']);
    nssImmagini($f['pdf'], 'fascicolo-con-la-nota');
    $frontespizio = preg_replace('/\s+/u', ' ', explode("\f", nssTesto($f['pdf']))[0]);
    expect(array_column($f['indice'], 'parte'))->toBe(['nota', 'riepilogo', 'situazione'])
        ->and($f['indice'][0]['pagina'])->toBe(2)
        ->and($f['indice'][0]['titolo'])->toBe('Nota sintetica esplicativa')
        ->and($frontespizio)->toContain('Nota sintetica esplicativa')->not->toContain(NotaSinteticaDelRendiconto::FRASE_SENZA_NOTA)
        ->and(preg_replace('/\s+/u', ' ', explode("\f", nssTesto($f['pdf']))[1]))->toContain('1. I criteri del rendiconto')
        // La nota scelta senza la sua parte nel fascicolo: il frontespizio lo dice anche così.
        ->and(preg_replace('/\s+/u', ' ', nssTesto(app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['riepilogo'])['pdf'])))->toContain(NotaSinteticaDelRendiconto::FRASE_SENZA_NOTA);
    // La nota tiene il controllo da leggere spiegato: la frase dei controlli del frontespizio rimanda alla nota per quello.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(nssNota($s, ['spiegazioni' => ['R8' => 'I vincoli dei fondi si leggono a oggi.']])), $this->user);
    expect(preg_replace('/\s+/u', ' ', explode("\f", nssTesto(app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['nota'])['pdf']))[0]))
        ->toContain('(la spiegazione è nella nota sintetica)');
});

it('la stima del tetto del fascicolo conta la nota con il suo costo fisso', function () {
    $s = nssScenario($this);
    // La nota non scritta non costa niente: nel fascicolo non c'è.
    expect(app(FascicoloDelRendiconto::class)->righeDelleParti(['nota'], $s['e'], [], false))->toBe([]);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['parti' => ['altre' => ['testo' => 'Una riga.']]]), $this->user);
    $righe = app(FascicoloDelRendiconto::class)->righeDelleParti(['nota'], $s['e'], [], false);
    expect($righe)->toBe(['nota' => 1, 'nota_crediti' => 0])
        ->and(FascicoloDelRendiconto::stima($righe))->toBe(['secondi' => FascicoloDelRendiconto::SECONDI_BASE + FascicoloDelRendiconto::NOTA_SECONDI, 'mb' => FascicoloDelRendiconto::NOTA_MB])
        // Con le rate non pagate dei condòmini, la nota rifà la lettura dei crediti per anzianità: 1.000 righe, 4,6 s in più.
        ->and(FascicoloDelRendiconto::stima(['nota' => 1, 'nota_crediti' => 1000])['secondi'])->toEqualWithDelta(FascicoloDelRendiconto::SECONDI_BASE + FascicoloDelRendiconto::NOTA_SECONDI + 4.6, 0.0001);
    // Con un fatto del conguaglio tenuto e la parte del conguaglio non scelta, la stima conta anche la sua lettura.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['G7' => ['stato' => 'tenuto']]]), $this->user);
    expect(array_keys(app(FascicoloDelRendiconto::class)->righeDelleParti(['nota'], $s['e'], [], false)))->toBe(['nota', 'nota_crediti', 'conguaglio_lettura']);
});

it('il frontespizio di un fascicolo senza la nota, nei tre casi della carta: la frase c\'è, e il frontespizio resta in una pagina con la firma', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è');
    }
    $s = nssScenario($this);
    $carta();
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], []);
    nssImmagini($f['pdf'], 'fascicolo-senza-nota-'.preg_replace('/\W+/', '-', $this->dataName()));
    $pagine = explode("\f", nssTesto($f['pdf']));
    $primo = preg_replace('/\s+/u', ' ', $pagine[0]);
    expect($primo)->toContain(NotaSinteticaDelRendiconto::FRASE_SENZA_NOTA)->toContain('L\'amministratore')
        // Il frontespizio in una pagina, la firma compresa: il riepilogo, la prima parte, comincia a pagina 2.
        ->and($f['indice'][0])->toMatchArray(['parte' => 'riepilogo', 'pagina' => 2]);
})->with('carte della nota');

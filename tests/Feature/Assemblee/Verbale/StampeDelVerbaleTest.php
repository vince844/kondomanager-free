<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\CartaStampa;
use App\Services\Assemblee\Verbale\TestoDelVerbaleInPdf;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\GruppoCarta;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';
require_once __DIR__ . '/../../Pdf/Support/CartaDelloStudio.php';

/**
 * Le quattro stampe del verbale, sul modello comune delle stampe del condominio (la vista estende `pdf.base`, il servizio passa il gruppo «Rendiconto e
 * assemblea», nessuna testata scritta a mano), nei tre casi della carta: senza lo studio, con lo studio e il logo, con la carta intestata.
 *
 * - **Il verbale**: la bozza da firmare e la pagina per il libro di carta (decisione 595 (2)), con il testo dalla struttura dell'editor, l'elenco dei presenti
 *   e dei voti in calce, le firme del presidente e del segretario, la versione e l'impronta su ogni pagina.
 * - **Il registro dei verbali** e quello di nomina e revoca, con la colonna «Nel libro di carta», e la pagina dell'annotazione di una seduta deserta.
 * - **La lettera della trasmissione** per persona, seguita dalla copia sottoscritta.
 * - **La prova della trasmissione**, per destinatario.
 *
 * Il testo del verbale entra nel PDF da un solo componente, {@see TestoDelVerbaleInPdf}, che scappa ogni testo: è l'unica uscita non scappata delle viste.
 * Le immagini delle pagine, per guardarle: `VERBALE_IMMAGINI=/cartella` (servono `pdftoppm`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il testo di tutte le pagine, letto dai flussi di mPDF. */
function svdTesto(string $pdf): string
{
    $testo = [];
    for ($i = 1; $i <= cdsNumeroPagine($pdf); $i++) {
        $testo[] = cdsTesto($pdf, $i);
    }

    return implode(' ', $testo);
}

/** Le immagini delle pagine a 80 dpi, nella cartella di `VERBALE_IMMAGINI`, per guardarle. */
function svdImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('VERBALE_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 80 -png ' . escapeshellarg("{$cartella}/{$nome}.pdf") . ' ' . escapeshellarg("{$cartella}/{$nome}"));
}

/** Una carta intestata in immagine A4 per tutti i gruppi: la fascia in alto, i margini della carta. */
function svdCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 58, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-del-verbale.png', (string) ob_get_clean());
    $carta = CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-del-verbale.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

dataset('carte del verbale', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => svdCartaIntestata(),
]);

/**
 * La testata: con lo studio il nome dello studio, senza il condominio con l'indirizzo; con la carta intestata l'immagine della carta (solo sul foglio
 * verticale: il foglio orizzontale, come la prova della convocazione, ha la testata comune).
 */
function svdTestata(string $pdf, string $caso, bool $orizzontale = false): void
{
    $testo = svdTesto($pdf);
    if (str_contains($caso, 'con lo studio')) {
        expect($testo)->toContain('Studio Bianchi Amministrazioni')->and(cdsHaImmagine($pdf, 1))->toBeTrue();
    } else {
        expect($testo)->not->toContain('Studio Bianchi Amministrazioni')->toContain('Via Roma 12');
    }
    if (str_contains($caso, 'carta intestata') && ! $orizzontale) {
        expect(cdsHaImmagine($pdf, 1))->toBeTrue();
    }
}

/** Il verbale generato, con la discussione del punto 4 scritta nel verbale intero. */
function svdGenerato($t): array
{
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($t);
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a);
    $parte = \App\Models\Assemblee\ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->firstOrFail();
    $documento = (array) $parte->documento;
    $documento['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Il presidente ricorda che '],
        ['type' => 'text', 'text' => 'i preventivi restano agli atti', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' & <non> si votano oggi.']]];
    VerbaleDiViaRoma12::manda($t, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', $documento, '2026-12-15 20:59', (int) $parte->revisione)])
        ->assertOk()->assertJsonPath('risposte.0.esito', 'applicato');

    return [$s, $a->refresh(), $verbale->refresh(), $seduta, $v];
}

/** Il verbale generato e registrato come n. 42 (il libro di carta al n. 41). */
function svdRegistrato($t, ?string $copia = null): array
{
    [$s, $a, $verbale, $seduta, $v] = svdGenerato($t);
    VerbaleDiViaRoma12::registra($t, $s, $verbale, $copia === null ? [] : ['copia' => VerbaleDiViaRoma12::copia($copia)])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');

    return [$s, $a->refresh(), $verbale->refresh(), $seduta, $v];
}

it('le quattro viste estendono pdf.base, non scrivono una testata a mano, e l\'unica uscita non scappata è il testo del verbale', function () {
    $nonScappate = [];
    foreach (['assemblea_verbale', 'assemblea_registro_verbali', 'assemblea_trasmissione', 'assemblea_prova_trasmissione'] as $vista) {
        $sorgente = (string) file_get_contents(resource_path("views/pdf/gestionale/{$vista}.blade.php"));
        $senzaIlCommento = trim((string) preg_replace('/\{\{--.*?--\}\}/s', '', $sorgente));
        expect($senzaIlCommento)->toStartWith("@extends('pdf.base')")
            ->and($sorgente)->not->toContain('htmlpageheader')->not->toContain('intestazione_propria')->not->toContain('sethtmlpageheader');
        preg_match_all('/\{!!(.*?)!!\}/s', $senzaIlCommento, $m);
        foreach ($m[1] as $uscita) {
            $nonScappate[] = $vista . ': ' . trim($uscita);
        }
    }
    $parziale = (string) file_get_contents(resource_path('views/pdf/gestionale/_assemblea_presenze_voti_seduta.blade.php'));
    expect($parziale)->not->toContain('{!!')
        ->and($nonScappate)->toBe(['assemblea_verbale: \App\Services\Assemblee\Verbale\TestoDelVerbaleInPdf::html($documento)']);
});

it('il testo del verbale nel PDF: i nodi e i segni della barra, ogni testo scappato, i casi ostili del file in comune rifiutati o resi come testo', function () {
    $casi = json_decode((string) file_get_contents(base_path('tests/Fixtures/verbale/struttura.json')), true);
    foreach ($casi['validi'] as $caso) {
        $html = TestoDelVerbaleInPdf::html($caso['documento']);
        expect($html)->not->toContain('<script')->not->toContain('onclick')->not->toContain('href')->not->toContain('<img');
        foreach (preg_split('/\n/', (string) $caso['testo']) as $riga) {
            if (trim($riga) !== '') {
                expect(html_entity_decode(strip_tags(str_replace('<br />', "\n", $html)), ENT_QUOTES | ENT_HTML5))->toContain(trim($riga));
            }
        }
    }
    foreach ($casi['rifiutati'] as $caso) {
        expect(fn () => TestoDelVerbaleInPdf::html($caso['documento']))->toThrow(ValidationException::class);
    }
    $ostile = collect($casi['validi'])->firstWhere('nome', 'ostile: <script> nel testo resta testo');
    expect(TestoDelVerbaleInPdf::html($ostile['documento']))->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &lt;b&gt;non grassetto&lt;/b&gt;');

    $documento = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => '4. Sostituzione del portone']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Uno', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline']]],
            ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'due']]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'terzo']]]]]]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'voce']]]]]]],
        ['type' => 'datiDellaSala', 'attrs' => ['chiave' => 'voto:7', 'originale' => 'Favorevoli'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Favorevoli: 9']]]]],
    ]];
    $html = TestoDelVerbaleInPdf::html($documento);
    expect($html)->toContain('<h3')->toContain('4. Sostituzione del portone')->toContain('<b><i><u>Uno</u></i></b>')->toContain('<br />due')
        ->toContain('<ol start="3"')->toContain('<li')->toContain('terzo')->toContain('<ul')->toContain('voce')->toContain('Favorevoli: 9')
        ->not->toContain('voto:7')->not->toContain('datiDellaSala');
});

it('la bozza da firmare, nei tre casi della carta: il testo con i segni, l\'elenco in calce con le presenze e i voti, le firme, la versione e l\'impronta', function (\Closure $carta) {
    [$s, $a, $verbale, $seduta] = svdGenerato($this);
    $carta();
    $spia = cdsSpia();
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]), ['anche_con_le_cifre' => 1]);
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $pdf = (string) $r->getContent();
    svdImmagini($pdf, 'bozza-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = svdTesto($pdf);
    $stampa = collect($spia->stampe)->last();
    $verbale->refresh();
    $impronta = (string) $verbale->stampata['impronta_testo'];

    expect($stampa['vista'])->toBe('pdf.gestionale.assemblea_verbale')
        ->and($stampa['dati']['gruppo_carta'])->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($stampa['dati']['senza_firma'] ?? false)->toBeTrue()
        ->and($testo)->toContain('Bozza del verbale da firmare')->toContain('Assemblea ordinaria del 15/12/2026, seconda convocazione')
        ->toContain('Sono presenti, di persona o per delega, 11 condòmini su 26')
        ->toContain('i preventivi restano agli atti')->toContain('& <non> si votano oggi.')
        ->toContain('Elenco in calce: le presenze e i voti')->toContain('Presidente: Rossi Mario')
        ->toContain('Rossi Mario')->toContain('In sala')->toContain('18:45')->toContain('Bianchi Anna')->toContain('19:20')
        ->toContain('4. Sostituzione del portone')->toContain('Favorevoli')->toContain('Contrari')->toContain('Colombo Rita')->toContain('Senza voto')
        ->toContain('In conflitto d\'interessi: Condomino 30')->toContain('I preventivi sono arrivati il giorno prima dell\'assemblea.')
        ->toContain('Il presidente')->toContain('Il segretario')->toContain('L\'amministratore')
        ->toContain('Bozza da firmare · versione ' . $verbale->stampata['versione'] . ' · impronta ' . substr($impronta, 0, 8))
        ->toContain('Impronta del testo: ' . $impronta)
        ->not->toContain('Pagina del libro');
    // Il grassetto del testo resta grassetto: il pezzo è scritto con un carattere «Bold».
    expect(cdsTesto($pdf, 1) . svdTesto($pdf))->toContain('i preventivi restano agli atti');
    svdTestata($pdf, $this->dataName());
})->with('carte del verbale');

it('la pagina per il libro di carta, nei tre casi della carta: in testa il registro e il numero della pagina da scrivere, la firma a cavallo; l\'annullata dice perché', function (\Closure $carta) {
    [$s, $a, $verbale] = svdRegistrato($this);
    $carta();
    $spia = cdsSpia();
    $r = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.libro.pagina', [$s->c, $a, $verbale]))->assertOk();
    $pdf = (string) $r->getContent();
    svdImmagini($pdf, 'libro-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = svdTesto($pdf);

    expect(collect($spia->stampe)->last()['vista'])->toBe('pdf.gestionale.assemblea_verbale')
        ->and(collect($spia->stampe)->last()['dati']['gruppo_carta'])->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($testo)->toContain('Registro dei verbali delle assemblee')->toContain('Registro dei verbali di Via Roma 12')->toContain('Pagina del libro n.')
        ->toContain('Verbale n. 42 · assemblea ordinaria del 15/12/2026')->toContain('Sottoscritto il 15/12/2026')
        ->toContain('i preventivi restano agli atti')->toContain('Elenco in calce: le presenze e i voti')
        ->toContain('Spazio per la firma a cavallo fra questo foglio e la pagina del libro')
        ->not->toContain('Bozza del verbale da firmare');
    // Ogni pagina dice il numero del verbale e la pagina del libro: una pagina staccata si riconosce.
    for ($i = 1; $i <= cdsNumeroPagine($pdf); $i++) {
        expect(cdsTesto($pdf, $i))->toContain('Pagina del libro n.')->toContain('Verbale n. 42');
    }
    svdTestata($pdf, $this->dataName());

    if (str_contains($this->dataName(), 'senza studio')) {
        ConvocazioneDiViaRoma12::oggi('2026-12-19 10:00');
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $verbale]), ['motivo' => 'Scansione di un\'altra assemblea.'])
            ->assertSessionHasNoErrors();
        $annullato = svdTesto((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.libro.pagina', [$s->c, $a, $verbale]))->getContent());
        expect($annullato)->toContain('Verbale n. 42')->toContain('Registrazione annullata il 19/12/2026')->toContain('Scansione di un\'altra assemblea.');
    }
})->with('carte del verbale');

it('il registro dei verbali e quello di nomina e revoca, nei tre casi della carta: le righe per data, l\'annotazione, l\'annullata con il perché, il libro di carta', function (\Closure $carta) {
    [$s, $a, $verbale] = svdRegistrato($this);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Andrea Costa', 'dal' => '2025-04-10',
        'da_dove' => 'carta', 'estremi' => 'verbale n. 36 del 10/04/2025, nel libro di carta'])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.libro', [$s->c, $a, $verbale]), ['giorno' => '2026-12-16'])->assertSessionHasNoErrors();
    $carta();
    $spia = cdsSpia();
    $pdf = (string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.stampa', $s->c))->assertOk()->getContent();
    svdImmagini($pdf, 'registro-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = svdTesto($pdf);

    expect(collect($spia->stampe)->last()['vista'])->toBe('pdf.gestionale.assemblea_registro_verbali')
        ->and(collect($spia->stampe)->last()['dati']['gruppo_carta'])->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($testo)->toContain('Registro dei verbali di Via Roma 12')->toContain('continua il libro di carta: ultimo numero 41 del 22/10/2026')
        ->toContain('N.')->toContain('Data')->toContain('Assemblea')->toContain('Nel libro di carta')
        ->toContain('14/12/2026')->toContain('Ordinaria, prima convocazione')->toContain('Mancata costituzione')
        ->toContain('42')->toContain('15/12/2026')->toContain('Ordinaria, seconda convocazione')->toContain('1 dichiarazione')->toContain('Sottoscritto il 15/12/2026')
        ->toContain('Incollata nel libro il 16/12/2026')->toContain('Da incollare')
        ->toContain('Registro di nomina e revoca')->toContain('10/04/2025')->toContain('Nomina')->toContain('Andrea Costa')->toContain('verbale n. 36 del 10/04/2025')
        ->toContain('Impronta della stampa');
    svdTestata($pdf, $this->dataName());

    if (str_contains($this->dataName(), 'senza studio')) {
        ConvocazioneDiViaRoma12::oggi('2026-12-19 10:00');
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $verbale]), ['motivo' => 'Scansione di un\'altra assemblea.'])
            ->assertSessionHasNoErrors();
        $dopo = svdTesto((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.stampa', $s->c))->getContent());
        expect($dopo)->toContain('Annullato il 19/12/2026')->toContain('Scansione di un\'altra assemblea.');
    }
})->with('carte del verbale');

it('l\'annotazione della mancata costituzione ha la sua pagina per il libro: la frase, la prosecuzione, l\'amministratore e la firma a cavallo', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $prima = SalaDiViaRoma12::seduta($a, 'prima');
    cdsSenzaStudio();
    $spia = cdsSpia();
    $pdf = (string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.annotazione', [$s->c, $prima->id]))->assertOk()->getContent();
    svdImmagini($pdf, 'annotazione');
    $testo = svdTesto($pdf);

    expect(collect($spia->stampe)->last()['dati']['gruppo_carta'])->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and(cdsNumeroPagine($pdf))->toBe(1)
        ->and($testo)->toContain('Registro dei verbali delle assemblee')->toContain('Pagina del libro n.')->toContain('Annotazione · mancata costituzione')
        ->toContain('Il giorno 14 dicembre 2026, alle ore 08:00, in prima convocazione')->toContain('non si è costituita')
        ->toContain('L\'assemblea prosegue in seconda convocazione il 15 dicembre 2026 alle ore 18:30, come nell\'avviso.')
        ->toContain('L\'amministratore')->toContain('Spazio per la firma a cavallo')
        ->not->toContain('Nel libro di carta');
});

it('la lettera della trasmissione, nei tre casi della carta: il destinatario, l\'oggetto, i punti per cui è assente e i trenta giorni, una pagina sola', function (\Closure $carta) {
    [$s, $a, $verbale] = svdRegistrato($this);
    $carta();
    $spia = cdsSpia();
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]]);
    expect($r->status())->toBe(200);
    $pdf = (string) $r->getContent();
    svdImmagini($pdf, 'lettera-' . preg_replace('/\W+/', '-', $this->dataName()));
    $lettera = collect($spia->stampe)->firstWhere('vista', 'pdf.gestionale.assemblea_trasmissione');
    $testo = cdsTesto($pdf, 1);

    expect($lettera)->not->toBeNull()->and($lettera['dati']['gruppo_carta'])->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($lettera['pagine'])->toBe(1)
        ->and(cdsNumeroPagine($pdf))->toBe(2)
        ->and($testo)->toContain('Bianchi Anna')->toContain('Oggetto: trasmissione del verbale n. 42')->toContain('assemblea ordinaria del 15/12/2026')
        ->toContain('nella copia sottoscritta che segue')->toContain('Lo riceve come assente per i punti 1 e 2')->toContain('art. 1137 c. 2 c.c.')
        ->toContain('Raccomandata')->toContain('L\'amministratore')
        ->and(cdsTesto($pdf, 2))->toContain('Verbale firmato da presidente e segretario');
    svdTestata($pdf, $this->dataName());
})->with('carte del verbale');

it('la lettera della PEC dice che la copia sottoscritta è allegata al messaggio, non che segue la lettera', function () {
    [$s, $a, $verbale] = svdRegistrato($this);
    $spia = cdsSpia();
    app(\App\Services\Assemblee\Verbale\StampeDelVerbale::class)->lettera($verbale, ['nome' => 'Condomino 37', 'unita' => 'Int. 37', 'righe' => [], 'mezzo' => 'pec',
        'mezzo_parola' => 'PEC']);
    $corpo = (string) collect($spia->stampe)->firstWhere('vista', 'pdf.gestionale.assemblea_trasmissione')['dati']['corpo'];
    expect($corpo)->toEndWith('nella copia sottoscritta allegata a questo messaggio.')->not->toContain('che segue questa lettera');
});

it('la prova della trasmissione, nei tre casi della carta: per persona il perché, il mezzo, la spedizione, l\'esito, i trenta giorni; chi manca; l\'impronta', function (\Closure $carta) {
    [$s, $a, $verbale] = svdRegistrato($this);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]])->assertOk();
    $riga = \App\Models\Gestionale\RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $s->persone['bianchi']->id)->firstOrFail();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$riga->id], 'canale' => 'raccomandata',
        'data' => '2026-12-16'])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $riga]), ['esito' => 'consegnata', 'giorno' => '2026-12-18'])
        ->assertSessionHasNoErrors();
    $carta();
    $spia = cdsSpia();
    $pdf = (string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.prova', [$s->c, $a, $verbale]))->assertOk()->getContent();
    svdImmagini($pdf, 'prova-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = svdTesto($pdf);
    $stampa = collect($spia->stampe)->last();

    expect($stampa['vista'])->toBe('pdf.gestionale.assemblea_prova_trasmissione')
        ->and($stampa['dati']['gruppo_carta'])->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($stampa['opzioni']['orientation'] ?? null)->toBe('L')
        ->and($testo)->toContain('Prova della trasmissione del verbale n. 42')->toContain('Persona')->toContain('Perché lo riceve')->toContain('Mezzo')
        ->toContain('Spedizione')->toContain('Esito')->toContain('Può impugnare fino al')
        ->toContain('Bianchi Anna')->toContain('assente ai punti 1 e 2')->toContain('Raccomandata')->toContain('16/12/2026')->toContain('Consegnata il 18/12/2026')
        ->toContain('18/01/2027')
        ->toContain('Condomino 37')->toContain('Da spedire')
        ->toContain('Chi manca')->toContain('calcolati in modo prudente')->toContain('Impronta della stampa');
    svdTestata($pdf, $this->dataName(), true);
})->with('carte del verbale');

it('la catena: il verbale scritto e registrato → la stampa del verbale e la pagina del libro con lo stesso testo → la riga nella stampa del registro', function () {
    [$s, $a, $verbale] = svdGenerato($this);
    cdsSenzaStudio();
    $bozza = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]), ['anche_con_le_cifre' => 1])->assertOk();
    $impronta = (string) $verbale->refresh()->stampata['impronta_testo'];
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $verbale->refresh();

    $libro = svdTesto((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.libro.pagina', [$s->c, $a, $verbale]))->assertOk()->getContent());
    $registro = svdTesto((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.stampa', $s->c))->assertOk()->getContent());

    expect(svdTesto((string) $bozza->getContent()))->toContain('i preventivi restano agli atti')->toContain(substr($impronta, 0, 8))
        ->and($libro)->toContain('Verbale n. 42')->toContain('i preventivi restano agli atti')->toContain('Impronta del testo: ' . $impronta)
        ->and($registro)->toContain('Sottoscritto il 15/12/2026')->toContain('Ordinaria, seconda convocazione');
});

it('la catena: il .p7m caricato → il PDF tolto dalla busta e mostrato nel browser; senza la richiesta, la copia com\'è', function () {
    [$s, $a, $verbale] = svdRegistrato($this, 'verbale_firmato.pdf.p7m');
    $dentro = VerbaleDiViaRoma12::pdfVero('Verbale firmato da presidente e segretario');

    $mostrato = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.copia.show', [$s->c, $a, $verbale]) . '?come=pdf');
    $mostrato->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect((string) $mostrato->getContent())->toBe($dentro)
        ->and((string) $mostrato->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('verbale-42');

    $comE = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.copia.show', [$s->c, $a, $verbale]));
    $comE->assertOk();
    expect(substr((string) $comE->streamedContent(), 0, 1))->toBe("\x30");
});

it('il PDF si toglie anche dalla busta .p7m scritta in base64 e da quella firmata due volte; una busta senza un PDF dentro non dà niente', function () {
    $pdf = VerbaleDiViaRoma12::pdfVero('Verbale firmato');
    $busta = VerbaleDiViaRoma12::p7m($pdf);

    expect(\App\Services\Assemblee\Verbale\PdfDellaCopia::dalP7m(base64_encode($busta)))->toBe($pdf)
        ->and(\App\Services\Assemblee\Verbale\PdfDellaCopia::dalP7m(chunk_split(base64_encode($busta), 76, "\r\n")))->toBe($pdf)
        ->and(\App\Services\Assemblee\Verbale\PdfDellaCopia::pdfDalP7m(VerbaleDiViaRoma12::p7m($busta)))->toBe($pdf)
        ->and(\App\Services\Assemblee\Verbale\PdfDellaCopia::pdfDalP7m(VerbaleDiViaRoma12::p7m('un testo, non un PDF')))->toBeNull()
        ->and(\App\Services\Assemblee\Verbale\PdfDellaCopia::pdfDalP7m('non è una busta'))->toBeNull();
});

it('le stampe del verbale le legge chi vede le assemblee; condòmini e fornitori mai; il verbale di un\'altra assemblea è un 404', function () {
    [$s, $a, $verbale] = svdRegistrato($this);
    $rotte = ['libro' => route('admin.gestionale.assemblee.verbale.libro.pagina', [$s->c, $a, $verbale]),
        'mostra' => route('admin.gestionale.assemblee.verbale.copia.show', [$s->c, $a, $verbale]) . '?come=pdf'];
    $stati = [];
    foreach (['condòmino' => apUtente(\App\Enums\Role::UTENTE->value, [\App\Enums\Permission::VIEW_ASSEMBLEE]),
        'fornitore' => apUtente(\App\Enums\Role::FORNITORE->value, [\App\Enums\Permission::VIEW_ASSEMBLEE]),
        'vede' => apUtente(null, [\App\Enums\Permission::VIEW_ASSEMBLEE])] as $nome => $u) {
        foreach ($rotte as $quale => $indirizzo) {
            $stati["{$nome} {$quale}"] = $this->actingAs($u)->get($indirizzo)->status();
        }
    }
    expect($stati)->toBe(['condòmino libro' => 403, 'condòmino mostra' => 403, 'fornitore libro' => 403, 'fornitore mostra' => 403, 'vede libro' => 200, 'vede mostra' => 200])
        // La copia caricata come PDF si mostra com'è.
        ->and((string) $this->actingAs($s->admin)->get($rotte['mostra'])->getContent())->toBe(VerbaleDiViaRoma12::pdfVero('Verbale firmato da presidente e segretario'));

    $altra = SalaDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.copia.show', [$s->c, $altra, $verbale]) . '?come=pdf')->assertNotFound();
});

it('nella lettera della trasmissione il destinatario ha le righe allineate al margine destro, non in una colonna da metà pagina (decisione 260)', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questo computer.');
    }
    [$s, $a, $verbale] = svdRegistrato($this);
    $pdf = app(\App\Services\Assemblee\Verbale\StampeDelVerbale::class)->lettera($verbale, ['nome' => 'Bianchi Anna', 'unita' => 'Int. 3', 'righe' => [],
        'mezzo' => 'raccomandata', 'mezzo_parola' => 'Raccomandata', 'indirizzo' => 'Via Garibaldi 5, 20100 Milano']);
    $file = tempnam(sys_get_temp_dir(), 'svd') . '.pdf';
    file_put_contents($file, $pdf);
    $xml = (string) shell_exec('pdftotext -bbox -f 1 -l 1 ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);
    preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="[\d.]+">([^<]*)<\/word>/', $xml, $m, PREG_SET_ORDER);
    $destra = function (string $parola) use ($m) {
        foreach ($m as $w) {
            if (html_entity_decode($w[4]) === $parola) {
                return (float) $w[3];
            }
        }

        return null;
    };
    $bordi = [$destra('Raccomandata'), $destra('Anna'), $destra('Milano'), $destra('3')];
    expect(in_array(null, $bordi, true))->toBeFalse()->and(max($bordi) - min($bordi))->toBeLessThan(2.0)
        ->and(min($bordi))->toBeGreaterThan(450.0);
});

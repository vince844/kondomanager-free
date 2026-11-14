<?php

/**
 * La Coda 573: il frontespizio del fascicolo del rendiconto ha il piede delle parti (la data di emissione, la nota legale, «Frontespizio, pagina 1 di 1»), e ogni
 * pagina del fascicolo ha il suo numero nel fascicolo, «Pagina 7 di 13 del fascicolo», nella riga che il piede della parte lascia libera sotto il suo nome. Il
 * numero non tocca il piede della parte, né il resto della pagina, né la fascia stampata della carta intestata, con la nota legale corta, lunga (una riga sola
 * che va a capo da sé) e su tre righe; la riga della parte sta tutta su una riga. Se la carta intestata non si disegna in stampa e la stampa esce a campi, il
 * totale, l'indice e il posto del numero sono quelli del PDF uscito davvero. Le stampe delle parti da sole non cambiano.
 *
 * Il PDF si legge con pdftotext (`-bbox`): i riquadri delle parole in punti, dall'alto. Due righe di fila dello stesso piede hanno i riquadri che si toccano per
 * qualche centesimo di punto (le lettere del carattere vanno più su e più giù della riga): «toccare» qui vuol dire sovrapporsi per più di mezzo punto.
 */

use App\Models\CartaStampa;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\MpdfConCarta;
use App\Services\PDF\PdfService;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

beforeEach(function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questo computer.');
    }
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('local');
    Storage::fake('public');
});

/** Il margine basso della carta intestata delle prove, in mm: sotto, la carta ha la sua fascia stampata. */
const NDF_BASSO = 25;

/** La nota legale di sempre. */
const NDF_NOTA = 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4';

/**
 * La riga della parte nel piede, tutta su una riga: il nome intero (quelli delle parti del fascicolo) e «, pagina N di M». Sulla stessa riga può esserci la fine
 * della nota legale, a sinistra.
 */
const NDF_RIGA_DELLA_PARTE = '/(?:^|\s)(?:Frontespizio|Riepilogo finanziario|Situazione patrimoniale|Entrate e uscite per cassa|Controlli del rendiconto|Posizioni dei condòmini|Crediti per anzianità|Consuntivo per tabella|Conguaglio per persona|Registro di contabilità), pagina \d+ di \d+$/u';

/**
 * Una nota legale lunga, scritta su una riga sola come la scrive chi mette iscrizione e polizza: va a capo da sé, e la sua ultima riga arriva dove il numero del
 * fascicolo sarebbe finito se la colonna destra del piede fosse stretta («… sede in Roma via Po 12,» sotto «Pagina 6 di 11 del fascicolo»).
 */
const NDF_NOTA_LUNGA = 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4. Iscritto al registro degli amministratori di condominio n. 1234; polizza di responsabilità civile professionale n. 987654 presso Assicurazioni Esempio, massimale euro 1.000.000,00, sede in Roma via Po 12,';

/** Una nota legale su tre righe scritte a capo: la colonna di sinistra del piede è più alta della riga della parte e del numero messi insieme. */
const NDF_NOTA_TRE_RIGHE = "Professione esercitata ai sensi della legge 14 gennaio 2013, n.4\nIscritto al registro degli amministratori di condominio n. 1234\nPolizza RC professionale n. 987654 con Assicurazioni Esempio";

/**
 * Una carta intestata in PDF di due pagine, come la stampa una tipografia: in alto il nome della pagina, in basso una fascia azzurra alta quanto il margine basso,
 * con due scritte dentro (una a sinistra, una a destra, dove sta il numero di pagina).
 */
function ndfCartaPdf(): string
{
    $fascia = round(NDF_BASSO / 25.4 * 72, 2);
    $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $figli = [];
    foreach (['CARTA PRIMA PAGINA', 'CARTA PAGINE SEGUENTI'] as $i => $testo) {
        $n = 4 + 2 * $i;
        $figli[] = "{$n} 0 R";
        $o[$n] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R >> >> /Contents ".($n + 1).' 0 R >>';
        $c = "0.85 0.9 0.95 rg 0 0 595.28 {$fascia} re f 0 0 0 rg BT /F1 18 Tf 72 761.89 Td ({$testo}) Tj ET"
            .' BT /F1 9 Tf 72 30 Td (CARTA PIEDE DELLA TIPOGRAFIA) Tj ET BT /F1 9 Tf 420 52 Td (CARTA ANGOLO DESTRO) Tj ET';
        $o[$n + 1] = '<< /Length '.strlen($c).">>\nstream\n{$c}\nendstream";
    }
    $o[2] = '<< /Type /Pages /Kids ['.implode(' ', $figli).'] /Count '.count($figli).' >>';
    ksort($o);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $posti = [];
    foreach ($o as $n => $c) {
        $posti[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$c}\nendobj\n";
    }
    $dimensione = max(array_keys($o)) + 1;
    $x = strlen($pdf);
    $pdf .= "xref\n0 {$dimensione}\n0000000000 65535 f \n";
    for ($k = 1; $k < $dimensione; $k++) {
        $pdf .= sprintf("%010d 00000 n \n", $posti[$k]);
    }

    return $pdf."trailer\n<< /Size {$dimensione} /Root 1 0 R >>\nstartxref\n{$x}\n%%EOF\n";
}

/** La carta intestata delle prove, scelta per il rendiconto; `$alto`: il margine alto, in mm. */
function ndfCartaIntestata(int $alto = 45): void
{
    $percorso = 'settings/carte/carta-delle-prove.pdf';
    Storage::disk('public')->put($percorso, ndfCartaPdf());
    $carta = CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null, 'carta_path' => $percorso,
        'carta_tipo' => 'pdf', 'carta_pagine' => 2, 'predefinita' => false, 'margine_alto' => $alto, 'margine_basso' => NDF_BASSO, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = ['rendiconto_e_assemblea' => $carta->id];
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

/**
 * Via Roma al 15/01/2027 con la carta del caso: senza lo studio, con lo studio compilato, con la carta intestata. `$nota`: la nota legale delle stampe (quella di
 * sempre, se non si dice).
 */
function ndfScenario($test, string $carta, ?string $nota = null): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $carta === 'senza studio' ? cdsSenzaStudio() : cdsStudioPieno(true);
    if ($carta === 'carta intestata') {
        ndfCartaIntestata();
    }
    if ($nota !== null) {
        $impostazioni = app(PrintSettings::class);
        $impostazioni->nota_legale_stampe = $nota;
        $impostazioni->save();
    }

    return $s;
}

/**
 * Le pagine del PDF lette da pdftotext: per ognuna l'altezza in punti e le parole [testo, xMin, yMin, xMax, yMax], in punti dall'alto, nell'ordine di lettura.
 *
 * @return list<array{altezza: float, parole: list<array{0: string, 1: float, 2: float, 3: float, 4: float}>}>
 */
function ndfPagine(string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'ndf');
    file_put_contents($file, $pdf);
    $xml = (string) shell_exec('pdftotext -bbox '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);
    preg_match_all('/<page width="[\d.]+" height="([\d.]+)">(.*?)<\/page>/s', $xml, $pagine, PREG_SET_ORDER);

    return array_map(function (array $p) {
        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<\/word>/', $p[2], $m, PREG_SET_ORDER);

        return ['altezza' => (float) $p[1], 'parole' => array_map(fn ($w) => [html_entity_decode($w[5], ENT_QUOTES | ENT_XML1), (float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4]], $m)];
    }, $pagine);
}

/** Il testo di una pagina, le parole unite da uno spazio. */
function ndfTesto(array $pagina): string
{
    return implode(' ', array_column($pagina['parole'], 0));
}

/** Il riquadro di più parole: [xMin, yMin, xMax, yMax]. */
function ndfRiquadro(array $parole): array
{
    return [min(array_column($parole, 1)), min(array_column($parole, 2)), max(array_column($parole, 3)), max(array_column($parole, 4))];
}

/** Due riquadri si toccano: si sovrappongono in orizzontale, e in verticale per più di mezzo punto. */
function ndfSiToccano(array $a, array $b): bool
{
    return $a[0] < $b[2] && $b[0] < $a[2] && min($a[3], $b[3]) - max($a[1], $b[1]) > 0.5;
}

/**
 * Le righe del piede di una pagina: il numero del fascicolo («Pagina N di T del fascicolo», le sei parole di fila sulla stessa riga) e la riga della parte (le
 * parole sulla riga di «…, pagina N di M», nella metà destra), ognuna con il suo riquadro e i suoi numeri; null se la riga non c'è.
 */
function ndfPiede(array $pagina): array
{
    $parole = $pagina['parole'];
    $numero = null;
    $parte = null;
    foreach ($parole as $i => $w) {
        $riga = array_slice($parole, $i, 6);
        if (count($riga) === 6 && $w[0] === 'Pagina' && $riga[2][0] === 'di' && $riga[4][0] === 'del' && $riga[5][0] === 'fascicolo'
            && ctype_digit($riga[1][0]) && ctype_digit($riga[3][0]) && max(array_column($riga, 2)) - min(array_column($riga, 2)) < 0.5) {
            $numero = ['n' => (int) $riga[1][0], 'di' => (int) $riga[3][0], 'parole' => $riga, 'riquadro' => ndfRiquadro($riga)];
        }
        if ($w[0] === 'pagina' && $i > 0 && str_ends_with($parole[$i - 1][0], ',') && ($parole[$i + 2][0] ?? '') === 'di') {
            $stessaRiga = array_values(array_filter($parole, fn ($x) => abs($x[2] - $w[2]) < 0.5 && $x[1] > 200));
            $parte = ['testo' => implode(' ', array_column($stessaRiga, 0)), 'parole' => $stessaRiga, 'riquadro' => ndfRiquadro($stessaRiga)];
        }
    }

    return ['numero' => $numero, 'parte' => $parte];
}

/**
 * Quello che non va nel piede di ogni pagina del fascicolo, una riga per difetto (vuoto se va tutto bene): il numero «Pagina N di T del fascicolo» c'è, N è
 * la pagina e T il totale delle pagine del PDF; la riga della parte sta tutta su una riga («<parte>, pagina N di M»); il numero sta nella riga sotto la riga
 * della parte, non tocca nessun'altra parola della pagina e non entra nella fascia stampata della carta intestata (se la pagina ce l'ha).
 *
 * @return list<string>
 */
function ndfMale(array $pagine): array
{
    $totale = count($pagine);
    $basso = NDF_BASSO / 25.4 * 72;
    $male = [];
    foreach ($pagine as $i => $pagina) {
        $p = $i + 1;
        ['numero' => $numero, 'parte' => $parte] = ndfPiede($pagina);
        if ($numero === null || $parte === null) {
            $male[] = "pagina {$p}: ".($numero === null ? 'il numero del fascicolo non c\'è' : 'la riga della parte non c\'è');

            continue;
        }
        if ($numero['n'] !== $p || $numero['di'] !== $totale) {
            $male[] = "pagina {$p}: «Pagina {$numero['n']} di {$numero['di']} del fascicolo», non «Pagina {$p} di {$totale}»";
        }
        // Il nome della parte e «, pagina N di M» stanno sulla stessa riga: la colonna destra del piede non è più stretta del suo testo.
        if (! preg_match(NDF_RIGA_DELLA_PARTE, $parte['testo'])) {
            $male[] = "pagina {$p}: la riga della parte va a capo («{$parte['testo']}»)";
        }
        // Sotto la riga della parte, la riga subito dopo: lo stesso bordo destro, lo stesso carattere (la stessa altezza), lo spazio di una riga del piede.
        [$nx0, $ny0, $nx1, $ny1] = $numero['riquadro'];
        [, $py0, $px1, $py1] = $parte['riquadro'];
        if (abs($nx1 - $px1) > 1.0 || abs(($ny1 - $ny0) - ($py1 - $py0)) > 0.1 || abs($ny0 - $py1) > 0.5) {
            $male[] = "pagina {$p}: il numero non è nella riga sotto «{$parte['testo']}» (numero ".json_encode($numero['riquadro']).', parte '.json_encode($parte['riquadro']).')';
        }
        // Nessun'altra parola della pagina tocca il numero: né il piede della parte, né il corpo, né le scritte della carta intestata.
        foreach ($pagina['parole'] as $w) {
            if (! in_array($w, $numero['parole'], true) && ndfSiToccano($numero['riquadro'], [$w[1], $w[2], $w[3], $w[4]])) {
                $male[] = "pagina {$p}: il numero tocca «{$w[0]}»";
            }
        }
        // Sulle pagine con la carta intestata (le verticali: le orizzontali hanno la testata a campi) il numero sta sopra la fascia stampata della carta.
        if (str_contains(ndfTesto($pagina), 'CARTA PIEDE DELLA TIPOGRAFIA') && $ny1 > $pagina['altezza'] - $basso + 0.5) {
            $male[] = "pagina {$p}: il numero entra nella fascia della carta ({$ny1} oltre ".round($pagina['altezza'] - $basso, 2).')';
        }
    }

    return $male;
}

it('il fascicolo: il frontespizio ha il piede delle parti; ogni pagina ha «Pagina N di T del fascicolo», in sequenza, sotto la riga della parte, senza toccare nient\'altro', function (string $carta, string $nota) {
    $s = ndfScenario($this, $carta, $nota);
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], [], true);
    $pagine = ndfPagine($f['pdf']);
    $totale = count($pagine);

    // Il frontespizio: la data di emissione, la nota legale (tutte le sue parole: va a capo dove serve) e il nome della parte, come le altre parti.
    expect(ndfTesto($pagine[0]))->toContain('Documento emesso il 15/01/2027')
        ->and(ndfPiede($pagine[0])['parte']['testo'] ?? '')->toEndWith('Frontespizio, pagina 1 di 1')
        ->and(array_diff(preg_split('/\s+/', $nota), array_column($pagine[0]['parole'], 0)))->toBe([]);

    // Il frontespizio sta in una pagina, con la firma: le parti cominciano a pagina 2.
    expect(ndfTesto($pagine[0]))->toContain('L\'amministratore')
        ->and($f['indice'][0]['pagina'])->toBe(2)
        ->and($totale)->toBe(1 + array_sum(array_column($f['indice'], 'pagine')))
        ->and(ndfMale($pagine))->toBe([]);
    if ($carta === 'carta intestata') {
        $conLaCarta = array_filter($pagine, fn ($p) => str_contains(ndfTesto($p), 'CARTA PIEDE DELLA TIPOGRAFIA'));
        expect(count($conLaCarta))->toBeGreaterThan(2)->and(ndfTesto($pagine[0]))->toContain('CARTA ANGOLO DESTRO');
    }
})->with([
    'senza studio' => ['senza studio', NDF_NOTA],
    'con lo studio' => ['con lo studio', NDF_NOTA],
    'carta intestata' => ['carta intestata', NDF_NOTA],
    'nota lunga, senza studio' => ['senza studio', NDF_NOTA_LUNGA],
    'nota lunga, carta intestata' => ['carta intestata', NDF_NOTA_LUNGA],
    'nota su tre righe, con lo studio' => ['con lo studio', NDF_NOTA_TRE_RIGHE],
    'nota su tre righe, carta intestata' => ['carta intestata', NDF_NOTA_TRE_RIGHE],
]);

it('le stampe delle parti da sole non hanno il numero del fascicolo, e il loro piede è quello di sempre: «Pagina N di M» a metà fra le due righe di sinistra', function () {
    $s = ndfScenario($this, 'senza studio');
    $rotte = [
        'il riepilogo' => route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$s['c'], $s['e']]).'?dettaglio=1',
        'la situazione' => route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=situazione',
        'le posizioni' => route('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa', [$s['c'], $s['e']]),
        'il registro' => route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]),
    ];
    $male = [];
    foreach ($rotte as $stampa => $indirizzo) {
        $risposta = $this->actingAs($this->user)->get($indirizzo)->assertOk();
        $pdf = (string) ($risposta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $risposta->streamedContent() : $risposta->getContent());
        foreach (ndfPagine($pdf) as $i => $pagina) {
            $testo = ndfTesto($pagina);
            $parole = $pagina['parole'];
            $primo = fn (string $t) => collect($parole)->filter(fn ($w) => $w[0] === $t)->sortByDesc(fn ($w) => $w[2])->first();
            [$documento, $nota, $numero] = [$primo('Documento'), $primo('Professione'), $primo('Pagina')];
            if (str_contains($testo, 'fascicolo')) {
                $male[] = "{$stampa}, pagina ".($i + 1).': il numero del fascicolo';
            } elseif ($documento === null || $nota === null || $numero === null) {
                $male[] = "{$stampa}, pagina ".($i + 1).': il piede non ha la data, la nota legale e il numero di pagina';
            } elseif (! ($numero[2] > $documento[2] + 1.0 && $numero[2] < $nota[2] - 1.0)) {
                $male[] = "{$stampa}, pagina ".($i + 1).': «Pagina» non sta a metà fra le due righe di sinistra';
            }
        }
    }

    expect($male)->toBe([]);
});

it('la firma del frontespizio non resta mai da sola: con una carta che lascia poco spazio l\'indice e la firma passano insieme alla seconda pagina, e i numeri seguono', function () {
    $s = ndfScenario($this, 'con lo studio');
    // Una carta con il margine alto di 75 mm: il frontespizio non sta in una pagina nemmeno con le righe strette.
    ndfCartaIntestata(75);
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], [], true);
    $pagine = ndfPagine($f['pdf']);
    $totale = count($pagine);
    $conLaFirma = array_keys(array_filter($pagine, fn ($p) => str_contains(ndfTesto($p), 'L\'amministratore')));

    expect($conLaFirma)->toBe([1])
        ->and(ndfTesto($pagine[1]))->toContain('Indice')->toContain('Riepilogo finanziario, con il dettaglio per spesa')->toContain('Controlli del rendiconto')
        ->and(ndfTesto($pagine[0]))->not->toContain('Indice')
        ->and(ndfTesto($pagine[1]))->toContain('Frontespizio, pagina 2 di 2')
        ->and($f['indice'][0]['pagina'])->toBe(3)
        ->and(array_map(fn ($p) => ndfPiede($p)['numero']['n'] ?? null, $pagine))->toBe(range(1, $totale))
        ->and(array_unique(array_map(fn ($p) => ndfPiede($p)['numero']['di'] ?? null, $pagine)))->toBe([$totale]);
});

it('se la carta intestata non si disegna in stampa e le parti escono a campi, il totale, l\'indice e il posto del numero sono quelli del PDF uscito davvero', function () {
    $s = ndfScenario($this, 'carta intestata');
    // Il file della carta cambia sul disco dopo il controllo della stampa (una carta nuova caricata in un'altra scheda, il file vecchio cancellato): da qui in poi
    // la carta non si disegna più, e ogni parte verticale ripiega sulla stampa a campi, che ha altri margini e altre pagine.
    $spia = new class('settings/carte/carta-delle-prove.pdf') extends PdfService
    {
        public int $conLaCarta = 0;

        public function __construct(private string $percorso) {}

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            $mpdf = parent::generate($view, $data, $config);
            if ($mpdf instanceof MpdfConCarta) {
                $this->conLaCarta++;
                Storage::disk('public')->put($this->percorso, 'non è più un PDF');
            }

            return $mpdf;
        }
    };
    app()->instance(PdfService::class, $spia);

    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], [], true);
    $pagine = ndfPagine($f['pdf']);
    $totale = count($pagine);

    // La prova c'è davvero: le parti erano con la carta, e nel PDF la carta non c'è.
    expect($spia->conLaCarta)->toBeGreaterThan(2)
        ->and(implode(' ', array_map('ndfTesto', $pagine)))->not->toContain('CARTA PIEDE DELLA TIPOGRAFIA');

    // Il totale è le pagine del PDF, i numeri vanno da 1 al totale, ognuno nella riga sotto quella della parte (a 11 mm dal suo posto non ci sta).
    expect(ndfMale($pagine))->toBe([])
        ->and(array_map(fn ($p) => ndfPiede($p)['numero']['n'] ?? null, $pagine))->toBe(range(1, $totale))
        ->and(array_unique(array_map(fn ($p) => ndfPiede($p)['numero']['di'] ?? null, $pagine)))->toBe([$totale])
        ->and($totale)->toBe(1 + array_sum(array_column($f['indice'], 'pagine')));

    // L'indice: la prima pagina di ogni parte è quella che dice l'indice.
    $male = [];
    foreach ($f['indice'] as $voce) {
        if ($voce['pagina'] === null) {
            continue;
        }
        $testo = ndfPiede($pagine[$voce['pagina'] - 1])['parte']['testo'] ?? '';
        if (! preg_match('/, pagina 1 di \d+$/', $testo)) {
            $male[] = "«{$voce['titolo']}» è a pagina {$voce['pagina']} per l'indice, e quella pagina dice «{$testo}»";
        }
    }
    expect($male)->toBe([]);
});

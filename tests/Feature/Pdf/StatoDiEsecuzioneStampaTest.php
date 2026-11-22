<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La stampa «Lo stato di esecuzione delle delibere» (§8 del progetto della tappa 5), sul Condominio Via Roma 12: la straordinaria del 22/10/2026 (la
 * facciata: il piano ancora da creare) e l'ordinaria del 15/12/2026 (il riscaldamento, eseguita il 16/12/2026); a maggio 2027 l'ordinaria con il punto
 * «Stato di esecuzione delle delibere precedenti», preparato il 28/04/2027.
 *
 * - Nei tre casi della carta (senza studio, con lo studio e il logo, con la carta intestata): il titolo, l'assemblea e il punto, le aperte prima e le
 *   eseguite dopo, le cose da fare con il loro stato; mai un nome né un testo scritto a mano.
 * - **L'elenco è quello fermato nel punto**: una cosa chiusa dopo il 28/04 non cambia la stampa (l'avviso e il verbale portano le stesse righe).
 * - La regola delle stampe: la vista estende `pdf.base`, il servizio passa `GruppoCarta::RENDICONTO_E_ASSEMBLEA`; nessuna intestazione scritta a mano.
 * - **Nel fascicolo**: il punto senza un documento aggiunto porta la stampa, e il fascicolo composto la contiene.
 * - «Vede le assemblee» la legge; un'assemblea di un altro condominio è un 404.
 *
 * Le immagini delle pagine vanno nella cartella dell'ambiente `STATO_IMMAGINI`, se c'è.
 */

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\CartaStampa;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\PDF\CartaDiStampa;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../Assemblee/Seguito/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/Support/CartaDelloStudio.php';
require_once __DIR__ . '/../Assemblee/Pagine/RotteDelleAssemblee.php';

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Permission::findOrCreate(\App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'web');
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Una riunione su carta il giorno dato, con gli esiti approvati. */
function sesSuCarta(Beta51Scenario $s, array $punti, string $giorno, array $piu = []): \App\Models\Assemblee\Assemblea
{
    ConvocazioneDiViaRoma12::oggi(\Illuminate\Support\Carbon::parse($giorno)->addDay()->format('Y-m-d') . ' 10:00');
    $a = ViaRoma12::ordinaria($s, $punti, $piu);
    $e = [];
    foreach ($a->punti()->get() as $p) {
        $e[(int) $p->id] = ['esito' => 'approvata'];
    }

    return StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, $e, $giorno);
}

/** Via Roma 12 con la facciata aperta, il riscaldamento eseguito, e l'ordinaria di maggio 2027 con il punto preparato il 28/04/2027. @return array<string, mixed> */
function sesMaggio($t): array
{
    $s = ViaRoma12::nuovo($t);
    $facciata = sesSuCarta($s, [['testo' => 'Rifacimento della facciata con Edil Verdi di Rossi Mario', 'materia' => 'lavori', 'importo' => 9000000]],
        '2026-10-22', ['tipo' => 'straordinaria']);
    SeguitoDiViaRoma12::conferma($t, $s, $facciata, [SeguitoDiViaRoma12::delibera($facciata, 1)->id . ':cosa:crea_piano_straordinario'])->assertOk();
    $ordinaria = sesSuCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']], '2026-12-15');
    SeguitoDiViaRoma12::conferma($t, $s, $ordinaria, [])->assertOk();

    ConvocazioneDiViaRoma12::oggi('2027-04-28 10:00');
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'di_serie')['punti'];
    $punto = collect($proposte)->firstWhere('chiave_fonte', StatoDiEsecuzione::CHIAVE);
    $maggio = ViaRoma12::ordinaria($s, [$punto], ['sedute' => [['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2027-05-19 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2027-05-20 18:30']]]);

    return ['s' => $s, 'facciata' => $facciata, 'maggio' => $maggio];
}

/** Il testo di un PDF con pdftotext, con gli spazi normalizzati. */
function sesTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'ses') . '.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/\s+/u', ' ', $testo);
}

/** Le immagini delle pagine, a 70 dpi, nella cartella data dall'ambiente (`STATO_IMMAGINI`). */
function sesImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('STATO_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png ' . escapeshellarg("{$cartella}/{$nome}.pdf") . ' ' . escapeshellarg("{$cartella}/{$nome}"));
}

/** La carta intestata in immagine per il gruppo del rendiconto e dell'assemblea, come le prove della nota sintetica. */
function sesCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-dello-stato.png', (string) ob_get_clean());
    $carta = CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-dello-stato.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

dataset('carte dello stato', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => sesCartaIntestata(),
]);

it('nei tre casi della carta: il titolo, l\'assemblea e il punto, le aperte prima e le eseguite dopo, le cose con il loro stato; nessun nome', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è');
    }
    $x = sesMaggio($this);
    $carta();
    $r = $this->actingAs($x['s']->admin)->get(route('admin.gestionale.assemblee.seguito.stampa', [$x['s']->c, $x['maggio']]))->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf');
    $pdf = (string) $r->getContent();
    sesImmagini($pdf, 'stato-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = sesTesto($pdf);

    expect($testo)->toContain('Lo stato di esecuzione delle delibere')
        ->toContain('Ordinaria del 20/05/2027 · punto 1 · elenco al 28/04/2027')
        ->toContain('Ancora da eseguire · 1')
        ->toContain('Lavori nelle parti comuni')
        ->toContain('Crea il piano straordinario: da fare')
        ->toContain('Eseguite dall\'ultima ordinaria, il 15/12/2026 · 1')
        ->toContain('Straordinaria del 22/10/2026, punto 1 · Lavori nelle parti comuni')
        ->toContain('Ordinaria del 15/12/2026, punto 1 · Riscaldamento e condizionamento · eseguita il 16/12/2026')
        ->and(strpos($testo, 'Ancora da eseguire'))->toBeLessThan(strpos($testo, 'Eseguite dall\'ultima ordinaria'))
        // Mai il testo scritto nel punto, che può portare un nome (Cass. 29323/2022).
        ->and($testo)->not->toContain('Rossi Mario');
})->with('carte dello stato');

it('l\'elenco è quello fermato nel punto: una cosa chiusa dopo il 28/04 non cambia la stampa; la regola delle stampe; «Vede» la legge, un altro condominio è un 404', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è');
    }
    $x = sesMaggio($this);
    $s = $x['s'];
    ConvocazioneDiViaRoma12::oggi('2027-05-02 10:00');
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', SeguitoDiViaRoma12::delibera($x['facciata'], 1)->id)
        ->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->sole();
    CoseDaFare::nonServe($cosa, 'Il condominio ha deciso di aspettare.', $s->admin);
    $testo = sesTesto((string) $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.seguito.stampa', [$s->c, $x['maggio']]))->assertOk()->getContent());
    expect($testo)->toContain('Ancora da eseguire · 1')->toContain('elenco al 28/04/2027');

    // La regola delle stampe: la vista estende la base comune, il servizio dice il gruppo della carta; nessuna intestazione scritta a mano.
    $vista = (string) file_get_contents(resource_path('views/pdf/gestionale/assemblea_stato_esecuzione.blade.php'));
    $servizio = (string) file_get_contents(app_path('Services/Assemblee/Seguito/StampaDelloStatoDiEsecuzione.php'));
    expect($vista)->toContain('@extends(\'pdf.base\')')->not->toContain('<html')->not->toContain('nome_studio')
        ->and($servizio)->toContain('GruppoCarta::RENDICONTO_E_ASSEMBLEA');

    $vede = apUtente(null, [\App\Enums\Permission::VIEW_ASSEMBLEE]);
    $this->actingAs($vede)->get(route('admin.gestionale.assemblee.seguito.stampa', [$s->c, $x['maggio']]))->assertOk();
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.seguito.stampa', [$altro->c, $x['maggio']]))->assertNotFound();
});

it('catena · l\'assemblea di maggio → il punto dello stato di esecuzione → la stampa nel fascicolo', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è');
    }
    $x = sesMaggio($this);
    $s = $x['s'];
    $punto = $x['maggio']->punti()->where('chiave_fonte', StatoDiEsecuzione::CHIAVE)->sole();
    expect($punto->dati[StatoDiEsecuzione::CHIAVE]['al'])->toBe('2027-04-28');

    $fascicolo = app(FascicoloDellAssemblea::class);
    $riga = collect($fascicolo->perLaPagina($x['maggio']->fresh())['righe'])->firstWhere('punto_id', $punto->id);
    expect($riga['documento'])->toBe('Lo stato di esecuzione delle delibere, stampato dal programma con l\'elenco del punto')
        ->and($fascicolo->riepilogo($x['maggio']->fresh())['documenti'])->toBe(1);

    $documenti = $fascicolo->documenti($x['maggio']->fresh());
    expect($documenti)->toHaveCount(1)
        ->and($documenti[0]['nome'])->toBe('Lo stato di esecuzione delle delibere')
        ->and($documenti[0]['numero'])->toBe((int) $punto->numero)
        ->and($documenti[0]['pagine'])->toBeGreaterThanOrEqual(1)
        ->and(sesTesto($documenti[0]['contenuto']))->toContain('Ancora da eseguire · 1');

    $composto = $fascicolo->componi($x['maggio']->fresh(), $s->admin);
    expect($composto['documenti'])->toBe(3)
        ->and(sesTesto($composto['pdf']))->toContain('Lo stato di esecuzione delle delibere');
});

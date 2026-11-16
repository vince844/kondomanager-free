<?php

/**
 * Il riferimento del tasso scritto nelle stampe, per intero, nei tre casi della carta (senza studio, con lo studio e il logo, con la carta intestata): la
 * ricevuta del versamento degli interessi dice la loro causale con il tasso e il riferimento; la lettera li dice nella riga dell'addebito e nel segnaposto
 * `[tasso]`; l'estratto conto nella riga dell'emissione. Le immagini a 70 dpi nella cartella data dall'ambiente (`RIFERIMENTO_IMMAGINI`), per guardarle.
 *
 * Via Verdi (`AccessoriScenario.php`): una regola dal 01/01/2026 con il 5,00 % dal regolamento contrattuale; il 29/06 a Neri interessi di mora per € 3,70
 * (300,00 × 5,00 % × 90/365 = 3,698630), versati il 10/07.
 */

use App\Models\Gestionale\RataQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Gestionale/AccessoriScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

const RDTS_RIFERIMENTO = 'art. 12 del regolamento contrattuale, allegato al primo atto di vendita del 12/05/1987, trascritto alla Conservatoria di Milano';

/** Via Verdi con la regola scritta e gli interessi di Neri registrati il 29/06 e versati il 10/07: lo scenario e l'incasso. */
function rdtsViaVerdi($test): array
{
    $s = accViaVerdi($test);
    $test->actingAs($test->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '5',
        'fonte' => 'regolamento', 'riferimento' => RDTS_RIFERIMENTO])->assertSessionHasNoErrors();
    $test->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($test, $s, accProposta($test, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    $test->travelTo('2026-07-10 10:00:00');
    $s['incasso'] = lcdIncassa($test, $s, 'Neri', [(int) RataQuote::where('rata_id', $rataId)->value('id') => 370], '2026-07-10');

    return $s;
}

function rdtsCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-del-riferimento.png', (string) ob_get_clean());
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-del-riferimento.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(\App\Settings\PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

/** Il testo del PDF, con gli spazi e gli a capo uniti in uno spazio solo. */
function rdtsTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'rdts').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -raw '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/\s+/u', ' ', $testo);
}

function rdtsImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('RIFERIMENTO_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png '.escapeshellarg("{$cartella}/{$nome}.pdf").' '.escapeshellarg("{$cartella}/{$nome}"));
}

dataset('carte del riferimento', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => rdtsCartaIntestata(),
]);

it('la ricevuta, la lettera e l\'estratto conto dicono il tasso scritto con il riferimento intero, nei tre casi della carta', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = rdtsViaVerdi($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $causale = 'Interessi di mora al 29/06/2026 (5,00 % annuo, '.RDTS_RIFERIMENTO.')';

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s['c']->id]), ['incassi' => [$s['incasso']], 'bollo' => 'assolta']);
    expect($r->status())->toBe(200, (string) $r->getContent());
    rdtsImmagini((string) $r->getContent(), 'ricevuta-'.$nome);
    expect(rdtsTesto((string) $r->getContent()))->toContain($causale);

    // La lettera: il segnaposto [tasso] dice il tasso di oggi con il riferimento intero.
    $l =$this->actingAs($this->user)->post(route('admin.gestionale.lettere.pdf-anteprima', ['condominio' => $s['c']->id]), ['tipo' => 'sollecito',
        'persone' => [$s['persone']['Neri']->id], 'testo' => "Gentile [nome],\nrisultano da versare [totale], al tasso [tasso].", 'termine' => 15, 'indirizzi' => ['email']]);
    expect($l->status())->toBe(200, (string) $l->getContent());
    rdtsImmagini((string) $l->getContent(), 'lettera-'.$nome);
    expect(rdtsTesto((string) $l->getContent()))->toContain('al tasso 5,00 % annuo, '.RDTS_RIFERIMENTO.'.');

    $e = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['Neri']]));
    expect($e->status())->toBe(200);
    rdtsImmagini((string) $e->getContent(), 'estratto-conto-'.$nome);
    expect(rdtsTesto((string) $e->getContent()))->toContain('Addebito: '.$causale);
})->with('carte del riferimento');

it('la riga degli interessi nella lettera, quando sono ancora da versare: la causale con il tasso e il riferimento, nei tre casi della carta', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = accViaVerdi($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), ['dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '5',
        'fonte' => 'regolamento', 'riferimento' => RDTS_RIFERIMENTO])->assertSessionHasNoErrors();
    $this->travelTo('2026-06-29 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'])->json())->assertCreated();
    $this->travelTo('2026-07-10 10:00:00');
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());

    $l = $this->actingAs($this->user)->post(route('admin.gestionale.lettere.pdf-anteprima', ['condominio' => $s['c']->id]), ['tipo' => 'sollecito',
        'persone' => [$s['persone']['Neri']->id], 'testo' => "Gentile [nome],\nrisultano da versare [totale], di cui interessi di mora [interessi].", 'termine' => 15,
        'indirizzi' => ['email']]);
    expect($l->status())->toBe(200, (string) $l->getContent());
    rdtsImmagini((string) $l->getContent(), 'lettera-con-la-riga-'.$nome);
    expect(rdtsTesto((string) $l->getContent()))->toContain('Interessi di mora al 29/06/2026 (5,00 % annuo, '.RDTS_RIFERIMENTO.') — addebito')
        ->toContain('di cui interessi di mora € 3,70.');
})->with('carte del riferimento');

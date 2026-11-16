<?php

/**
 * Le stampe con un interesse di mora addebitato e versato, nei tre casi della carta (senza studio, con lo studio e il logo, con la carta intestata): il
 * frontespizio dice le spese meno le entrate da terzi meno gli interessi e le spese versati dai condòmini; il riepilogo ha la riga dei versati sotto le
 * entrate e la nomina nella riga dei costi; la ricevuta del versamento dice che cos'è, «Interessi di mora al 20/12/2026», e la lettera riempie
 * `[interessi]` e `[tasso]` con ciò che è registrato. Le immagini a 70 dpi nella cartella data dall'ambiente (`ACCESSORI_IMMAGINI`), per guardarle.
 *
 * Via Roma (`RendicontoViaRomaScenario.php`): a Bice € 0,92 di interessi addebitati il 20/12/2026 sulla voce «Interessi di mora», versati il 28/12; a
 * Ugo € 1,18 addebitati e non versati.
 */

use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

/** Via Roma con gli interessi di Bice versati e quelli di Ugo no; restituisce lo scenario e l'incasso di Bice. */
function acstViaRoma($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2026-12-20');
    $s['voce_interessi'] = be52Voce($test, $s, $s['pc'], 'Interessi di mora', 'entrata', '0,00');
    $registra = fn (string $chi, string $u, int $importo) => app(AddebitiIndividuali::class)->registra($s['c'], $s['g'], $s['e'], [[
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$u]->id, 'importo' => $importo, 'causale' => 'Interessi di mora al 20/12/2026',
        'scadenza' => '2026-12-20', 'natura' => 'accessorio', 'tipo_accessorio' => 'interessi', 'base' => 'legge', 'riferimento' => 'artt. 1224 e 1284 c.c.',
        'conto_id' => $s['voce_interessi']->id,
    ]], $test->user->id, '2026-12-20')[0];
    $bice = $registra('bice', 'C', 92);
    $registra('ugo', 'A', 118);
    vrmGiorno($test, '2026-12-28');
    $s['incasso_interessi'] = vrmIncassa($test, $s, 'bice', [(int) DB::table('rate_quote')->where('rata_id', $bice->id)->value('id') => 92], '2026-12-28');
    vrmGiorno($test, '2026-12-30');

    return $s;
}

function acstCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-degli-accessori.png', (string) ob_get_clean());
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-degli-accessori.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(\App\Settings\PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

function acstTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'acst').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/\s+/u', ' ', $testo);
}

function acstImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('ACCESSORI_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png '.escapeshellarg("{$cartella}/{$nome}.pdf").' '.escapeshellarg("{$cartella}/{$nome}"));
}

dataset('carte degli accessori', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => acstCartaIntestata(),
]);

it('il frontespizio e il riepilogo con gli interessi versati, nei tre casi della carta: fuori dalle entrate da terzi, detti a parte', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = acstViaRoma($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=riepilogo');
    $r->assertOk();
    $pdf = (string) $r->getContent();
    acstImmagini($pdf, 'fascicolo-con-interessi-'.$nome);
    $t = acstTesto($pdf);

    expect($t)->toContain('− entrate da terzi € 240,00 − interessi e spese versati dai condòmini € 0,92 =')
        ->toContain('Interessi e spese versati dai condòmini € 0,92 (Interessi di mora € 0,92): addebitati a singole persone, non sono entrate da terzi; riducono le spese da dividere.')
        ->toContain('spese per capitolo meno entrate e interessi e spese versati')
        ->not->toMatch('/\b(dovut[oa]|esigibil[ei]|titolo)\b/iu');
})->with('carte degli accessori');

it('la ricevuta del versamento e la lettera, nei tre casi della carta: «Interessi di mora al 20/12/2026»; [interessi] e [tasso] da ciò che è registrato', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = acstViaRoma($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $r = $this->actingAs($this->user)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s['c']->id]), ['incassi' => [$s['incasso_interessi']], 'bollo' => 'assolta']);
    expect($r->status())->toBe(200, (string) $r->getContent());
    acstImmagini((string) $r->getContent(), 'ricevuta-con-interessi-'.$nome);
    expect(acstTesto((string) $r->getContent()))->toContain('Interessi di mora al 20/12/2026')->not->toContain('Rata 1 — Interessi');

    $l = $this->actingAs($this->user)->post(route('admin.gestionale.lettere.pdf-anteprima', ['condominio' => $s['c']->id]), ['tipo' => 'sollecito',
        'persone' => [$s['persone']['ugo']->id], 'testo' => "Gentile [nome],\nrisultano da versare [totale], di cui interessi di mora [interessi] al tasso [tasso].", 'termine' => 15,
        'indirizzi' => ['email']]);
    expect($l->status())->toBe(200, (string) $l->getContent());
    acstImmagini((string) $l->getContent(), 'lettera-con-interessi-'.$nome);
    expect(acstTesto((string) $l->getContent()))->toContain('di cui interessi di mora € 1,18 al tasso 1,60 % annuo, tasso legale.');
})->with('carte degli accessori');

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le stampe dell'accordo di rientro con il calendario, nei tre casi della carta (senza studio, con lo studio e il logo, con la carta intestata), sul caso
 * di Neri (`AccordoCalendarioScenario.php`):
 * - l'accordo stampato per la persona: il debito riconosciuto rata per rata (€ 200,00, € 0,92, € 25,00 = € 225,92), il calendario (75,31 + 75,31 +
 *   75,30 = 225,92), la regola di decadenza come è scritta, l'autorizzazione, lo spazio delle firme; una pagina;
 * - la ricevuta del versamento dell'accordo del 31/07: le righe e «Imputazione: versamento dell'accordo di rientro.»;
 * - la stampa dell'elenco «Rate scadute» al 02/10: la riga di Neri con il calendario e il giorno del segnale.
 * Le immagini a 70 dpi nella cartella data dall'ambiente (`ACCORDO_IMMAGINI`), per guardarle.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../Gestionale/AccordoCalendarioScenario.php';
require_once __DIR__ . '/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

function arsCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-dell-accordo.png', (string) ob_get_clean());
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-dell-accordo.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(\App\Settings\PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

function arsTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'ars') . '.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    // Lo spazio che lega «€» alla cifra, e le legature di pdftotext («ﬁ»).
    return (string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", 'ﬁ', 'ﬂ'], [' ', 'fi', 'fl'], $testo));
}

function arsImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('ACCORDO_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png ' . escapeshellarg("{$cartella}/{$nome}.pdf") . ' ' . escapeshellarg("{$cartella}/{$nome}"));
}

dataset('carte dell\'accordo', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => arsCartaIntestata(),
]);

it('l\'accordo con il calendario, la ricevuta del versamento dell\'accordo e l\'elenco delle rate scadute, nei tre casi della carta', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    [$s, $interessi, $sollecito, $a] = aclAccordo($this);
    $incasso = aclVersa($this, $s, [adbQuota($interessi)->id => 92, adbQuota($sollecito)->id => 2500, lcdQuota($s, 'Neri', 1) => 4939], '2026-07-31');
    $this->travelTo('2026-10-02 10:00:00');
    $carta();
    $nome = str_replace([' ', "'"], '-', (string) $this->dataName());

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.accordi-rientro.stampa', ['condominio' => $s['c']->id, 'accordo' => $a->id]));
    expect($r->status())->toBe(200, (string) $r->getContent());
    $pdf = (string) $r->getContent();
    arsImmagini($pdf, 'accordo-' . $nome);
    $t = arsTesto($pdf);
    expect(cdsNumeroPagine($pdf))->toBe(1)
        ->and($t)->toContain('Accordo di rientro')
        ->toContain('Il debito riconosciuto da Neri è di € 225,92, per le rate qui sotto. Si versa con le rate del calendario, fino al 30/09/2026.')
        ->toContain('Interessi di mora al 29/06/2026')->toContain('Spese di sollecito')->toContain('€ 200,00')
        ->toContain('31/07/2026 € 75,31')->toContain('31/08/2026 € 75,31')->toContain('30/09/2026 € 75,30')
        ->toContain('Regola di decadenza: 2 rate del calendario non versate, anche non consecutive; oppure 15 giorni dopo una rata non versata.')
        ->toContain('Non autorizzato dall\'assemblea.')
        ->not->toMatch('/\b(decadut[oai]|dovut[oai]|esigibil[ei]|moros[oai])\b/iu');

    $ricevuta = $this->actingAs($this->user)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s['c']->id]), ['incassi' => [$incasso], 'bollo' => 'assolta']);
    expect($ricevuta->status())->toBe(200, (string) $ricevuta->getContent());
    arsImmagini((string) $ricevuta->getContent(), 'ricevuta-accordo-' . $nome);
    expect(arsTesto((string) $ricevuta->getContent()))->toContain('Imputazione: versamento dell\'accordo di rientro.')
        ->toContain('Interessi di mora al 29/06/2026')->toContain('Spese di sollecito');

    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1]));
    expect($elenco->status())->toBe(200, (string) $elenco->getContent());
    arsImmagini((string) $elenco->getContent(), 'rate-scadute-accordo-' . $nome);
    expect(arsTesto((string) $elenco->getContent()))->toContain('accordo di rientro del 15/07/2026, fino al 30/09/2026 (data passata): riconosciuti € 225,92, oggi € 150,61; 2 rate non versate; '
        . 'la condizione di decadenza scritta nell\'accordo si è verificata il 16/09/2026');

    // L'accordo con la decadenza registrata: la riga in testa.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]),
        ['data' => '2026-10-02', 'nota' => 'Comunicata con la raccomandata del 02/10'])->assertOk();
    $chiuso = (string) $this->actingAs($this->user)->get(route('admin.gestionale.accordi-rientro.stampa', ['condominio' => $s['c']->id, 'accordo' => $a->id]))->getContent();
    arsImmagini($chiuso, 'accordo-con-la-decadenza-' . $nome);
    expect(cdsNumeroPagine($chiuso))->toBe(1)->and(arsTesto($chiuso))->toContain('Decadenza registrata il 02/10/2026: Comunicata con la raccomandata del 02/10');
})->with('carte dell\'accordo');

it('un accordo senza calendario non ha la stampa; un accordo di un altro condominio fa 404', function () {
    [$s, $interessi, $sollecito] = aclNeri($this);
    $this->travelTo('2026-07-15 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'fino_al' => '2026-12-15', 'autorizzato_assemblea' => '0',
        'rate' => aclCoperte($s, $interessi, $sollecito),
    ])->assertCreated();
    $senza = \App\Models\Gestionale\AccordoRientro::sole();
    $this->actingAs($this->user)->get(route('admin.gestionale.accordi-rientro.stampa', ['condominio' => $s['c']->id, 'accordo' => $senza->id]))->assertNotFound();
    $altro = accViaVerdi($this);
    $this->actingAs($this->user)->get(route('admin.gestionale.accordi-rientro.stampa', ['condominio' => $altro['c']->id, 'accordo' => $senza->id]))->assertNotFound();
});

it('la stampa di un accordo chiuso lo dice in testa: la decadenza registrata, o la chiusura con la sua nota', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    [$s, , , $a] = aclAccordo($this);
    $this->travelTo('2026-10-02 10:00:00');
    $stampa = fn () => arsTesto((string) $this->actingAs($this->user)->get(route('admin.gestionale.accordi-rientro.stampa', ['condominio' => $s['c']->id, 'accordo' => $a->id]))->getContent());
    expect($stampa())->not->toContain('Decadenza registrata')->not->toContain('Accordo chiuso');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $a->id]),
        ['data' => '2026-10-02', 'nota' => 'Comunicata con la raccomandata del 02/10'])->assertOk();
    expect($stampa())->toContain('Decadenza registrata il 02/10/2026: Comunicata con la raccomandata del 02/10');
});

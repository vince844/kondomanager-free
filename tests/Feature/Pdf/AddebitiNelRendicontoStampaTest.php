<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le stampe con un contributo addebitato a una persona sola, nei tre casi della carta (senza studio, con lo studio e il logo, con la carta intestata):
 * il frontespizio ha la riga dei contributi nel prospetto, con la frase che dice che cosa sono; il riepilogo ha la riga nel blocco delle quote, senza
 * deliberato, con la stessa cifra, e il raccordo li toglie dalle quote registrate; il sollecito fatturato e addebitato ha la riga sua, «Spese di una
 * sola unità chieste a una persona». La lettera di sollecito chiama l'addebito con la sua causale. Senza contributi nessuna delle righe. Le immagini
 * a 70 dpi nella cartella data dall'ambiente (`ADDEBITI_IMMAGINI`), per guardarle.
 *
 * Via Verdi al 01/03/2027, con il passaggio di U2 e una spesa personale di U3: a Neri un contributo di € 50,00 e un sollecito fatturato di € 25,00,
 * registrati il 02/11/2026.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/AddebitiScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

/** Via Verdi con le spese a giornale come le scrive una fattura, il passaggio di U2 e la spesa personale di U3; al 01/03/2027. */
function adbsViaVerdi($test, bool $conAddebito): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    if ($conAddebito) {
        $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-10-20');
        $test->travelTo('2026-11-02 10:00:00');
        adbRegistra($test, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Spesa della cantina', 'scadenza' => '2026-11-02'],
            ['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'riga_fattura_id' => $riga, 'scadenza' => '2026-11-02']])
            ->assertCreated();
    }
    $test->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));

    return $s;
}

/** La carta intestata in immagine A4 per tutti i gruppi, come nelle altre prove della carta. */
function adbsCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-degli-addebiti.png', (string) ob_get_clean());
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-degli-addebiti.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(\App\Settings\PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

/** Il testo di un PDF con pdftotext, con gli spazi di fila uniti. */
function adbsTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'adbs').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/\s+/u', ' ', $testo);
}

function adbsImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('ADDEBITI_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png '.escapeshellarg("{$cartella}/{$nome}.pdf").' '.escapeshellarg("{$cartella}/{$nome}"));
}

dataset('carte degli addebiti', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => adbsCartaIntestata(),
]);

it('il frontespizio e il riepilogo con un contributo addebitato, nei tre casi della carta: la riga nel prospetto, nel blocco delle quote e nel raccordo', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = adbsViaVerdi($this, true);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=riepilogo');
    $r->assertOk();
    $pdf = (string) $r->getContent();
    adbsImmagini($pdf, 'fascicolo-con-contributo-'.$nome);
    $t = adbsTesto($pdf);

    expect($t)->toContain('Contributi addebitati a singole persone, di cui non versati € 50,00 € 50,00')
        ->toContain('Contributi addebitati a singole persone: somme chieste a una persona sola, fuori dal riparto; sono fra le quote registrate.')
        // Nel blocco delle quote del riepilogo la stessa cifra del frontespizio, e il sollecito nella riga sua; nessuna delle due ha un deliberato.
        ->toContain('Contributi addebitati a singole persone — € 50,00 € 0,00 € 50,00')
        ->toContain('Spese di una sola unità chieste a una persona — € 25,00 € 0,00 € 25,00')
        ->toContain('− contributi addebitati a singole persone € 50,00')
        ->not->toMatch('/\b(dovut[oa]|esigibil[ei]|titolo)\b/iu');
})->with('carte degli addebiti');

it('la lettera di sollecito a Neri, nei tre casi della carta: l\'addebito ha la sua causale, le rate del preventivo il loro numero', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = adbsViaVerdi($this, true);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $r = $this->actingAs($this->user)->post(route('admin.gestionale.lettere.pdf-anteprima', ['condominio' => $s['c']->id]), ['tipo' => 'sollecito',
        'persone' => [$s['persone']['Neri']->id], 'testo' => "Gentile [nome],\nrisultano da versare [totale].", 'termine' => 15,
        'indirizzi' => ['email']]);
    expect($r->status())->toBe(200, (string) $r->getContent());
    $pdf = (string) $r->getContent();
    adbsImmagini($pdf, 'lettera-con-addebito-'.$nome);
    $t = adbsTesto($pdf);

    expect($t)->toContain('Spesa della cantina — addebito')->toContain('Spese di sollecito — addebito')->toContain('Rata 4 — ')
        ->not->toContain('Addebiti individuali');
})->with('carte degli addebiti');

it('controprova — senza contributi il frontespizio e il riepilogo non li nominano', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = adbsViaVerdi($this, false);
    cdsSenzaStudio();
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=riepilogo');
    $r->assertOk();
    expect(adbsTesto((string) $r->getContent()))->not->toContain('Contributi addebitati')->not->toContain('contributi addebitati');
});

it('nel frontespizio le quote pagate e non pagate sono quelle dei piani, e il contributo non versato è detto accanto ai contributi', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = adbsViaVerdi($this, true);
    cdsSenzaStudio();
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=riepilogo');
    $r->assertOk();
    $t = adbsTesto((string) $r->getContent());

    // Le quote dei piani € 3.950,00: pagate € 3.650,00 e non pagate € 300,00; il contributo di Neri, € 50,00, non è versato.
    expect($t)->toContain('Contributi addebitati a singole persone, di cui non versati € 50,00 € 50,00')
        ->toContain('Quote dei piani pagate € 3.650,00')
        ->toContain('Quote dei piani non pagate € 300,00')
        ->not->toMatch('/(?<!dei piani )Quote pagate/u');
});

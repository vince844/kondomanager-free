<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le stampe toccate dal controllo della banca e dalle frasi del conguaglio, nei tre casi della carta (senza studio, con lo studio e il logo, con la
 * carta intestata): la stampa dei controlli con la riga della banca (le due cifre, o che cosa manca al posto di due zeri; il rimedio nelle parole per
 * chi legge, senza «qui sotto»), il frontespizio del fascicolo con il motivo del controllo non calcolato, il conguaglio per persona con la riga «non
 * ancora calcolato» senza promesse. Le immagini a 70 dpi nella cartella data dall'ambiente (`BANCA_IMMAGINI`), per guardarle.
 *
 * Via Verdi al 01/03/2027: la banca ha € 4.650,00 in contabilità al 31/12/2026; l'estratto scritto € 4.700,00, la differenza € 50,00 in più nell'estratto.
 */

use App\Models\CartaStampa;
use App\Models\User;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\ParoleDellaStampa;
use App\Services\Gestionale\SaldiDelleBanche;
use App\Services\PDF\CartaDiStampa;
use App\Settings\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
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

function cbsViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $test->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));

    return $s;
}

/** La carta intestata in immagine A4 per il gruppo del rendiconto, come nelle altre prove della carta. */
function cbsCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-della-banca.png', (string) ob_get_clean());
    $carta = CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-della-banca.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

/** Il testo di un PDF con pdftotext, com'è disposto, con gli spazi di fila uniti. */
function cbsTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'cbs').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/[ \t]+/u', ' ', $testo);
}

function cbsImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('BANCA_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png '.escapeshellarg("{$cartella}/{$nome}.pdf").' '.escapeshellarg("{$cartella}/{$nome}"));
}

function cbsPdf($test, string $indirizzo): string
{
    $r = $test->actingAs($test->user)->get($indirizzo);
    $r->assertOk();

    return (string) $r->getContent();
}

dataset('carte della banca', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => cbsCartaIntestata(),
]);

it('la stampa dei controlli e il frontespizio nei tre casi della carta: la riga della banca, il rimedio per chi legge, il motivo del «non calcolato»', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = cbsViaVerdi($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());

    // Senza l'estratto: la riga dice che cosa manca, non due cifre a zero; il frontespizio dice il motivo.
    $controlli = cbsPdf($this, route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=controlli');
    cbsImmagini($controlli, 'controlli-senza-estratto-'.$nome);
    $t = preg_replace('/\s+/u', ' ', cbsTesto($controlli));
    expect($t)->toContain('Il saldo della banca e la contabilità')->toContain('saldo della banca non scritto')->toContain(ParoleDellaStampa::VARIANTI[SaldiDelleBanche::RIMEDIO_SCRIVI])
        ->not->toContain('€ 0,00 = € 0,00')->not->toContain(SaldiDelleBanche::RIMEDIO_SCRIVI);

    $fascicolo = cbsPdf($this, route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]).'?parti[]=riepilogo&parti[]=controlli');
    cbsImmagini($fascicolo, 'frontespizio-'.$nome);
    expect(preg_replace('/\s+/u', ' ', cbsTesto($fascicolo)))->toContain('il saldo della banca non è confrontato, perché l\'amministratore non ha indicato il saldo dell\'estratto conto della banca.');

    // Con l'estratto e una differenza di € 50,00: le due cifre, «da leggere», il rimedio nelle parole per chi legge.
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    $c['banca'] = [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 470000, 'spiegazione' => 'Un versamento del 31/12 arrivato in banca.']];
    NotaSinteticaDelRendiconto::salva($s['e'], $c, null);
    $controlli = cbsPdf($this, route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=controlli');
    cbsImmagini($controlli, 'controlli-con-differenza-'.$nome);
    $t = preg_replace('/\s+/u', ' ', cbsTesto($controlli));
    expect($t)->toContain('€ 4.700,00 ≠ € 4.650,00')->toContain('l\'amministratore lo spiega in assemblea')
        ->toContain(ParoleDellaStampa::VARIANTI[SaldiDelleBanche::RIMEDIO_CAUSE])->not->toContain('Qui sotto')->not->toMatch('/errore/i');
})->with('carte della banca');

it('il conguaglio per persona con una riga non ancora calcolata, nei tre casi della carta: la riga non entra nei totali, senza promesse', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = cbsViaVerdi($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $pdf = cbsPdf($this, route('admin.gestionale.esercizi.rendiconto.conguaglio.stampa', [$s['c'], $s['e']]));
    cbsImmagini($pdf, 'conguaglio-per-persona-'.$nome);
    expect(preg_replace('/\s+/u', ' ', cbsTesto($pdf)))->toContain('«Non ancora calcolato»: la cifra di quella riga non c\'è, e la riga non entra nei totali.')
        ->not->toContain('completerà')->not->toContain('manderà');
})->with('carte della banca');

it('la riga della banca con l\'estratto di un conto su due, nei tre casi della carta: al singolare', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = cbsViaVerdi($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    $conto = \App\Models\Gestionale\ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => '10.11', 'nome' => 'Conto postale', 'tipo' => 'attivo', 'ruolo' => 'banca',
        'categoria' => 'liquidita']);
    \App\Models\Gestionale\Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => 'Conto postale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    $c['banca'] = [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 465000, 'spiegazione' => '']];
    NotaSinteticaDelRendiconto::salva($s['e'], $c, null);
    $pdf = cbsPdf($this, route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=controlli');
    cbsImmagini($pdf, 'controlli-un-conto-su-due-'.$nome);
    $t = preg_replace('/\s+/u', ' ', cbsTesto($pdf));
    expect($t)->toContain('saldo della banca scritto per 1 conto su 2')->not->toContain('1 conti');
})->with('carte della banca');

it('la nota con il saldo della banca e un fondo, nei tre casi della carta: la contabilità con le sue due parti, e «coincidono»', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = cbsViaVerdi($this);
    $carta();
    $nome = str_replace(' ', '-', (string) $this->dataName());
    // Un fondo con € 500,00 accantonati dalla banca il 30/06: sul conto € 4.150,00, nel fondo € 500,00; l'estratto € 4.650,00.
    $conto = \App\Models\Gestionale\ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => '10.40', 'nome' => 'Fondo riserva', 'tipo' => 'attivo', 'ruolo' => 'fondo',
        'categoria' => 'liquidita']);
    $fondo = \App\Models\Gestionale\Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => 'Fondo riserva', 'tipo' => 'fondo', 'attiva' => true,
        'saldo_iniziale' => 0]);
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-06-30',
        'data_competenza' => '2026-06-30', 'numero_protocollo' => 'GIR-2026-00001', 'causale' => 'Accantonamento', 'tipo_movimento' => 'giroconto', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $s['cassa']->conto_contabile_id, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'avere', 'importo' => 50000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $conto->id, 'cassa_id' => $fondo->id, 'tipo_riga' => 'dare', 'importo' => 50000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    $c['banca'] = [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 465000, 'spiegazione' => '']];
    NotaSinteticaDelRendiconto::salva($s['e'], $c, null);

    $pdf = cbsPdf($this, route('admin.gestionale.esercizi.rendiconto.nota.stampa', [$s['c'], $s['e']]));
    cbsImmagini($pdf, 'nota-banca-e-fondo-'.$nome);
    expect(preg_replace('/\s+/u', ' ', cbsTesto($pdf)))->toContain('secondo la contabilità € 4.650,00 (€ 4.150,00 sul conto e € 500,00 nei fondi): coincidono.')
        ->not->toContain('la differenza è');
})->with('carte della banca');


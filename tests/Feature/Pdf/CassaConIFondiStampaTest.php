<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La stampa della scheda «Cassa» (§ 5, le entrate e le uscite per cassa) con i fondi dentro il conto corrente, nei tre casi della carta: il conto chiude
 * con il saldo della banca; sotto, il gruppo «Come si divide il conto» con la sua riga di testa, i fondi con il loro tipo fra parentesi («messi nel
 * fondo», «usciti dal fondo»), la parte libera con quanto è andato nei fondi e quanto ne è tornato, la riga «si può usare»; il «Totale delle casse» con la riga che
 * dice che cosa somma; la frase corta sotto la tabella.
 *
 * Il caso, a mano: la banca aperta a € 5.000,00; un incasso di € 2.000,00; € 1.000,00 messi nel fondo dei lavori, vincolato; € 300,00 messi nel fondo di
 * riserva, generico, e € 100,00 tornati in banca; € 300,00 prelevati in contanti; un pagamento di € 700,00. Il conto: 5.000 + 2.000 − (300 + 700) =
 * € 6.000,00; nel gruppo il fondo di riserva 0 + 300 − 100 = € 200,00, il fondo dei lavori € 1.000,00, la parte libera 5.000 + (2.000 + 100) − (1.300 +
 * 300 + 700) = € 4.800,00; si può usare 4.800 + 200 = € 5.000,00 (il fondo dei lavori è vincolato). I contanti € 300,00. Il totale delle casse: 5.000,
 * 2.000, 700, 6.300: la somma delle due righe in grassetto, meno i € 300,00 passati dal conto ai contanti nelle entrate e nelle uscite.
 */

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
    Storage::fake('public');
});

function cfsCassa(array $s, string $tipo, string $nome): Cassa
{
    $conto = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => 'CF-'.substr(md5($nome), 0, 5), 'nome' => $nome, 'tipo' => 'attivo', 'ruolo' => 'conto_'.$tipo,
        'categoria' => 'liquidita']);

    return Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => $nome, 'tipo' => $tipo, 'attiva' => true, 'saldo_iniziale' => 0]);
}

function cfsScrittura(array $s, string $data, string $tipo, array $dare, array $avere, int $importo): void
{
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => $data,
        'data_competenza' => $data, 'numero_protocollo' => 'CFS-'.uniqid(), 'causale' => $tipo, 'tipo_movimento' => $tipo, 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$dare, 'dare'], [$avere, 'avere']] as [[$conto, $cassa], $verso]) {
        DB::table('righe_scritture')->insert(['scrittura_id' => $id, 'conto_contabile_id' => $conto, 'cassa_id' => $cassa, 'tipo_riga' => $verso, 'importo' => $importo,
            'created_at' => now(), 'updated_at' => now()]);
    }
}

function cfsCaso(): array
{
    $s = be52Condominio();
    $s['cassa']->update(['saldo_iniziale' => 0, 'nome' => 'Conto corrente condominiale']);
    $fondo = cfsCassa($s, 'fondo', 'Fondo lavori straordinari');
    $fondo->update(['sottotipo_fondo' => 'vincolato_lavori', 'vincolo_descrizione' => 'rifacimento della facciata']);
    $riserva = cfsCassa($s, 'fondo', 'Fondo di riserva');
    $contanti = cfsCassa($s, 'contanti', 'Cassa contanti');
    $b = [(int) $s['cassa']->conto_contabile_id, (int) $s['cassa']->id];
    $conto = fn (string $ruolo) => [(int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id'), null];
    cfsScrittura($s, '2026-01-01', 'apertura', $b, $conto('passate_gestioni'), 500000);
    cfsScrittura($s, '2026-03-01', 'incasso_rata', $b, $conto('crediti_condomini'), 200000);
    cfsScrittura($s, '2026-04-01', 'giroconto', [(int) $fondo->conto_contabile_id, (int) $fondo->id], $b, 100000);
    cfsScrittura($s, '2026-04-15', 'giroconto', [(int) $riserva->conto_contabile_id, (int) $riserva->id], $b, 30000);
    cfsScrittura($s, '2026-07-01', 'giroconto', $b, [(int) $riserva->conto_contabile_id, (int) $riserva->id], 10000);
    cfsScrittura($s, '2026-05-01', 'giroconto', [(int) $contanti->conto_contabile_id, (int) $contanti->id], $b, 30000);
    cfsScrittura($s, '2026-06-01', 'pagamento_fornitore', $conto('debiti_fornitori'), $b, 70000);

    return $s;
}

function cfsTesto(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'cfs').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/[ \t]+/u', ' ', $testo);
}

/** Il testo del PDF nell'ordine in cui è scritto (cella dopo cella), con gli spazi uniti: per leggere una riga della tabella con le sue note. */
function cfsInOrdine(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'cfs').'.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -raw '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return (string) preg_replace('/[\s\x{a0}]+/u', ' ', $testo);
}

function cfsCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-della-cassa.png', (string) ob_get_clean());
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-della-cassa.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $p = app(\App\Settings\PrintSettings::class);
    $p->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $p->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

dataset('carte della cassa', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => cfsCartaIntestata(),
]);

it('la stampa della scheda Cassa con i fondi dentro il conto, nei tre casi della carta: il conto, il gruppo «Come si divide il conto», il «Totale delle casse», la frase corta', function (\Closure $carta) {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = cfsCaso();
    $this->travelTo(\Carbon\Carbon::parse('2027-01-15 10:00', 'Europe/Rome'));
    $carta();
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=cassa');
    $r->assertOk();
    $pdf = (string) $r->getContent();
    $cartella = getenv('CASSA_IMMAGINI') ?: null;
    if ($cartella !== null && trim((string) shell_exec('command -v pdftoppm')) !== '') {
        @mkdir($cartella, 0777, true);
        $nome = str_replace(' ', '-', (string) $this->dataName());
        file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
        shell_exec('pdftoppm -r 70 -png '.escapeshellarg("{$cartella}/{$nome}.pdf").' '.escapeshellarg("{$cartella}/cassa-{$nome}"));
    }
    $t = cfsTesto($pdf);
    // Il testo estratto dal PDF ha lo spazio che non va a capo come uno spazio qualunque.
    $una = preg_replace('/[\s\x{a0}]+/u', ' ', $t);

    $ordine = cfsInOrdine($pdf);

    // La riga del conto, in grassetto, e la riga dei contanti, come prima.
    expect($t)->toMatch('/Conto corrente condominiale · banca\s+€ 5\.000,00\s+€ 2\.000,00\s+€ 1\.000,00\s+€ 6\.000,00/u')
        ->toMatch('/Cassa contanti · contanti\s+€ 0,00\s+€ 300,00\s+€ 0,00\s+€ 300,00/u');
    // Il gruppo, nell'ordine: la riga di testa, i fondi con le loro note e il loro tipo, la parte libera con le sue, «si può usare»; poi i contanti.
    expect($ordine)->toContain('Come si divide il conto · le righe qui sotto sono già nella riga del conto, non si sommano al totale')
        ->toMatch('/Come si divide il conto .*? in «Fondo di riserva» \(generico, si può usare\) € 0,00 € 300,00 messi nel fondo € 100,00 usciti dal fondo € 200,00 '
            .'in «Fondo lavori straordinari» \(vincolato ai lavori: rifacimento della facciata\) € 0,00 € 1\.000,00 messi nel fondo € 0,00 usciti dal fondo € 1\.000,00 '
            .'libero € 5\.000,00 € 2\.100,00 di cui € 100,00 tornati dai fondi € 2\.300,00 di cui € 1\.300,00 messi nei fondi € 4\.800,00 '
            .'si può usare: la parte libera e i fondi generici o sbloccati € 5\.000,00 Cassa contanti/u')
        // Il totale delle casse, con la riga che dice che cosa somma.
        ->toMatch('/Totale delle casse il denaro entrato e uscito dal condominio: la somma delle righe in grassetto, meno € 300,00 passati da una cassa all\'altra '
            .'€ 5\.000,00 € 2\.000,00 € 700,00 € 6\.300,00/u')
        ->not->toContain('accantonati')
        ->not->toContain('di cui in «')
        ->not->toContain('Tutte le casse, flussi con l\'esterno');
    // La frase sotto la tabella è corta: la tabella dice da sé come si divide il conto e gli spostamenti verso i fondi.
    expect($una)->toContain('La riga «si può usare» è la parte libera più i fondi generici e quelli sbloccati dall\'assemblea: i fondi vincolati ai lavori, il TFR, la morosità e i fondi senza tipo restano fuori.')
        ->toContain('La disponibilità iniziale è quanto c\'era il giorno prima dell\'inizio dell\'esercizio')
        ->not->toContain('Ogni riga quadra da sé')
        ->not->toContain('Un fondo è una parte dello stesso conto corrente')
        ->not->toContain('Gli spostamenti fra la parte libera e i fondi')
        ->not->toContain('I trasferimenti fra le casse')
        ->not->toContain('· fondo');
})->with('carte della cassa');

it('il fondo da cui € 100,00 sono tornati nella parte libera non dice «usati»: dice «usciti dal fondo», e la parte libera «tornati dai fondi»', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $s = cfsCaso();
    $this->travelTo(\Carbon\Carbon::parse('2027-01-15 10:00', 'Europe/Rome'));
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=cassa');
    $r->assertOk();
    $t = cfsInOrdine((string) $r->getContent());

    expect($t)->toContain('€ 100,00 usciti dal fondo')->toContain('tornati dai fondi')->not->toMatch('/€ [\d.,]+ usati/u');
});

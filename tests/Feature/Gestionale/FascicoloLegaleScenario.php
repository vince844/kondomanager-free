<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Lo scenario del fascicolo per il legale, sopra quello dei sei mesi (Via Verdi 12, oggi 20/05/2026): Rossi ha cinque rate scadute da € 100,00, le rate 1 e 2
 * con la data dei sei mesi dell'esercizio 2025 (30/06/2026) e le rate 3, 4 e 5 dell'esercizio 2026 (30/06/2027), tutte del piano «Preventivo 2026», che è
 * dell'esercizio 2026. Qui le lettere con il PDF conservato e le prove, il rendiconto fermato o approvato con i suoi documenti, l'accordo con la scansione,
 * e le letture della finestra e dell'archivio.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\RendicontoVersione;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

/** Via Verdi 12 al 20/05/2026, con il disco dei documenti e quello dei file pubblici finti (chi chiama toglie Vite nel suo `beforeEach`). */
function fslScenario($test): array
{
    Storage::fake('local');
    Storage::fake('public');

    return smaViaVerdi($test);
}

/** Il contenuto di un PDF finto, riconoscibile. */
function fslPdf(string $chi): string
{
    return "%PDF-1.4\n% " . $chi . "\n" . str_repeat('x', 64) . "\n%%EOF\n";
}

/** Un documento sul disco dei documenti, legato a un modello. */
function fslDocumento(\Illuminate\Database\Eloquent\Model $di, string $nome, ?string $contenuto, string $estensione = 'pdf', ?string $creatoIl = null): Documento
{
    $percorso = 'documenti/prova/' . \Illuminate\Support\Str::random(20) . '.' . $estensione;
    if ($contenuto !== null) {
        Storage::disk('local')->put($percorso, $contenuto);
    }
    $d = $di->documenti()->create(['name' => $nome, 'description' => 'Prova', 'path' => $percorso, 'mime_type' => $estensione === 'pdf' ? 'application/pdf' : 'image/jpeg',
        'file_size' => strlen((string) $contenuto), 'is_published' => false, 'is_approved' => true]);
    if ($creatoIl !== null) {
        DB::table('documenti')->where('id', $d->id)->update(['created_at' => $creatoIl, 'updated_at' => $creatoIl]);
    }

    return $d->fresh();
}

/**
 * Una lettera del registro con le sue rate, il giorno e l'ora di Roma (`2026-03-02 10:15`), e il PDF conservato: `pdf` il contenuto (null: nessun PDF
 * conservato), `impronta` (true: quella del contenuto; false: nessuna; una stringa: quella), `sparito` (il file non c'è più sul disco), `diverso` (il file sul
 * disco non è quello conservato), `spedizione` (i dati della spedizione per una lettera spedita).
 */
function fslLettera(array $s, string $chi, string $tipo, string $stato, string $quando, array $numeri, array $opz = []): RegistroInvio
{
    $riga = smaLettera($s, $chi, $tipo, $stato, substr($quando, 0, 10), $numeri, isset($opz['spedizione']) ? ['spedizione' => $opz['spedizione']] : []);
    $riga->update(['emesso_il' => \Illuminate\Support\Carbon::parse($quando, 'Europe/Rome')->utc()]);
    $contenuto = array_key_exists('pdf', $opz) ? $opz['pdf'] : fslPdf($tipo . ' ' . $quando . ' ' . $chi);
    if ($contenuto === null) {
        return $riga->fresh();
    }
    $d = fslDocumento($riga, ucfirst($tipo) . ' ' . $chi . '.pdf', ($opz['sparito'] ?? false) ? null : (($opz['diverso'] ?? false) ? $contenuto . 'cambiato' : $contenuto), 'pdf',
        \Illuminate\Support\Carbon::parse($quando, 'Europe/Rome')->utc()->format('Y-m-d H:i:s'));
    $impronta = $opz['impronta'] ?? true;
    $riga->update(['impronta' => $impronta === true ? hash('sha256', $contenuto) : ($impronta === false ? null : $impronta),
        'dati' => (array) $riga->dati + ['documento_id' => $d->id]]);

    return $riga->fresh();
}

/** Una prova di spedizione della lettera, aggiunta il giorno dato. Con `$contenuto` null il file non c'è più. */
function fslProva(RegistroInvio $riga, string $nome, ?string $contenuto = 'cartolina', string $giorno = '2026-03-05 11:00:00'): Documento
{
    return fslDocumento($riga, $nome, $contenuto, pathinfo($nome, PATHINFO_EXTENSION) ?: 'pdf', $giorno);
}

/**
 * Il rendiconto dell'esercizio fermato (`presentata`) o approvato, con i suoi documenti: `pdf` il contenuto del fascicolo conservato (null: nessuno), `parti`
 * (le parti conservate una per una al posto del fascicolo), `verbale` (il contenuto del verbale, null: nessuno), `sparito` (il file del fascicolo non c'è più).
 */
function fslRendiconto(array $s, Esercizio $e, string $stato, array $opz = []): RendicontoVersione
{
    $v = RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'numero' => 1 + (int) RendicontoVersione::where('esercizio_id', $e->id)->max('numero'),
        'stato' => $stato, 'forma' => RendicontoVersione::FORMA, 'cifre' => [], 'calcolato_il' => '2026-04-10 08:00:00',
        'data_delibera' => $stato === RendicontoVersione::APPROVATA ? ($opz['data_delibera'] ?? '2026-04-20') : null,
        'numero_verbale' => $stato === RendicontoVersione::APPROVATA ? ($opz['numero_verbale'] ?? '3') : null,
        'riparto_approvato' => $stato === RendicontoVersione::APPROVATA ? true : null, 'pdf' => ['parti' => [], 'documenti' => []]]);
    $documenti = [];
    $pdf = array_key_exists('pdf', $opz) ? $opz['pdf'] : fslPdf('rendiconto ' . $e->nome);
    if ($pdf !== null) {
        $d = fslDocumento($v, 'rendiconto-' . $e->nome . '.pdf', ($opz['sparito'] ?? false) ? null : $pdf, 'pdf', '2026-04-10 08:00:00');
        $documenti[] = ['documento_id' => $d->id, 'parte' => 'fascicolo', 'impronta' => hash('sha256', $pdf), 'byte' => strlen($pdf), 'pagine' => 22];
    }
    foreach ($opz['parti'] ?? [] as $parte) {
        $contenuto = fslPdf('rendiconto ' . $parte);
        $d = fslDocumento($v, 'rendiconto-' . $parte . '.pdf', $contenuto, 'pdf', '2026-04-10 08:00:00');
        $documenti[] = ['documento_id' => $d->id, 'parte' => $parte, 'impronta' => hash('sha256', $contenuto), 'byte' => strlen($contenuto), 'pagine' => 2];
    }
    $verbale = null;
    $contenutoVerbale = array_key_exists('verbale', $opz) ? $opz['verbale'] : fslPdf('verbale ' . $e->nome);
    if ($contenutoVerbale !== null && $stato === RendicontoVersione::APPROVATA) {
        $verbale = fslDocumento($v, 'verbale-assemblea.pdf', $contenutoVerbale, 'pdf', '2026-04-21 09:00:00');
    }
    $v->update(['pdf' => ['parti' => $opz['parti'] ?? [], 'documenti' => $documenti], 'verbale_documento_id' => $verbale?->id]);

    return $v->fresh();
}

/** Un accordo di rientro della persona sulle rate date, con la scansione firmata (o senza). */
function fslAccordo(array $s, string $chi, array $numeri, bool $conScansione = true): AccordoRientro
{
    $a = smaAccordo($s, $chi, $numeri);
    if ($conScansione) {
        fslDocumento($a, 'accordo-firmato.pdf', fslPdf('accordo ' . $chi), 'pdf', '2026-01-10 12:00:00');
    }

    return $a;
}

/** Le chiavi «rata|unità» delle rate di una persona. */
function fslChiavi(array $s, string $chi, array $numeri): array
{
    return array_map(fn ($n) => smaChiave($s, $chi, $n), $numeri);
}

function fslRotta(array $s, string $chi, string $nome = 'anagrafiche.fascicolo-legale'): string
{
    return route('admin.gestionale.' . $nome, ['condominio' => $s['c']->id, 'anagrafica' => $s['persone'][$chi]->id]);
}

/** L'anteprima della finestra, in JSON. */
function fslAnteprima($test, array $s, string $chi): array
{
    $r = $test->actingAs($test->user)->getJson(fslRotta($s, $chi));
    $r->assertOk();

    return $r->json();
}

/**
 * «Scarica il fascicolo»: di serie tutte le rate scadute della persona e tutte le parti che ci sono. Rende la risposta e i file dell'archivio, nome => contenuto,
 * nell'ordine dell'archivio.
 *
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<string, string>}
 */
function fslScarica($test, array $s, string $chi, ?array $rate = null, ?array $parti = null): array
{
    $anteprima = fslAnteprima($test, $s, $chi);
    $rate ??= collect($anteprima['esercizi'])->flatMap(fn ($e) => array_column($e['rate'], 'chiave'))->all();
    $parti ??= $anteprima['parti'];
    $r = $test->actingAs($test->user)->post(fslRotta($s, $chi, 'anagrafiche.fascicolo-legale.prepara'), ['rate' => $rate, 'parti' => $parti]);
    if ($r->getStatusCode() !== 200) {
        return [$r, []];
    }
    $percorso = tempnam(sys_get_temp_dir(), 'fsl');
    file_put_contents($percorso, $r->streamedContent());
    $zip = new ZipArchive;
    $zip->open($percorso);
    $file = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nome = (string) $zip->getNameIndex($i);
        $file[$nome] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($percorso);
    ksort($file);

    return [$r, $file];
}

/**
 * Il testo di un PDF, con gli spazi ridotti a uno. Di serie nell'ordine in cui mPDF lo scrive (`-raw`: una cella dopo l'altra, così una frase che va a capo in
 * una colonna resta intera); `-layout` per il testo come si vede sulla pagina.
 */
function fslTesto(string $pdf, string $modo = '-raw'): string
{
    $file = tempnam(sys_get_temp_dir(), 'fsl') . '.pdf';
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext ' . $modo . ' ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);

    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", 'ﬁ', 'ﬂ'], [' ', 'fi', 'fl'], $testo)));
}

/** Le immagini di un PDF a 70 dpi nella cartella data dall'ambiente (`FASCICOLO_IMMAGINI`), per guardarle. */
function fslImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('FASCICOLO_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 70 -png ' . escapeshellarg("{$cartella}/{$nome}.pdf") . ' ' . escapeshellarg("{$cartella}/{$nome}"));
}

/** La carta intestata della tipografia per tutti i gruppi, come nelle prove delle altre stampe. */
function fslCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 61, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-del-fascicolo.png', (string) ob_get_clean());
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-del-fascicolo.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $impostazioni->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

function fslSenzaPdftotext($test): void
{
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $test->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
}

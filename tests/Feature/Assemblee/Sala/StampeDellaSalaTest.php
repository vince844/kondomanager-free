<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role as Ruolo;
use App\Models\CartaStampa;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\GruppoCarta;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';
require_once __DIR__ . '/../../Pdf/Support/CartaDelloStudio.php';

/**
 * Le due stampe della sala, sul modello comune delle stampe del condominio (la vista estende `pdf.base`, il servizio passa il gruppo «Rendiconto e
 * assemblea», nessuna testata scritta a mano), nei tre casi della carta: senza lo studio, con lo studio e il logo, con la carta intestata.
 *
 * - **Il foglio della sala** (dalla prima spedizione): le teste del giorno della prossima seduta con le unità e i millesimi, «Presente o delega a» con le
 *   deleghe ricevute già scritte, entrata, uscita e firma; le deleghe ricevute (anche rifiutate); per ogni punto favorevoli, contrari, astenuti e l'ora; il
 *   numero legale delle due convocazioni.
 * - **Le presenze e i voti** (dalla sala aperta o dalla prima deserta): per ogni seduta l'apertura, presidente e segretario, le presenze con come, entrata
 *   e uscita; per ogni votazione favorevoli, contrari e astenuti con i nomi e i millesimi, chi non ha votato, i conflitti, le dichiarazioni, l'esito; in
 *   fondo l'impronta della fotografia e quella del prospetto.
 *
 * Le immagini delle pagine, per guardarle: `SALA_IMMAGINI=/cartella` (servono `pdftoppm`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake('local');
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il testo di tutte le pagine, letto dai flussi di mPDF. */
function sdsTesto(string $pdf): string
{
    $testo = [];
    for ($i = 1; $i <= cdsNumeroPagine($pdf); $i++) {
        $testo[] = cdsTesto($pdf, $i);
    }

    return implode(' ', $testo);
}

/** Le immagini delle pagine a 80 dpi, nella cartella di `SALA_IMMAGINI`, per guardarle. */
function sdsImmagini(string $pdf, string $nome): void
{
    $cartella = getenv('SALA_IMMAGINI') ?: null;
    if ($cartella === null || trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }
    @mkdir($cartella, 0777, true);
    file_put_contents("{$cartella}/{$nome}.pdf", $pdf);
    shell_exec('pdftoppm -r 80 -png ' . escapeshellarg("{$cartella}/{$nome}.pdf") . ' ' . escapeshellarg("{$cartella}/{$nome}"));
}

/** Una carta intestata in immagine A4 per tutti i gruppi: la fascia in alto, i margini della carta. */
function sdsCartaIntestata(): void
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 146, imagecolorallocate($im, 31, 58, 122));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    Storage::disk('public')->put('settings/carte/carta-della-sala.png', (string) ob_get_clean());
    $carta = CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-della-sala.png', 'carta_tipo' => 'immagine', 'carta_pagine' => 1, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'], $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

dataset('carte della sala', [
    'senza studio' => fn () => cdsSenzaStudio(),
    'con lo studio e il logo' => fn () => cdsStudioPieno(true),
    'con la carta intestata' => fn () => sdsCartaIntestata(),
]);

/** Via Roma 12 convocata, con due deleghe ricevute il 10/12 (Rossi a Bianchi Giorgio, fuori dall'anagrafica; Gallo a Bianchi Anna) e una rifiutata. */
function sdsConvocata($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 11:00');
    $delega = fn (string $chi, array $delegato) => $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]),
        ['delegante_anagrafica_id' => $s->persone[$chi]->id, 'delegato' => $delegato, 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    $delega('rossi', ['nome' => 'Bianchi Giorgio']);
    $delega('gallo', ['anagrafica_id' => $s->persone['bianchi']->id]);

    return [$s, $a->refresh()];
}

/** La seconda convocazione tenuta: undici in sala, il voto del punto 1 con un contrario, un astenuto e un conflitto, una dichiarazione, la conferma. */
function sdsTenuta($t): array
{
    [$s, $a] = sdsConvocata($t);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['bianchi']->id],
        'come' => 'in_collegamento', 'motivo' => 'È fuori città per lavoro.'], '2026-12-15 19:20'));
    $p1 = $a->punti()->where('numero', 1)->value('id');
    $v = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1], '2026-12-15 19:05'));
    $id = $v['votazione_id'];
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], '2026-12-15 19:06'));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $id, 'testa' => 'p-' . $s->persone['c30']->id, 'voto' => 'contrario'],
        '2026-12-15 19:07', 1));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $id, 'testa' => 'p-' . $s->persone['c31']->id, 'voto' => 'astenuto'],
        '2026-12-15 19:07', 1));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $id, 'testa' => 'p-' . $s->persone['c32']->id,
        'in_conflitto' => true, 'nota' => 'è il fornitore delle pulizie'], '2026-12-15 19:08'));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('dichiarazione', ['seduta_id' => $seduta->id, 'punto_id' => $p1,
        'anagrafica_id' => $s->persone['rossi']->id, 'testo' => 'Chiedo che le spese legali siano indicate a parte.'], '2026-12-15 19:09'));
    $versione = (int) \App\Models\Assemblee\VotazioneDelPunto::query()->findOrFail($id)->versione;
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $id, 'esito' => 'approvata'], '2026-12-15 19:10', $versione));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['marco']->id]],
        '2026-12-15 20:15'));

    return [$s, $a->refresh(), $seduta->refresh()];
}

/** Una stampa dalla sua rotta: il PDF e la stampa che la spia si ricorda. */
function sdsStampa($t, $spia, $utente, string $indirizzo): array
{
    $prima = count($spia->stampe);
    $r = $t->actingAs($utente)->get($indirizzo);
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $pdf = (string) $r->getContent();
    expect(substr($pdf, 0, 4))->toBe('%PDF');

    return [$pdf, $spia->stampe[$prima] ?? null];
}

it('le due viste estendono pdf.base e non scrivono una testata a mano', function () {
    foreach (['assemblea_foglio_sala', 'assemblea_presenze_voti'] as $vista) {
        $sorgente = (string) file_get_contents(resource_path("views/pdf/gestionale/{$vista}.blade.php"));
        $senzaIlCommento = trim((string) preg_replace('/\{\{--.*?--\}\}/s', '', $sorgente));
        expect($senzaIlCommento)->toStartWith("@extends('pdf.base')")
            ->and($sorgente)->not->toContain('htmlpageheader')->not->toContain('intestazione_propria')->not->toContain('sethtmlpageheader');
    }
});

it('il foglio della sala, nei tre casi della carta: le teste con le unità e i millesimi, le deleghe ricevute già scritte, i punti con i voti e l\'ora', function (\Closure $carta) {
    [$s, $a] = sdsConvocata($this);
    $carta();
    $spia = cdsSpia();
    [$pdf, $stampa] = sdsStampa($this, $spia, $s->admin, route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]));
    sdsImmagini($pdf, 'foglio-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = sdsTesto($pdf);

    expect($stampa['vista'] ?? null)->toBe('pdf.gestionale.assemblea_foglio_sala')
        ->and($stampa['dati']['gruppo_carta'] ?? null)->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($testo)->toContain('Foglio della sala')
        ->toContain('Prima convocazione')->toContain('lunedì 14/12/2026 alle 08:00')->toContain('Seconda convocazione')->toContain('martedì 15/12/2026 alle 18:30')
        ->toContain('Condòmino')->toContain('Presente o delega a')->toContain('Entrata')->toContain('Uscita')->toContain('Firma')
        ->toContain('Rossi Mario')->toContain('delega a Bianchi Giorgio')->toContain('Gallo Enzo')->toContain('delega a Bianchi Anna')
        ->toContain('Russo Anna e Russo Marco')->toContain('Condomino 49')->toContain('27,75')
        ->toContain('26 condòmini · 1.000,00 millesimi')
        ->toContain('In prima convocazione servono 14 condòmini e 666,67 millesimi')->toContain('in seconda 9 condòmini e 333,34 millesimi')
        ->toContain('1. Approvazione del rendiconto 2025')->toContain('Il rendiconto')->toContain('Il riparto')
        ->toContain('4. Sostituzione del portone')->toContain('Favorevoli')->toContain('Contrari')->toContain('Astenuti')->toContain('Votato alle')
        ->toContain('6. Varie ed eventuali')->toContain('Trattato')
        ->toContain('Le deleghe ricevute')->toContain('Registra dal foglio della sala');
    // Ogni testa ha la sua riga: le 26 della fotografia del 14/12.
    expect(substr_count($testo, 'Condomino '))->toBe(20);

    if (str_contains($this->dataName(), 'con lo studio')) {
        expect($testo)->toContain('Studio Bianchi Amministrazioni')->and(cdsHaImmagine($pdf, 1))->toBeTrue();
    } else {
        expect($testo)->not->toContain('Studio Bianchi Amministrazioni')->toContain('Via Roma 12');
    }
    if (str_contains($this->dataName(), 'carta intestata')) {
        expect(cdsHaImmagine($pdf, 1))->toBeTrue();
    }
})->with('carte della sala');

it('nel foglio la delega rifiutata non si scrive accanto al condòmino, e l\'elenco delle deleghe la dice rifiutata con il motivo', function () {
    [$s, $a] = sdsConvocata($this);
    // Bianchi Giorgio ha già Rossi: con quattro deleganti in più supera il quinto delle teste, e la quinta delega si rifiuta.
    foreach (['c30', 'c31', 'c32', 'c33', 'c34'] as $chi) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-12-10']);
    }
    $rifiutata = \App\Models\Assemblee\DelegaDellAssemblea::query()->where('stato', 'rifiutata')->firstOrFail();
    cdsSenzaStudio();
    $r = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk();
    $testo = sdsTesto((string) $r->getContent());

    expect($rifiutata->delegante_nome)->toBe('Condomino 34')
        ->and($testo)->toContain('Condomino 34 a Bianchi Giorgio')->toContain('rifiutata')
        ->and(substr_count($testo, 'delega a Bianchi Giorgio'))->toBe(5);
});

it('le presenze e i voti, nei tre casi della carta: le sedute, le presenze con come ed entrata e uscita, i voti con i nomi e i millesimi, le impronte', function (\Closure $carta) {
    [$s, $a, $seduta] = sdsTenuta($this);
    $carta();
    $spia = cdsSpia();
    [$pdf, $stampa] = sdsStampa($this, $spia, $s->admin, route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a]));
    sdsImmagini($pdf, 'prospetto-' . preg_replace('/\W+/', '-', $this->dataName()));
    $testo = sdsTesto($pdf);

    expect($stampa['vista'] ?? null)->toBe('pdf.gestionale.assemblea_presenze_voti')
        ->and($stampa['dati']['gruppo_carta'] ?? null)->toBe(GruppoCarta::RENDICONTO_E_ASSEMBLEA)
        ->and($testo)->toContain('Le presenze e i voti')
        ->toContain('Prima convocazione')->toContain('Deserta, segnata alle 18:40')
        ->toContain('Seconda convocazione')->toContain('Aperta alle 18:42')->toContain('Presidente: Rossi Mario')->toContain('Segretario: L\'amministratore')
        ->toContain('Rossi Mario')->toContain('In sala')->toContain('18:45')
        ->toContain('Bianchi Anna')->toContain('In collegamento')->toContain('19:20')
        ->toContain('Russo Marco')->toContain('20:15')
        ->toContain('1. Approvazione del rendiconto 2025')->toContain('Votato alle 19:05')
        ->toContain('Favorevoli')->toContain('Contrari')->toContain('Condomino 30')->toContain('27,75')->toContain('Astenuti')->toContain('Condomino 31')
        ->toContain('In conflitto')->toContain('è il fornitore delle pulizie')
        ->toContain('Chiedo che le spese legali siano indicate a parte.')
        ->toContain('Esito confermato: Approvata')
        ->toContain('Impronta della fotografia')->toContain((string) $seduta->impronta_fotografia)
        ->toContain('Impronta di questo prospetto');

    if (str_contains($this->dataName(), 'con lo studio')) {
        expect($testo)->toContain('Studio Bianchi Amministrazioni');
    } else {
        expect($testo)->not->toContain('Studio Bianchi Amministrazioni')->toContain('Via Roma 12');
    }
})->with('carte della sala');

it('l\'impronta del prospetto è la stessa con gli stessi dati e cambia con un gesto nuovo', function () {
    [$s, $a, $seduta] = sdsTenuta($this);
    $impronta = fn () => app(\App\Services\Assemblee\Sala\ProspettoDellaSala::class)->dati($a->refresh())['impronta'];
    $prima = $impronta();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:30');
    expect($impronta())->toBe($prima)->toMatch('/^[0-9a-f]{64}$/');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['carla']->id],
        'come' => 'in_sala'], '2026-12-15 20:20'));
    expect($impronta())->not->toBe($prima);
});

it('il foglio si stampa dalla prima spedizione, il prospetto dalla sala aperta o dalla prima deserta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertNotFound();
    SalaDiViaRoma12::convoca($a, $s);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk();
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a]))->assertNotFound();

    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-14 08:40'));
    $r = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a]))->assertOk();
    expect(sdsTesto((string) $r->getContent()))->toContain('Deserta, segnata alle 08:40');
});

it('i permessi: il foglio con «Gestisce le assemblee», il prospetto con «Vede»; condòmini e fornitori mai; l\'assemblea di un altro condominio è un 404', function () {
    [$s, $a] = sdsTenuta($this);
    $rotte = ['foglio' => route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]), 'prospetto' => route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a])];
    $tutti = [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE];
    $stati = [];
    foreach (['condòmino' => apUtente(Ruolo::UTENTE->value, $tutti), 'fornitore' => apUtente(Ruolo::FORNITORE->value, $tutti),
        'studio senza permessi' => apUtente(null, []), 'vede' => apUtente(null, [Permesso::VIEW_ASSEMBLEE]),
        'gestisce' => apUtente(null, [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE])] as $nome => $u) {
        foreach ($rotte as $quale => $indirizzo) {
            $stati["{$nome} {$quale}"] = $this->actingAs($u)->get($indirizzo)->status();
        }
    }
    expect($stati)->toBe(['condòmino foglio' => 403, 'condòmino prospetto' => 403, 'fornitore foglio' => 403, 'fornitore prospetto' => 403,
        'studio senza permessi foglio' => 403, 'studio senza permessi prospetto' => 403, 'vede foglio' => 403, 'vede prospetto' => 200,
        'gestisce foglio' => 200, 'gestisce prospetto' => 200]);

    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $sua = ViaRoma12::ordinaria($altro);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $sua]))->assertNotFound();
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $sua]))->assertNotFound();
});

it('il passo «In sala» porta al foglio della sala prima del giorno', function () {
    [$s, $a] = sdsConvocata($this);
    $passo = collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'sala');
    expect(collect($passo['manca'])->firstWhere('chiave', 'foglio')['collegamento'])
        ->toBe(['testo' => 'Stampa il foglio', 'url' => route('admin.gestionale.assemblee.sala.foglio', [$s->c->id, $a->id]), 'passo' => null, 'stampa' => true]);
});

it('a sala chiusa il passo «In sala» porta alle presenze e ai voti', function () {
    [$s, $a, $seduta] = sdsTenuta($this);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-15 20:40'));
    $passo = collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'sala');
    expect($passo['stato'])->toBe('fatto')
        ->and(collect($passo['manca'])->firstWhere('chiave', 'prospetto'))->toMatchArray(['fatto' => true, 'informa' => true, 'titolo' => 'Le presenze e i voti',
            'collegamento' => ['testo' => 'Stampa il prospetto', 'url' => route('admin.gestionale.assemblee.sala.prospetto', [$s->c->id, $a->id]), 'passo' => null, 'stampa' => true]]);
});

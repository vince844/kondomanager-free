<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * I salvataggi del testo del verbale (V4): un gesto numerato per salvataggio, dalla rotta vera; il numero due volte conta una (e risponde la revisione di
 * allora); in ordine; due versioni solo se dopo la base ha scritto un altro dispositivo, mai la stessa macchina con sé stessa; dopo la registrazione il testo
 * non si applica ma resta una versione «arrivata dopo»; l'ora nel futuro si rifiuta; la struttura ostile si rifiuta e niente si scrive; il 419 non arriva al
 * servizio; nel libro dei gesti dell'assemblea c'è la riga del salvataggio, mai il testo; la discussione scritta nella sala passa dalla coda della sala.
 *
 * Che cosa non copre: la coda del browser (i vitest della tenuta senza rete).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function gdvDoc(string $testo): array
{
    return StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($testo)]);
}

/** La sala aperta con la discussione del punto 4 da scrivere: [scenario, assemblea, seduta, verbale_id, parte]. */
function gdvSalaAperta($t): array
{
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($t, false);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);

    return [$s, $a, $seduta, (int) $verbale->id, 'punto:' . VerbaleDiViaRoma12::punto($a, 4)];
}

it('un salvataggio scrive la parte con la revisione; lo stesso numero due volte conta una e risponde la revisione di allora; il libro dei gesti non ha il testo', function () {
    [$s, $a, , $id, $parte] = gdvSalaAperta($this);
    $gesto = VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('L\'amministratore illustra i tre preventivi.'), '2026-12-15 20:14', 0);

    $prima = VerbaleDiViaRoma12::manda($this, $s, $a, [$gesto])->assertOk()->json('risposte.0');
    $doppio = VerbaleDiViaRoma12::manda($this, $s, $a, [$gesto])->assertOk()->json('risposte.0');

    expect($prima)->toMatchArray(['esito' => 'applicato', 'revisione' => 1, 'verbale_id' => $id, 'parte' => $parte])
        ->and($doppio)->toMatchArray(['esito' => 'doppio', 'esito_di_allora' => 'applicato', 'revisione' => 1, 'impronta' => $prima['impronta']])
        ->and(ParteDelVerbale::query()->where('verbale_id', $id)->where('parte', $parte)->value('revisione'))->toBe(1);
    $riga = GestoDellaSala::query()->where('gesto', $gesto['gesto'])->firstOrFail();
    expect($riga->tipo)->toBe('verbale_testo')
        ->and(array_keys((array) $riga->dati))->toEqualCanonicalizing(['verbale_id', 'parte', 'revisione', 'impronta', 'caratteri'])
        ->and(json_encode($riga->dati))->not->toContain('preventivi');
});

it('i salvataggi di una stessa macchina, anche partiti da una base vecchia, non sono mai in conflitto con sé stessi; in ordine', function () {
    [$s, $a, , $id, $parte] = gdvSalaAperta($this);
    $risposte = VerbaleDiViaRoma12::manda($this, $s, $a, [
        VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Uno.'), '2026-12-15 20:14', 0),
        VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Uno. Due.'), '2026-12-15 20:14', 1),
        // La risposta del secondo persa: il terzo parte ancora dalla revisione 1.
        VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Uno. Due. Tre.'), '2026-12-15 20:15', 1),
    ])->json('risposte');

    expect(array_column($risposte, 'esito'))->toBe(['applicato', 'applicato', 'applicato'])
        ->and(array_column($risposte, 'revisione'))->toBe([1, 2, 3])
        ->and(StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $id)->where('parte', $parte)->value('documento')))->toBe('Uno. Due. Tre.')
        ->and(VersioneDelVerbale::query()->where('verbale_id', $id)->where('in_attesa', true)->count())->toBe(0);
});

it('due versioni solo se dopo la base ha scritto un altro dispositivo: niente si sovrascrive, il testo arrivato aspetta la scelta', function () {
    [$s, $a, , $id, $parte] = gdvSalaAperta($this);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Dal portatile.'), '2026-12-15 20:14', 0)], 'portatile di Andrea');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Dal portatile, ancora.'), '2026-12-15 20:15', 1)], 'portatile di Andrea');

    $r = VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Dal tablet, scritto senza rete.'), '2026-12-15 20:24', 1)],
        'tablet di Marco')->json('risposte.0');

    $attesa = VersioneDelVerbale::query()->where('verbale_id', $id)->where('in_attesa', true)->firstOrFail();
    expect($r['esito'])->toBe('in_conflitto')
        ->and($r['conflitto'])->toMatchArray(['tipo' => 'due_versioni', 'versione_id' => $attesa->id, 'revisione_di_adesso' => 2, 'dispositivo_di_adesso' => 'portatile di Andrea'])
        ->and($r['messaggio'])->toContain('Non sovrascrivo niente: le due versioni restano, e scegli tu quale tenere, o le unisci.')
        ->and(StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $id)->where('parte', $parte)->value('documento')))->toBe('Dal portatile, ancora.')
        ->and(StrutturaDelVerbale::testo((array) $attesa->documento))->toBe('Dal tablet, scritto senza rete.')
        ->and($attesa->attesa)->toBe('due_versioni')->and($attesa->dispositivo)->toBe('tablet di Marco');
});

it('la struttura ostile si rifiuta e niente si scrive; la parte di un\'altra assemblea e il verbale intero prima della generazione si rifiutano', function () {
    [$s, $a, , $id, $parte] = gdvSalaAperta($this);
    $ostile = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(1)'], 'content' => [['type' => 'text', 'text' => 'x']]]]];
    $risposte = VerbaleDiViaRoma12::manda($this, $s, $a, [
        VerbaleDiViaRoma12::testo($id, $parte, $ostile, '2026-12-15 20:14', 0),
        VerbaleDiViaRoma12::testo($id, 'punto:999999', gdvDoc('Altrove.'), '2026-12-15 20:14', 0),
        VerbaleDiViaRoma12::testo($id, 'intero', gdvDoc('Il verbale.'), '2026-12-15 20:14', 0),
        VerbaleDiViaRoma12::testo($id, $parte, StrutturaDelVerbale::documento([StrutturaDelVerbale::datiDellaSala('voto:1', ['Favorevoli: tutti.'])]), '2026-12-15 20:14', 0),
    ])->json('risposte');

    expect(array_column($risposte, 'esito'))->toBe(['rifiutato', 'rifiutato', 'rifiutato', 'rifiutato'])
        ->and($risposte[0]['messaggio'])->toBe('Nel testo del verbale «paragraph» ha un attributo che il programma non conosce: «onclick».')
        ->and($risposte[1]['messaggio'])->toBe('La parte non è di questo verbale.')
        ->and($risposte[2]['messaggio'])->toBe('Il verbale non è ancora generato: adesso si scrive la discussione dei punti.')
        ->and($risposte[3]['messaggio'])->toContain('nella discussione si scrive solo ciò che si è detto')
        ->and(ParteDelVerbale::query()->where('verbale_id', $id)->count())->toBe(0);
});

it('l\'ora oltre due minuti nel futuro si rifiuta; il gesto di un\'altra assemblea con lo stesso numero si rifiuta', function () {
    [$s, $a, , $id, $parte] = gdvSalaAperta($this);
    $r = VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Domani.'), '2026-12-15 21:10', 0)])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toContain('è avanti rispetto al server');

    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $r = VerbaleDiViaRoma12::manda($this, $s, $b, [VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Altrove.'), '2026-12-15 20:14', 0)])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('Il verbale non è di questa assemblea.');
});

it('la sessione scaduta (419) e chi non gestisce le assemblee (403) non arrivano al servizio: niente si scrive', function () {
    [$s, $a, , $id, $parte] = gdvSalaAperta($this);
    $gesto = VerbaleDiViaRoma12::testo($id, $parte, gdvDoc('Senza sessione.'), '2026-12-15 20:14', 0);
    $this->withMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    $this->app->instance('env', 'production');
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.gesti', [$s->c, $a]), ['dispositivo' => 'x', 'gesti' => [$gesto]],
        ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
    expect($r->status())->toBe(419)->and(GestoDellaSala::query()->where('gesto', $gesto['gesto'])->exists())->toBeFalse();
    $this->app->instance('env', 'testing');

    $chi = ViaRoma12::utente([\App\Enums\Permission::VIEW_ASSEMBLEE]);
    $this->actingAs($chi)->postJson(route('admin.gestionale.assemblee.verbale.gesti', [$s->c, $a]), ['dispositivo' => 'x', 'gesti' => [$gesto]])->assertForbidden();
    expect(GestoDellaSala::query()->where('gesto', $gesto['gesto'])->exists())->toBeFalse();
});

it('la discussione scritta nella sala passa dalla coda della sala, anche prima di conoscere il verbale (con la seduta)', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    $gesto = VerbaleDiViaRoma12::testo(0, $parte, gdvDoc('Scritta in sala.'), '2026-12-15 20:14', 0);
    $gesto['dati'] = ['seduta_id' => $seduta->id, 'parte' => $parte, 'documento' => $gesto['dati']['documento']];

    $r = SalaDiViaRoma12::manda($this, $s, $a, [$gesto])->assertOk()->json('risposte.0');

    expect($r['esito'])->toBe('applicato')->and($r['tipo'])->toBe('testo')->and($r['verbale_id'])->toBeInt()
        ->and(StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $r['verbale_id'])->where('parte', $parte)->value('documento')))->toBe('Scritta in sala.')
        // La sala non vede il testo fra le sue due versioni.
        ->and(app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['conflitti'])->toBe([]);
});

it('dopo la registrazione un testo arrivato da un dispositivo rimasto senza rete non si applica: resta una versione «arrivata dopo la registrazione»', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 6);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:52');

    $r = VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $parte, gdvDoc('Il sig. Conti chiede che il compenso resti quello del 2026.'),
        '2026-12-15 20:48', 0)], 'telefono di Marco')->json('risposte.0');

    $v = VersioneDelVerbale::query()->findOrFail($r['versione_id']);
    expect($r['esito'])->toBe('in_conflitto')->and($r['conflitto']['tipo'])->toBe('dopo_la_registrazione')
        ->and($r['messaggio'])->toContain('Il verbale n. 42 è registrato')
        ->and($v->attesa)->toBe('dopo_la_registrazione')->and($v->segnata_il->format('H:i'))->toBe('19:48')
        ->and(ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->exists())->toBeFalse();
});

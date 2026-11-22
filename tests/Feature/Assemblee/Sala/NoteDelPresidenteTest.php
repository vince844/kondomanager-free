<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../Verbale/VerbaleDiViaRoma12.php';

/**
 * Le note del presidente su un voto, che vanno nel verbale: **la nota sul voto** del punto (anche quando si vota senza che l'assemblea risulti costituita: il
 * programma avvisa e non blocca, decide il presidente) e **la nota su chi non ha votato** (le persone senza voto restano contate a parte, con i nomi). Il gesto
 * `nota` le scrive, le cambia e le toglie fino alla chiusura; prima della conferma non è una riga del registro, e la riga della conferma le porta nel perché;
 * dopo la conferma il cambio è una riga. La pagina e il verbale le leggono con il voto.
 *
 * Che cosa non copre: la finestra della nota a video (i vitest della sala).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La sala aperta in seconda con le persone date, e il punto 1 al voto. @return array{0: Beta51Scenario, 1: \App\Models\Assemblee\Assemblea, 2: \App\Models\Assemblee\SedutaDellAssemblea, 3: int} */
function ndpAlVoto($t, array $chi): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    if ($chi !== []) {
        SalaDiViaRoma12::entrano($t, $s, $a, $chi);
    }
    $v = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));

    return [$s, $a, $seduta, (int) $v['votazione_id']];
}

it('la nota sul voto e la nota su chi non ha votato si scrivono, si leggono nella pagina e nel verbale, e si tolgono con il testo vuoto', function () {
    [$s, $a, , $id] = ndpAlVoto($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto',
        'testo' => 'Si vota con i presenti: il presidente lo ha deciso.'], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'non_votato',
        'testo' => 'Condomino 30 e Condomino 31 erano usciti un momento.'], '2026-12-15 19:02'));

    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    $votazione = collect($sala['punti'])->firstWhere('numero', 1)['votazioni'][0];
    expect($votazione)->toMatchArray(['nota' => 'Si vota con i presenti: il presidente lo ha deciso.',
        'nota_non_votato' => 'Condomino 30 e Condomino 31 erano usciti un momento.']);
    $verbale = collect(app(LetturaDellaSala::class)->perIlVerbale($a)['punti'])->firstWhere('numero', 1)['votazioni'][0];
    expect($verbale)->toMatchArray(['nota' => 'Si vota con i presenti: il presidente lo ha deciso.',
        'nota_non_votato' => 'Condomino 30 e Condomino 31 erano usciti un momento.']);

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'non_votato', 'testo' => '   '], '2026-12-15 19:03'));
    expect(VotazioneDelPunto::query()->findOrFail($id)->nota_non_votato)->toBeNull()
        ->and(VotazioneDelPunto::query()->findOrFail($id)->nota)->toBe('Si vota con i presenti: il presidente lo ha deciso.');
});

it('senza numero legale si vota lo stesso: «Si vota» avvisa, la nota del presidente lo spiega, e la riga della conferma porta le due note nel perché', function () {
    [$s, $a, , $id] = ndpAlVoto($this, ['rossi', 'marco', 'rita']);
    $avviso = VotazioneDelPunto::query()->findOrFail($id);
    expect($avviso->intervenuti['numero'])->toBe(3);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto',
        'testo' => 'L\'assemblea non risultava costituita: il presidente ha fatto votare i presenti.'], '2026-12-15 19:02'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'non_votato', 'testo' => 'Hanno votato tutti.'],
        '2026-12-15 19:02'));
    $riga = ViaRoma12::ultimaRiga();
    expect(ViaRoma12::righe($riga))->toHaveCount(0);
    $base = (int) VotazioneDelPunto::query()->findOrFail($id)->versione;
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $id, 'esito' => 'respinta'], '2026-12-15 19:04', $base));

    $ultima = ViaRoma12::righe($riga)->last();
    expect($ultima->perche)->toContain('Nota del presidente: L\'assemblea non risultava costituita: il presidente ha fatto votare i presenti.')
        ->toContain('Su chi non ha votato: Hanno votato tutti.');
});

it('la nota non cambia la versione del voto: la conferma partita prima della nota non è in conflitto', function () {
    [$s, $a, , $id] = ndpAlVoto($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], '2026-12-15 19:01'));
    $base = (int) VotazioneDelPunto::query()->findOrFail($id)->versione;
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto', 'testo' => 'Una nota.'], '2026-12-15 19:02'));
    expect($r['versione'])->toBe($base);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $id, 'esito' => 'approvata'], '2026-12-15 19:03', $base));
    expect(VotazioneDelPunto::query()->findOrFail($id)->esito?->value)->toBe('approvata');
});

it('dopo la conferma il cambio della nota è una riga del registro; dopo la chiusura si cambia ancora, fino alla registrazione del verbale', function () {
    \Illuminate\Support\Facades\Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    [$s, $a, $seduta, $id] = ndpAlVoto($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], '2026-12-15 19:01'));
    $base = (int) VotazioneDelPunto::query()->findOrFail($id)->versione;
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $id, 'esito' => 'approvata'], '2026-12-15 19:02', $base));
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto', 'testo' => 'Approvata dopo una lunga discussione.'],
        '2026-12-15 19:10'));
    $righe = ViaRoma12::righe($riga);
    expect($righe)->toHaveCount(1)
        ->and($righe->first()->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026: nota del presidente sul voto del punto 1 scritta')
        ->and($righe->first()->perche)->toBe('Approvata dopo una lunga discussione.');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-15 20:40'));
    // Chiusa la sala, il voto e le sue note si correggono finché il verbale non è registrato: il verbale lo dice («I dati della sala sono cambiati»).
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto', 'testo' => 'Dopo la chiusura.'], '2026-12-15 20:41'));
    expect(VotazioneDelPunto::query()->findOrFail($id)->nota)->toBe('Dopo la chiusura.');

    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto', 'testo' => 'Dopo.'], '2026-12-15 20:42')])
        ->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('Il verbale n. 42 è registrato: il voto non si cambia più.');
});

it('la nota si rifiuta sulla votazione di un\'altra assemblea, su un campo che non c\'è e troppo lunga', function () {
    [$s, $a, , $id] = ndpAlVoto($this, ['rossi']);
    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $r = SalaDiViaRoma12::manda($this, $s, $b, [SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto', 'testo' => 'Altrove.'], '2026-12-15 19:01')])
        ->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('La votazione non è di questa assemblea.');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'esito', 'testo' => 'Che cosa?'], '2026-12-15 19:01')])
        ->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('La nota è sul voto o su chi non ha votato.');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('nota', ['votazione_id' => $id, 'su' => 'voto', 'testo' => str_repeat('a', 2001)],
        '2026-12-15 19:01')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('La nota sta in poche righe: al più 2.000 caratteri.');
});

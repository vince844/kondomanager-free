<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisione 662, rilievo C41; completa la 643): i cinque giorni dell'art. 66 c. 3 si dicono già al passo 1, mentre si scrivono le date, e la
 * convocazione non propone mai un giorno passato.
 *
 * - `TerminiDellaConvocazione::primiGiorniDaOggi()`: contando da oggi, il primo giorno della prima convocazione che ha i cinque giorni, per la PEC e la
 *   consegna a mano (oggi più cinque) e per la raccomandata (l'arrivo atteso, con i giorni di posta dello studio, più cinque).
 * - `fraseDeiCinqueGiorni()`: la frase con i due giorni e il lavoro urgente (art. 1135 c. 2); nessuna frase se la data ha il tempo. Non blocca.
 * - Le pagine ricevono i due giorni (`termini_della_prima`): «Prepara l'assemblea» e la pagina dell'assemblea.
 * - «Spedisco le raccomandate entro» propone oggi quando il giorno proposto è passato, con la frase e «Sposta le date» (la scheda).
 *
 * Oggi è giovedì 26/11/2026; i giorni di posta dello studio sono tre (venerdì 27, lunedì 30, martedì 01/12): la raccomandata di oggi arriva il 01/12.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('i primi giorni della prima convocazione che hanno i cinque giorni, contando da oggi', function () {
    $t = app(TerminiDellaConvocazione::class)->primiGiorniDaOggi();

    expect($t)->toBe(['oggi' => '2026-11-26', 'dal_a_mano' => '2026-12-01', 'dal_raccomandata' => '2026-12-06']);
});

it('la frase: la prima convocazione del 28/11 non ha i cinque giorni; i due giorni; il lavoro urgente', function () {
    $termini = app(TerminiDellaConvocazione::class);
    $t = $termini->primiGiorniDaOggi();

    expect(TerminiDellaConvocazione::fraseDeiCinqueGiorni('2026-11-28', $t))->toBe('Con la prima convocazione del 28/11 non c\'è il tempo dei cinque giorni '
        . '(art. 66 c. 3): per raccomandata può essere dal 06/12, per PEC o a mano dal 01/12. Si può convocare lo stesso, ma la delibera è annullabile da chi '
        . 'non è convocato in tempo. Per un lavoro urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).')
        // Con la PEC c'è il tempo, con la raccomandata no: la frase c'è lo stesso, con tutti e due i giorni.
        ->and(TerminiDellaConvocazione::fraseDeiCinqueGiorni('2026-12-03', $t))->toStartWith('Con la prima convocazione del 03/12 non c\'è il tempo')
        ->and(TerminiDellaConvocazione::fraseDeiCinqueGiorni('2026-12-06', $t))->toBeNull()
        ->and(TerminiDellaConvocazione::fraseDeiCinqueGiorni('2026-12-14', $t))->toBeNull();
});

it('«Prepara l\'assemblea» e la pagina dell\'assemblea ricevono i due giorni', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $attesi = ['oggi' => '2026-11-26', 'dal_a_mano' => '2026-12-01', 'dal_raccomandata' => '2026-12-06'];
    $this->actingAs($s->admin);

    $this->get(route('admin.gestionale.assemblee.create', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('termini_della_prima', $attesi));
    $this->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('termini_della_prima', $attesi));
});

it('«Spedisco le raccomandate entro» non propone mai un giorno passato: propone oggi, con la frase dell\'avviso', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);

    // In tempo: la proposta di sempre, giovedì 03/12, e nessuna frase.
    $entro = app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['entro'];
    expect($entro['raccomandate']['proposta'])->toBe('2026-12-03')->and($entro['vicina'])->toBeNull();

    // Giovedì 10/12: l'ultimo giorno (09/12) è passato, e la proposta di prima (03/12) pure.
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $entro = app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['entro'];
    expect($entro['raccomandate'])->toMatchArray(['proposta' => '2026-12-10', 'entro' => '2026-12-10', 'scelta_da_te' => false, 'passata' => null])
        ->and(app(TerminiDellaConvocazione::class)->propostaDaOggi($a))->toEqual(\Carbon\CarbonImmutable::parse('2026-12-10'))
        ->and($entro['vicina'])->toBe('Con la prima convocazione del 14/12 non c\'è il tempo dei cinque giorni (art. 66 c. 3): per raccomandata può essere dal '
            . '20/12, per PEC o a mano dal 15/12. Si può convocare lo stesso, ma la delibera è annullabile da chi non è convocato in tempo. Per un lavoro '
            . 'urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).');
});

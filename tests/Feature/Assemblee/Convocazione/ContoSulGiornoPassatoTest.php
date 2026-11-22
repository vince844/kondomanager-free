<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * «Come conto» sul giorno già passato, con l'ordinaria di Via Roma 12 (la prima convocazione lunedì 14/12, l'ultimo giorno mercoledì 09/12, la proposta
 * giovedì 03/12): il conto si fa su quando si può spedire davvero, mai sul giorno passato.
 *
 * - Oggi venerdì 04/12, la proposta del 03/12 è passata: il programma propone oggi (decisione 662), «Come conto venerdì 04/12», «Arriva tardi», con la
 *   frase dei cinque giorni; niente «Torna alla proposta».
 * - La data scelta martedì 01/12 e oggi lunedì 07/12: il conto su oggi, «Arriva tardi».
 * - Oggi giovedì 10/12, dopo l'ultimo giorno: ancora tardi, mai «Arriva in tempo».
 * - La riga del promemoria scelta oggi alle 10: «domattina, mercoledì 02/12», perché il pianificatore delle 8:15 è già passato.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function csgEntro($s, $a): array
{
    test()->actingAs($s->admin);

    return app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['entro'];
}

it('la proposta già passata: il conto su oggi, «Arriva tardi», la frase della proposta e niente «Torna alla proposta»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    ConvocazioneDiViaRoma12::oggi('2026-12-04 10:00');
    $e = csgEntro($s, $a);
    // Decisione 662: la proposta non è mai un giorno passato, è oggi; la frase dei cinque giorni e «Sposta le date» dicono il resto.
    expect($e['conto']['titolo'])->toBe('Come conto venerdì 04/12')
        ->and($e['conto']['esito']['in_tempo'])->toBeFalse()
        ->and($e['conto']['esito']['titolo'])->toBe('Arriva tardi')
        ->and($e['raccomandate'])->toMatchArray(['proposta' => '2026-12-04', 'entro' => '2026-12-04', 'passata' => null, 'torna' => null])
        ->and($e['vicina'])->toStartWith('Con la prima convocazione del 14/12 non c\'è il tempo dei cinque giorni');
});

it('la data scelta che poi passa, e il giorno dopo l\'ultimo: il conto su oggi, mai «Arriva in tempo»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-12-01'])
        ->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-07 10:00');
    $e = csgEntro($s, $a);
    expect($e['conto']['titolo'])->toBe('Come conto se spedisci oggi, lunedì 07/12')->and($e['conto']['esito']['in_tempo'])->toBeFalse()
        ->and($e['raccomandate']['passata'])->toBe('Il giorno è già passato: scegli oggi o un giorno dopo.')
        // Tornare alla proposta porta a oggi, mai a un giorno passato.
        ->and($e['raccomandate']['torna'])->toBe('Torna alla proposta (lun 07/12)');

    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $e = csgEntro($s, $a);
    expect($e['conto']['titolo'])->toBe('Come conto se spedisci oggi, giovedì 10/12')->and($e['conto']['esito']['in_tempo'])->toBeFalse();
});

it('scelta oggi alle 10, dopo le 8:15 del pianificatore: il promemoria di oggi parte «domattina»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    ConvocazioneDiViaRoma12::oggi('2026-12-01 10:00');
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-12-01'])
        ->assertSessionHasNoErrors();
    $e = csgEntro($s, $a);
    expect($e['segue'])->toContain('Il promemoria per email: domattina, mercoledì 02/12.');

    ConvocazioneDiViaRoma12::oggi('2026-12-01 07:30');
    expect(csgEntro($s, $a)['segue'])->toContain('Il promemoria per email: martedì 01/12.');
});

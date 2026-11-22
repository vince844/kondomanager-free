<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * Le correzioni della simulazione del 21/11/2026 nel passo «Convocazione»: con nessuno da convocare per PEC la scheda non dice «Prima imposta la casella
 * PEC dello studio» (M1: la card del passo dice già che non serve).
 *
 * Che cosa non copre: la finestra «Registra la spedizione» (`FinestreDellaConvocazione.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('M1 · senza la casella ma con nessuno da convocare per PEC la scheda non porta la frase «Prima imposta la casella PEC dello studio»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    DB::table('anagrafiche')->update(['mezzo_convocazione' => 'raccomandata']);
    $this->actingAs(ViaRoma12::utente([\App\Enums\Permission::MANAGE_GENERAL_SETTINGS]));
    $pec = app(StatoDellaConvocazione::class)->perLaPagina($a)['pec'];
    expect($pec['quante'])->toBe(0)->and($pec['attivo'])->toBeFalse()->and($pec['perche'])->toBeNull()->and($pec['dove'])->toBeNull();
});

it('M3 · la spedizione dopo l\'ultimo giorno utile: la frase con il giorno dell\'arrivo, l\'ultimo giorno utile e il primo giorno valido per la prima convocazione; in tempo niente', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s); // prima convocazione 14/12/2026 alle 8:00: l'ultimo giorno utile è il 9/12
    $t = app(\App\Services\Assemblee\Convocazione\TerminiDellaConvocazione::class);

    expect($t->spedizioneTardiva($a, 'a_mano', '2026-12-09'))->toBeNull();
    $a_mano = $t->spedizioneTardiva($a, 'a_mano', '2026-12-10');
    expect($a_mano['dal'])->toBe('2026-12-15')
        ->and($a_mano['frase'])->toBe('Spedita il 10/12: con la consegna a mano arriva dopo il 09/12, l\'ultimo giorno utile per la prima convocazione del 14/12 (art. 66 disp. att.). '
            . 'Per stare nei termini la prima convocazione può essere dal 15/12.');

    $arrivo = $t->arrivoAtteso('2026-12-08');
    expect($arrivo->gt(\Carbon\CarbonImmutable::parse('2026-12-09')))->toBeTrue();
    $racc = $t->spedizioneTardiva($a, 'raccomandata', '2026-12-08');
    expect($racc['dal'])->toBe($arrivo->addDays(5)->format('Y-m-d'))->and($racc['frase'])->toContain('Spedita il 08/12: con la raccomandata arriva dopo il 09/12');

    // La finestra e la scheda: «oggi» per canale; il giorno di oggi è il 10/12.
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $this->actingAs($s->admin);
    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a);
    expect($scheda['tardiva_oggi']['a_mano'])->toBe($a_mano['frase'])->and($scheda['tardiva_oggi']['raccomandata'])->toContain('Spedita il 10/12: con la raccomandata arriva dopo il 09/12')
        ->and($scheda['tardiva'])->toBeNull()->and($scheda['rotte']['date'])->toContain('scheda=date');
});

it('M3 · la spedizione registrata fuori dai termini compare nella scheda, e si registra lo stesso', function () {
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$carla->id], 'canale' => 'raccomandata', 'data' => '2026-12-10',
        'numeri' => [$carla->id => '61 2345 6789']])->assertSessionHasNoErrors();
    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a->refresh());
    expect($scheda['tardiva']['frase'])->toStartWith('Spedita il 10/12: con la raccomandata arriva dopo il 09/12')->and($scheda['tardiva']['dal'])->not->toBeNull();
});

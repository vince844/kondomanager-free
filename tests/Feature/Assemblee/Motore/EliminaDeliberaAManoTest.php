<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R17 del progetto della tappa 2; §13 rilievo 20): Marco registra in Corso Italia 3 la riunione di carta di Via Roma; la toglie e
 * la registra in Via Roma. Una delibera a mano senza collegamenti si elimina, una riga sola; con un collegamento il programma elenca le schede da cambiare
 * prima; una riunione a mano senza delibere si elimina. La delibera sul punto di un'assemblea tenuta su carta (registrata a mano anche lei)
 * si elimina lasciando il punto, e non si sposta; l'ultima delibera di una riunione a mano senza verbale porta con sé la riunione, se lo si chiede.
 *
 * Che cosa non copre: la pagina e la sua conferma.
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('senza collegamenti la delibera si elimina con il suo punto, una riga; poi la riunione vuota si elimina', function () {
    $s = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata di Via Roma', 'approvata', '41');
    $riunione = $d->assemblea;
    $prima = ViaRoma12::ultimaRiga();

    DeliberaAMano::elimina($d, $s->admin);
    expect(Delibera::query()->whereKey($d->id)->exists())->toBeFalse()
        ->and($riunione->punti()->count())->toBe(0)
        ->and(ViaRoma12::righe($prima)->pluck('riepilogo')->all())
            ->toBe(['Delibera registrata a mano eliminata: riunione del 22/10/2026, «Rifacimento della facciata di Via Roma», approvata, verbale n. 41']);

    DeliberaAMano::eliminaRiunione($riunione->fresh(), $s->admin);
    expect(Assemblea::query()->whereKey($riunione->id)->exists())->toBeFalse();
});

it('con un collegamento non si elimina, e la frase dice la scheda', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-22');
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    CollegamentiDelleDelibere::collega($s->piano('Facciata 2026 — rate'), 'delibera', $d, null, $s->admin);

    try {
        DeliberaAMano::elimina($d, $s->admin);
        expect(true)->toBeFalse();
    } catch (ValidationException $e) {
        expect($e->errors()['delibera'][0])->toBe('La delibera è usata: cambia prima Piano «Facciata 2026 — rate», poi eliminala.');
    }
    expect(Delibera::query()->whereKey($d->id)->exists())->toBeTrue();
});

it('la delibera sul punto di un\'assemblea tenuta fuori si elimina: il punto resta, il memo torna in attesa; non si sposta', function () {
    $s = ViaRoma12::nuovo($this);
    $portone = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [\App\Services\Assemblee\MemoriaDelCondominio::comeProposta($portone)]);
    $punto = $a->punti()->first();
    \App\Services\Assemblee\StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [$punto->id => ['esito' => 'approvata']], '2026-11-18');
    $d = $a->delibere()->first();
    expect($portone->fresh()->stato->value)->toBe('deliberato');

    try {
        DeliberaAMano::sposta($d, ['giorno' => '2026-11-18'], $s->admin);
        expect(true)->toBeFalse();
    } catch (ValidationException $e) {
        expect($e->errors()['delibera'][0])->toBe('La delibera è sul punto di un\'assemblea tenuta su carta: non si sposta. Se è sbagliata, eliminala e registrala di nuovo sul punto.');
    }

    DeliberaAMano::elimina($d, $s->admin);
    expect(Delibera::query()->whereKey($d->id)->exists())->toBeFalse()
        ->and($punto->fresh())->not->toBeNull()
        ->and($portone->fresh()->stato->value)->toBe('in_attesa')
        ->and(Assemblea::query()->whereKey($a->id)->exists())->toBeTrue();
    // L'esito giusto si registra di nuovo sul punto.
    $giusta = DeliberaAMano::registraSulPunto($punto->fresh(), ['esito' => 'respinta'], $s->admin);
    expect($giusta->esito->value)->toBe('respinta');
});

it('l\'ultima delibera di una riunione a mano senza verbale si elimina con la riunione, se lo si chiede', function () {
    $s = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $d = ViaRoma12::delibera($s, '2024-03-12', 'Rifacimento della facciata di Via Roma', 'approvata', '41');
    $riunione = $d->assemblea;
    $prima = ViaRoma12::ultimaRiga();

    expect(DeliberaAMano::restaVuota($d))->toBeTrue();
    DeliberaAMano::elimina($d, $s->admin, true);
    expect(Assemblea::query()->whereKey($riunione->id)->exists())->toBeFalse()
        ->and(ViaRoma12::righe($prima)->pluck('riepilogo')->all())->toHaveCount(2)
        ->and(ViaRoma12::righe($prima)->pluck('riepilogo')->all()[0])
            ->toBe('Delibera registrata a mano eliminata: riunione del 12/03/2024, «Rifacimento della facciata di Via Roma», approvata, verbale n. 41')
        ->and(ViaRoma12::righe($prima)->pluck('riepilogo')->all()[1])->toStartWith('Riunione del 12/03/2024')->toEndWith('eliminata: non aveva delibere');

    // Con due delibere, o con il verbale, la riunione resta.
    $uno = ViaRoma12::delibera($s, '2024-03-13', 'Il portone', 'approvata', '42');
    ViaRoma12::delibera($s, '2024-03-13', 'Il cancello', 'approvata', '42');
    expect(DeliberaAMano::restaVuota($uno))->toBeFalse();
    DeliberaAMano::elimina($uno, $s->admin, true);
    expect(Assemblea::query()->whereKey($uno->assemblea_id)->exists())->toBeTrue();
});

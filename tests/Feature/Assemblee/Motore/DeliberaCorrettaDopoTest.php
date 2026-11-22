<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R2 del progetto della tappa 2, decisione 492 (8); §13 rilievo 6): la riunione a mano del 30/11/2026 era del 28/11/2026, con due
 * delibere usate dal piano «Facciata 2026 — rate»: «Correggi la data» corregge la riunione con le sue delibere, una riga sola con le schede nel perché; il
 * piano non cambia; la riunione dice chi la usa e con quale data. Una delibera nella riunione sbagliata si sposta: prende il giorno dell'altra.
 *
 * Che cosa non copre: la pagina della riunione (P6) e il suo «Apri».
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('«Correggi la data»: la riunione e le sue delibere al giorno giusto; il piano resta, e i posti lo dicono', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-30');
    $piano = $s->piano('Facciata 2026 — rate');
    $uno = ViaRoma12::delibera($s, '2026-10-30', 'Rifacimento della facciata', 'approvata', '7');
    $due = ViaRoma12::delibera($s, '2026-10-30', 'Scelta dell\'impresa', 'approvata', '7');
    CollegamentiDelleDelibere::collega($piano, 'delibera', $uno, null, $s->admin);
    $prima = ViaRoma12::ultimaRiga();

    $esito = DeliberaAMano::correggiData($uno->assemblea, '2026-10-28', $s->admin);

    $righe = ViaRoma12::righe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->campi[0])->toMatchArray(['etichetta' => 'Il giorno', 'prima' => '30/10/2026', 'dopo' => '28/10/2026'])
        ->and($righe[0]->perche)->toContain('Piano «Facciata 2026 — rate»')
        ->and($uno->fresh()->deliberata_il->format('Y-m-d'))->toBe('2026-10-28')
        ->and($due->fresh()->deliberata_il->format('Y-m-d'))->toBe('2026-10-28')
        ->and($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-30')
        ->and($esito['posti'][0])->toMatchArray(['oggetto_tipo' => 'piano_rate', 'data' => '2026-10-30'])
        ->and($esito['frase'])->toBe('La usano 1 scheda: Piano «Facciata 2026 — rate» dice 30/10/2026');
});

it('«Sposta in un\'altra riunione»: la delibera e il suo punto passano nell\'altra e prendono il suo giorno; la nuova nasce se non c\'è', function () {
    $s = ViaRoma12::nuovo($this);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Preventivo 2027', 'approvata', '41');
    ViaRoma12::delibera($s, '2026-10-22', 'Rendiconto 2025', 'approvata', '41');
    $prima = ViaRoma12::ultimaRiga();
    $da = \App\Models\Assemblee\Assemblea::query()->findOrFail($d->assemblea_id);

    $spostata = DeliberaAMano::sposta($d, ['giorno' => '2026-10-23', 'numero_verbale' => '42'], $s->admin);

    expect($spostata->assemblea_id)->not->toBe($da->id)
        ->and($spostata->deliberata_il->format('Y-m-d'))->toBe('2026-10-23')
        ->and($spostata->punto->assemblea_id)->toBe($spostata->assemblea_id)
        ->and($da->punti()->pluck('numero')->all())->toBe([1])
        ->and(ViaRoma12::righe($prima)->pluck('riepilogo')->all())->toBe(['Delibera spostata nella riunione del 23/10/2026, verbale n. 42']);
});

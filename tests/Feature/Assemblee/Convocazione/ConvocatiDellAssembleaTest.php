<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\MezzoDiConvocazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §4.1 e §4.2 del progetto della tappa 2; decisione 566): chi è convocato a un giorno. Una persona è un convocato anche con più
 * unità; ogni comproprietario ha il suo avviso; con l'usufrutto l'avviso va a tutti e due appena c'è un punto straordinario, al solo usufruttuario con
 * soli punti ordinari; il conduttore solo per i punti dove vota; il mezzo proposto dai dati, l'email semplice che non vale, la PEC non comunicata, la
 * destinazione d'uso con i soli raccomandata e PEC.
 *
 * Che cosa non copre: il venduto dopo l'invio (è di `NuovoTitolareNonConvocatoTest`); la pagina.
 */

beforeEach(fn () => ContestoDelRegistro::azzera());
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('una persona con più unità è un convocato solo; ogni comproprietario ha il suo avviso, con la quota', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $c = collect(app(ConvocatiDellAssemblea::class)->alGiorno($a, '2026-11-26'))->keyBy('nome');

    expect($c['Rossi Mario']['unita_parole'])->toBe('Int. 3, box 21')
        ->and($c['Rossi Mario']['quota'])->toBeNull()
        ->and($c['Russo Anna']['quota'])->toBe('½')
        ->and($c['Russo Marco']['unita_parole'])->toBe('Int. 5')
        ->and($c->has('Bianchi Anna'))->toBeTrue();
});

it('con un punto straordinario l\'avviso va all\'usufruttuaria e al nudo proprietario; con soli punti ordinari alla sola usufruttuaria', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $lavori = collect(app(ConvocatiDellAssemblea::class)->alGiorno(ConvocazioneDiViaRoma12::ordinaria($s), '2026-11-26'))->keyBy('nome');
    expect($lavori->has('Neri Carla'))->toBeTrue()->and($lavori->has('Neri Paolo'))->toBeTrue()
        ->and($lavori['Neri Paolo']['ruolo'])->toBe('nudo proprietario')
        ->and($lavori['Neri Carla']['ruolo'])->toBe('usufruttuario');

    $ordinarie = collect(app(ConvocatiDellAssemblea::class)->alGiorno(ConvocazioneDiViaRoma12::soloOrdinaria($s), '2026-11-26'))->keyBy('nome');
    expect($ordinarie->has('Neri Carla'))->toBeTrue()->and($ordinarie->has('Neri Paolo'))->toBeFalse();
});

it('il conduttore è convocato per il solo punto dove vota; senza un punto così non è convocato', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $c = collect(app(ConvocatiDellAssemblea::class)->alGiorno(ConvocazioneDiViaRoma12::ordinaria($s), '2026-11-26'))->keyBy('nome');
    expect($c['Ferri Luca']['punti'])->toBe([5])
        ->and($c['Ferri Luca']['ruolo'])->toBe('conduttore, per il punto 5')
        ->and($c['Colombo Rita']['punti'])->toBeNull();

    $senza = collect(app(ConvocatiDellAssemblea::class)->alGiorno(ConvocazioneDiViaRoma12::soloOrdinaria($s), '2026-11-26'))->keyBy('nome');
    expect($senza->has('Ferri Luca'))->toBeFalse();
});

it('la frase dei convocati: i condòmini, l\'usufruttuario e il conduttore, i due comproprietari', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $c = app(ConvocatiDellAssemblea::class)->alGiorno(ConvocazioneDiViaRoma12::ordinaria($s), '2026-11-26');

    expect(ConvocatiDellAssemblea::descrizione($c))
        ->toBe('7 condòmini, più l\'usufruttuario e il conduttore che il punto 5 chiama. I due comproprietari dell\'Int. 5 ricevono un avviso ciascuno.');
});

it('il mezzo: la PEC se c\'è, la raccomandata con l\'indirizzo, il mezzo scelto nella scheda; l\'email semplice non vale', function () {
    expect(MezzoDiConvocazione::per(['pec' => 'mario.rossi@pec.it', 'indirizzo' => 'Via Roma 12', 'pec_comunicata_il' => '2026-03-02']))
        ->toBe(['mezzo' => 'pec', 'proposto' => true, 'valido' => true, 'perche' => null, 'pec_non_comunicata' => false])
        ->and(MezzoDiConvocazione::per(['indirizzo' => 'Via Verdi 4'])['mezzo'])->toBe('raccomandata')
        ->and(MezzoDiConvocazione::per(['indirizzo' => 'Via Roma 12', 'mezzo_convocazione' => 'a_mano']))
        ->toMatchArray(['mezzo' => 'a_mano', 'proposto' => false, 'valido' => true])
        ->and(MezzoDiConvocazione::per(['email' => 'anna.bianchi@example.com', 'indirizzo' => '']))
        ->toMatchArray(['mezzo' => null, 'valido' => false, 'perche' => 'ha solo l\'email: aggiungi la PEC o l\'indirizzo'])
        ->and(MezzoDiConvocazione::per(['pec' => 'paolo.neri@pec.it'])['pec_non_comunicata'])->toBeTrue()
        ->and(MezzoDiConvocazione::per(['mezzo_convocazione' => 'pec', 'indirizzo' => 'Via Roma 12']))
        ->toMatchArray(['valido' => false, 'perche' => MezzoDiConvocazione::PEC_ASSENTE]);
});

it('con un punto sulla destinazione d\'uso «A mano» e «Fax» non valgono (art. 1117-ter)', function () {
    expect(MezzoDiConvocazione::per(['indirizzo' => 'Via Roma 12', 'mezzo_convocazione' => 'a_mano'], true))
        ->toMatchArray(['mezzo' => 'a_mano', 'valido' => false, 'perche' => MezzoDiConvocazione::SOLO_RACCOMANDATA_E_PEC])
        ->and(MezzoDiConvocazione::per(['indirizzo' => 'Via Roma 12'], true)['valido'])->toBeTrue();
});

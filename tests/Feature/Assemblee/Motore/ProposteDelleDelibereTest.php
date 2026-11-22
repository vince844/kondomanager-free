<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\MemoriaDelCondominio;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R6 e R7 del progetto della tappa 2; decisioni 500 e 502): «Scegli la delibera» propone, non sceglie. Un caso per livello (il punto
 * nato dalla scheda, il punto che parla di ciò che la contiene, la stessa materia, nessun legame), la parità (tutte le proposte del livello più alto in testa,
 * con la conseguenza del piano del saldo), che cosa si sceglie (la respinta spenta; la senza voto solo per il riferimento), la ricerca, il memo e la raccolta.
 *
 * Che cosa non copre: il componente della pagina e la sua rotta; la velocità con molte riunioni (il calcolo è in PHP sulle delibere del condominio).
 */

it('livello 1: il punto nato da questa fattura', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $giusta = ViaRoma12::delibera($s, '2026-10-22', 'Ratifica della colonna', 'approvata', '41', [], ['oggetto_tipo' => 'fattura_passiva', 'oggetto_id' => $fattura->id]);
    ViaRoma12::delibera($s, '2026-10-23', 'Un\'altra ratifica', 'approvata', '42', ['materia' => 'ratifica']);

    $esito = DelibereDelCondominio::scegli($s->c, 'fattura_passiva', $fattura->id, 'ratifica', null);
    expect(array_column($esito['proposte'], 'id'))->toBe([$giusta->id])
        ->and($esito['proposte'][0]['livello'])->toBe(1)
        ->and($esito['proposte'][0]['perche_proposta'])->toBe('Proposta perché il punto 1 dell\'assemblea del 22/10 è nato da questa fattura')
        ->and($esito['gruppi'])->toHaveCount(2);
});

it('livello 2: il punto che parla della gestione del piano; la parità mette in testa tutte e due, con la conseguenza', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2027 — saldo', 'Facciata 2026', ['Facciata' => 1450000], '2027-03-10');
    $piano = $s->piano('Facciata 2027 — saldo');
    $gestione = $s->gestioni['Facciata 2026']['g'];
    $opera = ViaRoma12::delibera($s, '2026-10-22', 'Facciata 2026', 'approvata', '41', [], ['parla_di' => [['gestione', $gestione->id]]]);
    $saldo = ViaRoma12::delibera($s, '2026-11-10', 'Saldo della facciata', 'approvata', '43', [], ['parla_di' => [['gestione', $gestione->id]]]);

    $esito = DelibereDelCondominio::scegli($s->c, 'piano_rate', $piano->id, 'delibera', null);
    expect(array_column($esito['proposte'], 'id'))->toBe([$saldo->id, $opera->id])
        ->and($esito['proposte'][0]['perche_proposta'])->toBe('Proposta perché parla della gestione «Facciata 2026»')
        ->and($esito['proposte'][1]['conseguenza'])->toBe('Con questa delibera le rate del piano passano a chi era titolare il 22/10/2026');
});

it('livello 3: la stessa materia, dalla più recente; livello 4: nessuna proposta, tutte per assemblea con la ricerca', function () {
    $s = ViaRoma12::nuovo($this);
    $dispensa = \App\Models\Gestionale\DispensaSeiMesi::create(['condominio_id' => $s->c->id, 'data_delibera' => '2026-10-22', 'tutte_le_persone' => true]);
    $materia = ViaRoma12::delibera($s, '2026-10-22', 'Dispensa dei sei mesi', 'approvata', '41', ['materia' => 'dispensa']);
    ViaRoma12::delibera($s, '2026-09-01', 'Il portone', 'approvata', '40');

    $esito = DelibereDelCondominio::scegli($s->c, 'dispensa_sei_mesi', $dispensa->id, 'dispensa', null);
    expect(array_column($esito['proposte'], 'id'))->toBe([$materia->id])->and($esito['proposte'][0]['livello'])->toBe(3);

    $nessuna = DelibereDelCondominio::scegli($s->c, null, null, 'delibera', 'portone');
    expect($nessuna['proposte'])->toBe([])->and($nessuna['gruppi'])->toHaveCount(1)
        ->and($nessuna['gruppi'][0]['delibere'][0]['testo'])->toBe('Il portone');
});

it('R7: la respinta e la rinviata spente con il perché; la senza voto si sceglie solo per il riferimento all\'assemblea', function () {
    $s = ViaRoma12::nuovo($this);
    $respinta = ViaRoma12::delibera($s, '2026-10-22', 'La facciata rossa', 'respinta', '41');
    $senzaVoto = ViaRoma12::delibera($s, '2026-10-22', 'Comunicazione dei lavori urgenti', 'senza_voto', '41');

    $riga = fn (array $esito, int $id) => collect($esito['gruppi'])->flatMap(fn ($g) => $g['delibere'])->firstWhere('id', $id);
    $perDelibera = DelibereDelCondominio::scegli($s->c, null, null, 'delibera', null);
    $perRiferimento = DelibereDelCondominio::scegli($s->c, null, null, 'riferimento', null);
    expect($riga($perDelibera, $respinta->id))->toMatchArray(['si_sceglie' => false, 'perche' => 'respinta'])
        ->and($riga($perDelibera, $senzaVoto->id))->toMatchArray(['si_sceglie' => false, 'perche' => 'senza voto'])
        ->and($riga($perRiferimento, $senzaVoto->id)['si_sceglie'])->toBeTrue();
});

it('la scheda con un memo dice «Già nella memoria dal …», e la raccolta il numero delle date', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);
    \App\Models\Gestionale\DispensaSeiMesi::create(['condominio_id' => $s->c->id, 'data_delibera' => '2025-04-10', 'numero_verbale' => '2/2025', 'tutte_le_persone' => true]);

    $esito = DelibereDelCondominio::scegli($s->c, 'fattura_passiva', $fattura->id, 'ratifica', null);
    expect($esito['memo']['frase'])->toStartWith('Già nella memoria dal ')
        ->and($esito['raccolta'])->toBe(['date' => 1, 'frase' => 'In Via Roma 12 c\'è 1 data scritta a mano: raccoglile']);
});

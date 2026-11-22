<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\MaggioranzePerMateria;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §3 P2 e §13 rilievo 3 del progetto della tappa 2; legge §3 e §9): la maggioranza proposta per materia, e **nessuna** per le
 * tabelle e i criteri di riparto, con l'avviso della nullità (Cass. SU 9839/2021); l'amministrazione di serie straordinaria per lavori, innovazioni, tabelle,
 * riparto, destinazione d'uso e per ogni maggioranza diversa da «Ordinaria» (§13, rilievo 1).
 * Il regolamento con la metà del valore (art. 1138 c. 3, che rimanda all'art. 1136 c. 2); sul riscaldamento il voto del conduttore al posto del proprietario
 * (art. 10 L. 392/1978): tutti e due riscontrati sul testo.
 *
 * Che cosa non copre: il menu della pagina e le parole dei badge (sono della pagina); le soglie con i numeri della sala (tappa 3).
 */

it('propone la maggioranza della materia', function (string $materia, ?string $proposta) {
    expect(MaggioranzePerMateria::proposta($materia))->toBe($proposta);
})->with([
    ['nomina', 'meta_del_valore'], ['liti', 'meta_del_valore'], ['lavori', 'meta_del_valore'], ['innovazioni', 'due_terzi'],
    ['destinazione_uso', 'quattro_quinti'], ['varie', 'senza_voto'], ['comunicazione', 'senza_voto'], ['comunicazione_urgenza', 'senza_voto'],
    ['tabelle', null], ['riparto', null], ['rendiconto', 'ordinaria'], ['preventivo', 'ordinaria'], ['ratifica', 'ordinaria'], ['altro', 'ordinaria'],
]);

it('per le tabelle e il riparto avvisa della nullità, per i lavori della notevole entità', function () {
    expect(MaggioranzePerMateria::avviso('tabelle'))->toBe(MaggioranzePerMateria::AVVISO_RIPARTO)
        ->and(MaggioranzePerMateria::avviso('riparto'))->toContain('Cass. SU 9839/2021')
        ->and(MaggioranzePerMateria::avviso('lavori'))->toContain('art. 1136 c. 4')
        ->and(MaggioranzePerMateria::avviso('rendiconto'))->toBeNull();
});

it('l\'amministrazione è straordinaria per le materie dell\'art. 67 c. 7 e per ogni maggioranza diversa da Ordinaria', function () {
    expect(MaggioranzePerMateria::amministrazione('lavori', 'ordinaria'))->toBe('straordinaria')
        ->and(MaggioranzePerMateria::amministrazione('tabelle', null))->toBe('straordinaria')
        ->and(MaggioranzePerMateria::amministrazione('altro', 'due_terzi'))->toBe('straordinaria')
        ->and(MaggioranzePerMateria::amministrazione('rendiconto', 'ordinaria'))->toBe('ordinaria')
        ->and(MaggioranzePerMateria::amministrazione('varie', 'senza_voto'))->toBe('ordinaria');
});

it('il regolamento è proposto con la metà del valore (art. 1138 c. 3 c.c., riscontrato sul testo), quindi di amministrazione straordinaria', function () {
    expect(MaggioranzePerMateria::proposta('regolamento'))->toBe('meta_del_valore')
        ->and(MaggioranzePerMateria::amministrazione('regolamento', 'meta_del_valore'))->toBe('straordinaria');
});

it('sul riscaldamento e il condizionamento il conduttore vota al posto del proprietario (art. 10 L. 392/1978); sulle altre materie nessuna proposta', function () {
    expect(MaggioranzePerMateria::conduttore('riscaldamento'))->toBe('vota')
        ->and(MaggioranzePerMateria::conduttore('lavori'))->toBeNull()
        ->and(MaggioranzePerMateria::conduttore('altro'))->toBeNull();
});

it('il punto del riscaldamento nasce con il voto del conduttore, se chi lo aggiunge non dice altro; la scelta dell\'amministratore resta', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $proposto = \App\Services\Assemblee\OrdineDelGiorno::aggiungi($a, ['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento'], $s->admin);
    $scelto = \App\Services\Assemblee\OrdineDelGiorno::aggiungi($a, ['testo' => 'Riscaldamento: la caldaia', 'materia' => 'riscaldamento', 'conduttore' => null], $s->admin);
    $scelte = \App\Http\Controllers\Assemblee\PerLePagine::scelte($s->c);

    expect($proposto->conduttore?->value)->toBe('vota')->and($scelto->conduttore)->toBeNull()
        ->and($scelte['maggioranze_proposte']['riscaldamento']['conduttore'])->toBe('vota')
        ->and($scelte['maggioranze_proposte']['regolamento']['maggioranza'])->toBe('meta_del_valore');
});

it('la nomina, la conferma e la revoca dell\'amministratore sono di amministrazione ordinaria anche con la metà del valore: vota l\'usufruttuario', function () {
    // Int. 7: usufruttuaria Anna Verdi, nuda proprietaria la figlia Laura. Sulla nomina vota Anna (art. 1135 n. 1 c.c., art. 67 c. 6 disp. att.); il
    // presidente può cambiarlo per il singolo punto.
    expect(MaggioranzePerMateria::amministrazione('nomina', 'meta_del_valore'))->toBe('ordinaria')
        ->and(MaggioranzePerMateria::amministrazione('nomina', null))->toBe('ordinaria')
        ->and(MaggioranzePerMateria::amministrazione('liti', 'meta_del_valore'))->toBe('straordinaria');
});

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\MateriaPunto;
use App\Services\Assemblee\Convocazione\AvvisoInBacheca;
use App\Services\Assemblee\MaggioranzePerMateria;
use App\Services\Assemblee\Sala\ContiDellaSala;
use App\Services\RegistroModifiche\Schede\PuntoNelRegistro;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisione 640 (1)): le materie con la maggioranza fissata dalla legge, sui testi letti su Normattiva il 21/11/2026. L'art. 1136 c. 4
 * vuole «sempre» la metà del valore, in prima e in seconda, per le innovazioni agevolate (art. 1120 c. 2), la videosorveglianza (art. 1122-ter), la tutela
 * delle destinazioni d'uso (art. 1117-quater) e i progetti del territorio (art. 1135 c. 3); la stessa maggioranza per il sito internet (art. 71-ter disp.
 * att.) e per la rettifica delle tabelle per errore o per variazione oltre un quinto (art. 69 disp. att.); l'art. 1136 c. 5 i due terzi per le modalità
 * degli impianti dei singoli (art. 1122-bis c. 3).
 *
 * I due casi di Via Roma 12 (seconda convocazione del 15/12/2026, 26 condòmini, 12 intervenuti, 9 favorevoli) diventano giusti: le telecamere nell'androne
 * con 420,00 millesimi erano «Approvata» con la materia «Altro» (basta un terzo) e sono respinte (ne servono 500,00); l'ascensore per le barriere
 * architettoniche con 560,00 era «Respinta» con «Innovazioni» (due terzi) ed è approvato (basta la metà).
 *
 * Poi gli avvisi accanto al menu, l'amministrazione di serie, la riga neutra della bacheca, la parola nel registro delle modifiche e lo specchio TypeScript.
 *
 * Che cosa non copre: il seguito delle delibere (`MaterieDellaLeggeNelSeguitoTest`); la finestra del punto (vitest e a video).
 */
const MDL_NUOVE = ['innovazioni_agevolate', 'videosorveglianza', 'tutela_destinazione_uso', 'sito_internet', 'rettifica_tabelle', 'progetti_territorio',
    'impianti_singoli'];

it('propone la maggioranza che la legge fissa', function (string $materia, ?string $proposta) {
    expect(MaggioranzePerMateria::proposta($materia))->toBe($proposta);
})->with([
    ['innovazioni_agevolate', 'meta_del_valore'], ['videosorveglianza', 'meta_del_valore'], ['tutela_destinazione_uso', 'meta_del_valore'],
    ['sito_internet', 'meta_del_valore'], ['rettifica_tabelle', 'meta_del_valore'], ['progetti_territorio', 'meta_del_valore'], ['impianti_singoli', 'due_terzi'],
    // Le materie di prima non cambiano.
    ['innovazioni', 'due_terzi'], ['destinazione_uso', 'quattro_quinti'], ['tabelle', null], ['regolamento', 'meta_del_valore'],
]);

it('i due casi di oggi: le telecamere con 420,00 in seconda sono respinte, l\'ascensore per le barriere con 560,00 è approvato', function (string $materia,
    int $valore, string $esito) {
    $proposta = MaggioranzePerMateria::proposta($materia);

    expect([$proposta, ContiDellaSala::esito($proposta, 'seconda', 26, 100000000, 12, 9, $valore)])->toBe(['meta_del_valore', $esito]);
})->with([
    'le telecamere nell\'androne' => ['videosorveglianza', 42000000, 'respinta'],
    'l\'ascensore per le barriere architettoniche' => ['innovazioni_agevolate', 56000000, 'approvata'],
]);

it('nel punto la materia porta la maggioranza e l\'amministrazione, e la sala ne fa l\'esito', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [
        ['testo' => 'Telecamere nell\'androne', 'materia' => 'videosorveglianza'],
        ['testo' => 'Ascensore per le barriere architettoniche', 'materia' => 'innovazioni_agevolate'],
        ['testo' => 'Pannelli solari dell\'interno 5 sul lastrico: le cautele', 'materia' => 'impianti_singoli'],
    ]);
    $punti = $a->punti()->orderBy('numero')->get();
    $esito = fn (int $i, int $valore) => ContiDellaSala::esito($punti[$i]->maggioranza?->value, 'seconda', 26, 100000000, 12, 9, $valore);

    expect($punti->map(fn ($p) => [$p->maggioranza?->value, $p->amministrazione?->value])->all())->toBe([
        ['meta_del_valore', 'straordinaria'], ['meta_del_valore', 'straordinaria'], ['due_terzi', 'straordinaria'],
    ])->and([$esito(0, 42000000), $esito(1, 56000000)])->toBe(['respinta', 'approvata']);
});

it('gli avvisi accanto al menu: che cosa comprendono le agevolate, le agevolate e i divieti sotto «Innovazioni», i due casi della rettifica', function () {
    $agevolate = MaggioranzePerMateria::avviso('innovazioni_agevolate');
    $innovazioni = MaggioranzePerMateria::avviso('innovazioni');
    $rettifica = MaggioranzePerMateria::avviso('rettifica_tabelle');

    expect($agevolate)->toContain('sicurezza e la salubrità')->toContain('barriere architettoniche')->toContain('consumo energetico')->toContain('parcheggi')
        ->toContain('fonti rinnovabili')->toContain('impianti centralizzati radiotelevisivi')->toContain('art. 1120 c. 2')
        ->toContain('in prima e in seconda convocazione')->toContain('mai voluttuarie')->toContain('L. 13/1989 art. 2')
        ->and($innovazioni)->toContain('scegli «Innovazioni agevolate»')->toContain('art. 1120 c. 4')->toContain('stabilità o la sicurezza')
        ->toContain('decoro')
        ->and($rettifica)->toContain('errore')->toContain('più di un quinto')->toContain('unanimità')->toContain('art. 69 disp. att.')
        // La nullità di Cass. SU 9839/2021 resta sulle tabelle e sul riparto, non sulla rettifica.
        ->not->toContain('9839')
        ->and(MaggioranzePerMateria::avviso('tabelle'))->toBe(MaggioranzePerMateria::AVVISO_RIPARTO)
        ->and(MaggioranzePerMateria::avviso('riparto'))->toBe(MaggioranzePerMateria::AVVISO_RIPARTO)
        ->and(MaggioranzePerMateria::avviso('tutela_destinazione_uso'))->toContain('art. 1117-ter')->toContain('quattro quinti');
});

it('l\'amministrazione di serie, anche se la maggioranza diventa «Ordinaria»: come la materia di prima più vicina', function () {
    $amministrazione = array_combine(MDL_NUOVE, array_map(fn ($m) => MaggioranzePerMateria::amministrazione($m, 'ordinaria'), MDL_NUOVE));

    expect($amministrazione)->toBe([
        // Come le innovazioni.
        'innovazioni_agevolate' => 'straordinaria',
        // Come i lavori.
        'videosorveglianza' => 'straordinaria',
        // Come le liti: far cessare un uso, anche in giudizio.
        'tutela_destinazione_uso' => 'ordinaria',
        // Come il regolamento.
        'sito_internet' => 'ordinaria',
        // Come le tabelle.
        'rettifica_tabelle' => 'straordinaria',
        // Come i lavori.
        'progetti_territorio' => 'straordinaria',
        // Come le innovazioni (art. 1136 c. 5 le mette insieme).
        'impianti_singoli' => 'straordinaria',
    ]);
});

it('ogni materia ha la sua riga neutra per la bacheca e la sua parola nel registro delle modifiche', function () {
    $neutre = (new ReflectionClassConstant(AvvisoInBacheca::class, 'NEUTRE'))->getValue();
    $registro = PuntoNelRegistro::FORMATI['materia'][1];

    expect(array_keys($neutre))->toEqualCanonicalizing(MateriaPunto::valori())
        ->and(array_keys($neutre))->toContain(...MDL_NUOVE)
        ->and(array_count_values($neutre)['Un altro argomento'] ?? 0)->toBe(1)
        ->and($registro)->toBe(MateriaPunto::parole());
});

it('le parole del menu dicono l\'articolo', function () {
    expect(MateriaPunto::parolaDi('innovazioni_agevolate'))->toBe('Innovazioni agevolate (art. 1120 c. 2)')
        ->and(MateriaPunto::parolaDi('videosorveglianza'))->toBe('Videosorveglianza sulle parti comuni (art. 1122-ter)')
        ->and(MateriaPunto::parolaDi('tutela_destinazione_uso'))->toBe('Tutela della destinazione d\'uso (art. 1117-quater)')
        ->and(MateriaPunto::parolaDi('sito_internet'))->toBe('Sito internet del condominio (art. 71-ter disp. att.)')
        ->and(MateriaPunto::parolaDi('rettifica_tabelle'))->toBe('Rettifica delle tabelle (art. 69 disp. att.)')
        ->and(MateriaPunto::parolaDi('progetti_territorio'))->toBe('Progetti del territorio (art. 1135 c. 3)')
        ->and(MateriaPunto::parolaDi('impianti_singoli'))->toBe('Modalità per gli impianti dei singoli (art. 1122-bis c. 3)');
});

it('lo specchio TypeScript ha le stesse materie dell\'enum', function () {
    $tipi = (string) file_get_contents(base_path('resources/js/types/assemblee.ts'));
    preg_match('/export type MateriaPunto =([^;]+);/', $tipi, $m);
    preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $valori);

    expect($valori[1])->toEqualCanonicalizing(MateriaPunto::valori())
        ->and($valori[1])->toContain(...MDL_NUOVE);
});

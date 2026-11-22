<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Services\Assemblee\RaccoltaDelleDate;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R9 del progetto della tappa 2; decisione 492 (9); §13 rilievi 10 e 27): la raccolta delle date già scritte. In Via Roma piani e
 * dispense con date diverse diventano riunioni per giorno e numero del verbale; l'anteprima non scrive niente e segna la data uguale al giorno
 * dell'approvazione nel programma; la conferma crea solo le righe spuntate, con il collegamento e una riga per scheda più il riepilogo; rilanciata, non
 * propone ciò che è già raccolto.
 *
 * Che cosa non copre: la pagina dell'anteprima; il verbale copiato da una dispensa con il file (la strada dell'archivio dei documenti è quella delle versioni).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('l\'anteprima raggruppa per giorno e verbale, segna la sospetta e non scrive niente; la conferma raccoglie le spuntate', function () {
    $s = ViaRoma12::conFacciata($this)
        ->pianoStraordinario('Facciata 2024 — rate', 'Facciata 2026', ['Facciata' => 100000], '2024-03-12')
        ->pianoStraordinario('Ascensore — rate', 'Facciata 2026', ['Facciata' => 100000], '2024-03-12')
        ->pianoStraordinario('Tetto — rate', 'Facciata 2026', ['Facciata' => 100000], '2025-05-20');
    DB::table('piani_rate')->where('nome', 'Facciata 2024 — rate')->update(['numero_verbale' => '3/2024']);
    DB::table('piani_rate')->where('nome', 'Ascensore — rate')->update(['numero_verbale' => '3 / 2024']);
    DB::table('piani_rate')->where('nome', 'Tetto — rate')->update(['approvato_il' => '2025-05-20 11:00:00']);
    $dispensa = \App\Models\Gestionale\DispensaSeiMesi::create(['condominio_id' => $s->c->id, 'data_delibera' => '2025-05-20', 'tutte_le_persone' => true]);
    $righePrima = DB::table('registro_modifiche')->count();

    $anteprima = RaccoltaDelleDate::anteprima($s->c);
    expect(DB::table('registro_modifiche')->count())->toBe($righePrima)
        ->and(array_column($anteprima, 'giorno'))->toBe(['2024-03-12', '2025-05-20'])
        ->and(count($anteprima[0]['schede']))->toBe(2)
        ->and(collect($anteprima[1]['schede'])->firstWhere('oggetto_tipo', 'piano_rate')['sospetta'])->toBe(RaccoltaDelleDate::FRASE_SOSPETTA)
        ->and(collect($anteprima[1]['schede'])->firstWhere('oggetto_tipo', 'piano_rate')['spuntata'])->toBeFalse();

    $prima = ViaRoma12::ultimaRiga();
    $esito = RaccoltaDelleDate::conferma($s->c, [['piano_rate', $s->piano('Facciata 2024 — rate')->id], ['piano_rate', $s->piano('Ascensore — rate')->id],
        ['dispensa_sei_mesi', $dispensa->id]], $s->admin);

    $righe = ViaRoma12::righe($prima);
    expect($esito)->toBe(['riunioni' => 2, 'delibere' => 3, 'schede' => 3])
        ->and(Assemblea::query()->where('origine', 'a_mano')->count())->toBe(2)
        ->and(CollegamentoAllaDelibera::query()->count())->toBe(3)
        ->and($righe->where('oggetto_tipo', 'condominio')->pluck('riepilogo')->all())
            ->toBe(['Date scritte a mano raccolte in delibere: 2 riunioni, 3 delibere, 3 schede collegate'])
        ->and($righe->whereIn('oggetto_tipo', ['piano_rate', 'dispensa_sei_mesi'])->count())->toBe(3)
        ->and(array_column(RaccoltaDelleDate::anteprima($s->c), 'giorno'))->toBe(['2025-05-20'])
        ->and(count(RaccoltaDelleDate::anteprima($s->c)[0]['schede']))->toBe(1);
});

it('una data scritta dopo oggi ha il suo segno e non è spuntata; la conferma la rifiuta da sola, e raccoglie le altre', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
    $s = ViaRoma12::conFacciata($this)
        ->pianoStraordinario('Facciata 2024 — rate', 'Facciata 2026', ['Facciata' => 100000], '2024-03-12')
        ->pianoStraordinario('Tetto — rate', 'Facciata 2026', ['Facciata' => 100000], '2027-03-01');

    $anteprima = RaccoltaDelleDate::anteprima($s->c);
    $tetto = collect($anteprima)->firstWhere('giorno', '2027-03-01')['schede'][0];
    expect($tetto['sospetta'])->toBe(RaccoltaDelleDate::FRASE_DOPO_OGGI)->and($tetto['spuntata'])->toBeFalse();

    // Spuntata lo stesso: il rifiuto riguarda solo lei, sotto `spuntate`, e niente si raccoglie.
    try {
        RaccoltaDelleDate::conferma($s->c, [['piano_rate', $s->piano('Facciata 2024 — rate')->id], ['piano_rate', $s->piano('Tetto — rate')->id]], $s->admin);
        expect(true)->toBeFalse();
    } catch (\Illuminate\Validation\ValidationException $e) {
        expect($e->errors()['spuntate'][0])->toBe('La data del piano «Tetto — rate» è dopo oggi (01/03/2027): controllala sul verbale e correggila nella sua scheda, poi raccoglila.');
    }
    expect(CollegamentoAllaDelibera::query()->count())->toBe(0);

    // Le spuntate di serie si raccolgono.
    $spuntate = collect($anteprima)->flatMap(fn ($g) => $g['schede'])->filter(fn ($sc) => $sc['spuntata'])->map(fn ($sc) => [$sc['oggetto_tipo'], $sc['oggetto_id']])->values()->all();
    RaccoltaDelleDate::conferma($s->c, $spuntate, $s->admin);
    expect(CollegamentoAllaDelibera::query()->count())->toBe(1);
});

it('il perimetro per riunione: il piano della scala B entra in una riunione della sola scala B', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Ascensore scala B — rate', 'Facciata 2026', ['Facciata' => 100000], '2025-05-20');
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $anteprima = RaccoltaDelleDate::anteprima($s->c);

    RaccoltaDelleDate::conferma($s->c, [['piano_rate', $s->piano('Ascensore scala B — rate')->id]], $s->admin,
        [$anteprima[0]['chiave'] => ['ambito' => 'scala', 'scala_id' => $scala->id]]);

    $riunione = Assemblea::query()->where('origine', 'a_mano')->firstOrFail();
    expect($riunione->ambito->value)->toBe('scala')->and((int) $riunione->scala_id)->toBe($scala->id);
});

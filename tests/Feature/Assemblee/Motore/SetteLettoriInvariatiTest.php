<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\CollegamentiDelleDelibere;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R1 e §1.4 del progetto della tappa 2): collegare il piano emesso a una delibera non cambia niente di ciò che i lettori della data
 * leggono: le colonne del piano (`data_delibera_assemblea`, `numero_verbale`, `tipo_autorizzazione`), le righe congelate del riparto con la loro
 * competenza, le quote e le scritture.
 *
 * I due gesti passano dalle rotte delle pagine («Collega la delibera» del piano e «Approva»), perché la data la scrivono i controller, non il servizio.
 *
 * Che cosa non copre: i sette lettori chiamati uno per uno (`CompetenzaDelPiano`, `NotaSolidarieta`, `SeiMesi`, `ConsuntivoPerPersona`,
 * `DeliberaDellOpera`, `FascicoloLegale`, `FattiDellaNota`): leggono solo queste colonne, e questo test prova che non cambiano. Il numero del verbale
 * dell'«Approva» segue la riunione quando ne ha uno (per questo non è nel confronto del secondo caso).
 */

/** Il piano «Facciata 2026 — rate» emesso con la data del 20/10/2026, la delibera della riunione del 22/10/2026 e la fotografia di ciò che i lettori leggono. */
function sliPianoEmesso($t): array
{
    $s = ViaRoma12::conFacciata($t)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-20')
        ->genera('Facciata 2026 — rate');
    $s->emetti('Facciata 2026 — rate', '2026-12-31');
    $piano = $s->piano('Facciata 2026 — rate')->fresh();
    $foto = fn () => [
        'piano' => DB::table('piani_rate')->where('id', $piano->id)->first(['data_delibera_assemblea', 'numero_verbale', 'tipo_autorizzazione', 'stato']),
        'riparto' => DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'quote' => DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo'),
        'scritture' => DB::table('scritture_contabili')->where('condominio_id', $s->c->id)->count(),
    ];
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    return [$s, $piano, $d, $foto];
}

it('sul piano emesso, prima e dopo il collegamento dalla rotta della delibera del piano, i lettori leggono lo stesso', function () {
    [$s, $piano, $d, $foto] = sliPianoEmesso($this);
    $prima = $foto();

    $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.delibera', [$s->c, $s->e, $piano]),
        ['delibera_id' => $d->id, 'ruolo' => 'delibera'])->assertSessionHasNoErrors();

    expect(CollegamentiDelleDelibere::delibera($piano, 'delibera')?->id)->toBe($d->id)->and($foto())->toEqual($prima);
});

it('sul piano emesso, «Approva» con la delibera dalla rotta dello stato: la data resta, e i lettori leggono lo stesso', function () {
    [$s, $piano, $d, $foto] = sliPianoEmesso($this);
    $prima = $foto();

    $this->actingAs($s->admin)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]),
        ['approvato' => true, 'delibera_id' => $d->id])->assertSessionHasNoErrors();

    $dopo = $foto();
    expect(CollegamentiDelleDelibere::delibera($piano, 'delibera')?->id)->toBe($d->id)
        ->and($dopo['piano']->data_delibera_assemblea)->toBe($prima['piano']->data_delibera_assemblea)
        ->and($dopo['riparto'])->toEqual($prima['riparto'])->and($dopo['quote'])->toEqual($prima['quote'])->and($dopo['scritture'])->toBe($prima['scritture']);
});

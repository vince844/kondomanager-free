<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Console\Commands\VerificaDelibereCommand;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\RaccoltaDelleDate;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R13 del progetto della tappa 2; §13 rilievo 10): «delibera uguale al giorno dell'approvazione» salta i piani collegati a una
 * delibera del programma, o di una riunione a mano con il verbale caricato. Il piano creato il 22/10/2026 con la delibera di una riunione con il verbale non
 * è sospetto; quello con la data scritta a mano lo stesso giorno sì; quello raccolto senza verbale sì.
 *
 * Che cosa non copre: l'uscita a terminale (le due altre verifiche non cambiano).
 */

it('collegato a una riunione con il verbale non è sospetto; scritto a mano o raccolto senza verbale sì', function () {
    $s = ViaRoma12::conFacciata($this)
        ->pianoStraordinario('Con il verbale', 'Facciata 2026', ['Facciata' => 100000], '2026-10-22')
        ->pianoStraordinario('A mano', 'Facciata 2026', ['Facciata' => 100000], '2026-10-23')
        ->pianoStraordinario('Raccolto', 'Facciata 2026', ['Facciata' => 100000], '2026-10-24');
    DB::table('piani_rate')->where('nome', 'Con il verbale')->update(['approvato_il' => '2026-10-22 15:00:00']);
    DB::table('piani_rate')->where('nome', 'A mano')->update(['approvato_il' => '2026-10-23 15:00:00']);
    DB::table('piani_rate')->where('nome', 'Raccolto')->update(['approvato_il' => '2026-10-24 15:00:00']);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Facciata', 'approvata', '41');
    $d->assemblea->update(['verbale_documento_id' => \App\Models\Documento::query()->create(['name' => 'Verbale', 'path' => 'x.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 1, 'is_published' => false, 'is_approved' => true])->id]);
    CollegamentiDelleDelibere::collega($s->piano('Con il verbale'), 'delibera', $d, null, $s->admin);
    RaccoltaDelleDate::conferma($s->c, [['piano_rate', $s->piano('Raccolto')->id]], $s->admin);

    $sospetti = (new ReflectionMethod(VerificaDelibereCommand::class, 'deliberaSospetta'))->invoke(app(VerificaDelibereCommand::class), $s->c->id);
    expect(collect($sospetti)->pluck('piano')->sort()->values()->all())->toBe(['A mano', 'Raccolto']);
});

it('il collegamento non zittisce il comando quando la data del piano resta quella del clic, né con una tenuta fuori senza verbale', function () {
    $s = ViaRoma12::conFacciata($this)
        ->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 100000], '2026-10-20')
        ->pianoStraordinario('Tetto — rate', 'Facciata 2026', ['Facciata' => 100000], '2026-11-18');
    DB::table('piani_rate')->where('nome', 'Facciata 2026 — rate')->update(['approvato_il' => '2026-10-20 15:00:00']);
    DB::table('piani_rate')->where('nome', 'Tetto — rate')->update(['approvato_il' => '2026-11-18 15:00:00']);
    $sospetta = fn () => collect((new ReflectionMethod(VerificaDelibereCommand::class, 'deliberaSospetta'))->invoke(app(VerificaDelibereCommand::class), $s->c->id))
        ->pluck('piano')->all();

    // La riunione vera del 22/10, con il verbale: il piano emesso tiene la data del 20/10, che è ancora quella che decide chi paga.
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Facciata', 'approvata', '41');
    $d->assemblea->update(['verbale_documento_id' => \App\Models\Documento::query()->create(['name' => 'Verbale', 'path' => 'x.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 1, 'is_published' => false, 'is_approved' => true])->id]);
    CollegamentiDelleDelibere::collega($s->piano('Facciata 2026 — rate'), 'delibera', $d, null, $s->admin);
    // L'ordinaria preparata nel programma e tenuta su carta il 18/11, senza verbale caricato.
    $memo = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il tetto'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [\App\Services\Assemblee\MemoriaDelCondominio::comeProposta($memo)]);
    \App\Services\Assemblee\StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [$a->punti()->first()->id => ['esito' => 'approvata']], '2026-11-18');
    CollegamentiDelleDelibere::collega($s->piano('Tetto — rate'), 'delibera', $a->delibere()->first(), null, $s->admin);

    $gruppi = \App\Services\Assemblee\DelibereDelCondominio::scegli($s->c, null, null, 'delibera', null)['gruppi'];
    $tenuta = collect($gruppi)->firstWhere(fn ($g) => $g['assemblea']['id'] === $a->id);
    expect($sospetta())->toContain('Facciata 2026 — rate')->toContain('Tetto — rate')
        ->and($tenuta['assemblea']['verbale_da_caricare'])->toBeTrue();
});

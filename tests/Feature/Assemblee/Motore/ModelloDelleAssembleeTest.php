<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\SedutaDellAssemblea;
use Illuminate\Database\QueryException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §1.1 e §7 del progetto della tappa 2): il modello dei dati. Le relazioni ci sono e portano dove devono; le chiavi `restrict`
 * fermano la cancellazione in cascata (un condominio con assemblee, una riunione con delibere); le sedute, i punti e i loro oggetti scendono con l'assemblea;
 * le mappe delle rotte annidate del condominio e dell'assemblea hanno le voci nuove.
 *
 * Che cosa non copre: MySQL (le chiavi sono provate su SQLite; la cascata circolare della tabella di proprietà è provata a mano su MySQL, nel rapporto); le
 * guardie con le frasi (`GuardieDiCancellazioneTest`).
 */

it('le relazioni dell\'assemblea e del condominio portano ai modelli giusti', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    expect($a->sedute()->count())->toBe(2)
        ->and($a->sedute()->first())->toBeInstanceOf(SedutaDellAssemblea::class)
        ->and($a->sedute()->first()->convocazione->value)->toBe('prima')
        ->and($a->punti()->first())->toBeInstanceOf(PuntoDellOrdineDelGiorno::class)
        ->and($a->data->format('Y-m-d'))->toBe('2026-12-15')
        ->and($a->nome())->toBe('Ordinaria del 15/12/2026')
        ->and($s->c->assemblee()->count())->toBe(2)
        ->and($s->c->delibere()->first())->toBeInstanceOf(Delibera::class)
        ->and($d->assemblea->eAMano())->toBeTrue()
        ->and($d->assemblea->nome())->toBe('Riunione del 22/10/2026')
        ->and($d->punto->assemblea_id)->toBe($d->assemblea_id);
});

it('le chiavi restrict fermano la cascata: il condominio con un\'assemblea e la riunione con una delibera non si cancellano dal database', function () {
    $s = ViaRoma12::nuovo($this);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata');

    expect(fn () => \Illuminate\Support\Facades\DB::table('assemblee')->where('id', $d->assemblea_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => \Illuminate\Support\Facades\DB::table('condomini')->where('id', $s->c->id)->delete())->toThrow(QueryException::class);
});

it('le sedute, i punti e i loro oggetti scendono con l\'assemblea in preparazione', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Ratifica', 'materia' => 'ratifica', 'oggetti' => [['gestione', 1, 'parla_di']]]]);
    expect(OggettoDelPunto::query()->count())->toBe(1);

    \Illuminate\Support\Facades\DB::table('assemblee')->where('id', $a->id)->delete();

    expect(SedutaDellAssemblea::query()->count())->toBe(0)->and(PuntoDellOrdineDelGiorno::query()->count())->toBe(0)
        ->and(OggettoDelPunto::query()->count())->toBe(0);
});

it('le mappe delle rotte annidate hanno le voci delle assemblee', function () {
    $mappa = fn (string $classe) => (new ReflectionMethod($classe, 'relazioniDeiFigliNelleRotte'))->invoke(new $classe());

    expect($mappa(\App\Models\Condominio::class))->toMatchArray(['assemblea' => 'assemblee', 'delibera' => 'delibere', 'memo' => 'memoDelleAssemblee',
        'rigaDiNomina' => 'righeDiNomina'])
        ->and($mappa(Assemblea::class))->toBe(['seduta' => 'sedute', 'punto' => 'punti', 'delibera' => 'delibere', 'invio' => 'invii',
            'convocato' => 'convocati', 'memo' => 'memo', 'delegaRicevuta' => 'deleghe', 'verbaleDellAssemblea' => 'verbali']);
});

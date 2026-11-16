<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La misura di «Ferma per l'assemblea» nel condominio grande delle misure del conguaglio (3.000 unità, 3.300 persone, 30 voci): il tempo, la memoria
 * in più e il peso delle cifre fermate, con il solo riepilogo nel PDF (il fascicolo intero ha il suo tetto, che la versione rispetta: oltre, nasce senza
 * PDF). La lettura del conguaglio si fa una volta, per le cifre e per il fascicolo. Le soglie sono scritte qui, accanto alle misure del 14/11/2026 su
 * un Apple M1 Pro, SQLite in memoria (il condominio di prova resta fuori dalla memoria misurata).
 *
 * Misure: con le voci sulla stessa tabella 2,3 s, 62,1 MB in più, cifre 1,53 MB di JSON; con una tabella per voce 16,7 s, 64,5 MB in più, cifre 1,53 MB.
 * Rifatta dopo che le cifre si scrivono in JSON prima del fascicolo: 2,4 s e 62,7 MB; 17,1 s e 64,7 MB. Il picco è la lettura del conguaglio con la
 * spesa per tabella (la scheda «Conguaglio», senza la spesa per tabella, sta a 45–49 MB): la versione la fa una volta e la cede al fascicolo, che la libera
 * prima di stampare le parti; mentre le parti si stampano restano le cifre in JSON (1,5 MB), non il loro array (circa 10 MB). Le cifre pesano più della
 * stima del progetto (0,8 MB) per i nomi della persona e dell'unità di ogni riga e i termini di B.
 */

use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Gestionale\VersioniDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

/** Le soglie: i MB in più del picco (il limite di una richiesta è 128 MB, e il resto serve al programma), i secondi, i MB delle cifre. */
const RVM_MEGA = 96;
const RVM_SECONDI_COMUNE = 10.0;
const RVM_SECONDI_PER_VOCE = 40.0;
const RVM_MEGA_CIFRE = 3.0;

beforeEach(function () {
    $this->travelTo('2027-03-01 10:00:00');
    Storage::fake('local');
});

/** «Ferma» misurato: la versione, i secondi, i MB in più del picco, i MB delle cifre nel database. */
function rvmFerma(array $s, string $etichetta): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $v = app(VersioniDelRendiconto::class)->ferma($s['c'], $s['e'], ['riepilogo'], false, false, User::factory()->create());
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $mega = (memory_get_peak_usage() - $prima) / 1048576;
    $cifre = strlen((string) DB::table('rendiconto_versioni')->where('id', $v->id)->value('cifre')) / 1048576;
    fwrite(STDERR, sprintf("\n[misura] Ferma, %s: %.2f s, %.1f MB in più, cifre %.2f MB\n", $etichetta, $secondi, $mega, $cifre));

    return [$v, $secondi, $mega, $cifre];
}

it('30 voci sulla stessa tabella: la versione con le cifre di 3.300 persone sta nel tempo e nella memoria', function () {
    $s = cgmCondominio(false);
    [$v, $secondi, $mega, $cifre] = rvmFerma($s, 'tabella comune');

    expect(count(RendicontoVersione::findOrFail($v->id)->cifre['conguaglio']['righe']))->toBe($s['persone'])
        ->and($v->pdf['documenti'])->toHaveCount(1)
        ->and($secondi)->toBeLessThan(RVM_SECONDI_COMUNE)->and($mega)->toBeLessThan(RVM_MEGA)->and($cifre)->toBeLessThan(RVM_MEGA_CIFRE);
});

it('una tabella per voce: la lettura più lunga, la stessa memoria e lo stesso peso', function () {
    $s = cgmCondominio(true);
    [$v, $secondi, $mega, $cifre] = rvmFerma($s, 'una tabella per voce');

    expect(count(RendicontoVersione::findOrFail($v->id)->cifre['conguaglio']['righe']))->toBe($s['persone'])
        ->and($secondi)->toBeLessThan(RVM_SECONDI_PER_VOCE)->and($mega)->toBeLessThan(RVM_MEGA)->and($cifre)->toBeLessThan(RVM_MEGA_CIFRE);
});

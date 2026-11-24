<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il limite di tempo di backup, ripristino e aggiornamento dal pannello (`LimiteDiTempo::alza`).
 *
 * Due difetti, una funzione sola. Da riga di comando il limite vale 0 (nessun limite): un `set_time_limit(50)` secco lo ABBASSA a 50 secondi, e il backup
 * e il ripristino lo facevano. Dove l'hosting vieta `set_time_limit` con `disable_functions`, in PHP 8 la funzione non esiste e chiamarla è un errore che `@`
 * non tace: l'aggiornamento, il backup e il ripristino si fermavano con un errore.
 *
 * Il limite e le funzioni vietate sono del processo: ogni caso gira in un processo a parte (solo l'autoload), sul modello di `PdfServiceLimiteDiTempoTest`.
 */

use App\Support\LimiteDiTempo;
use Symfony\Component\Process\Process;

/** Il limite di tempo dopo `LimiteDiTempo::alza($secondi)`, con le impostazioni di PHP date; vuoto se il processo si è fermato con un errore. */
function limiteDopoAlza(int $secondi, string ...$ini): string
{
    $comando = [PHP_BINARY];
    foreach ($ini as $i) {
        array_push($comando, '-d', $i);
    }
    array_push($comando, '-r', 'require getcwd()."/vendor/autoload.php"; \\App\\Support\\LimiteDiTempo::alza('.$secondi.'); echo ini_get("max_execution_time");');
    $processo = new Process($comando, base_path());
    $processo->run();

    return $processo->getOutput();
}

test('da riga di comando, dove il limite è 0, alza() non lo abbassa: backup e ripristino restano senza limite', function () {
    expect(limiteDopoAlza(50, 'max_execution_time=0'))->toBe('0')
        ->and(limiteDopoAlza(120, 'max_execution_time=0'))->toBe('0')
        ->and(limiteDopoAlza(0, 'max_execution_time=0'))->toBe('0');
});

test('un limite finito più basso si alza; uno uguale o più alto resta com\'era', function () {
    expect(limiteDopoAlza(50, 'max_execution_time=30'))->toBe('50')
        ->and(limiteDopoAlza(50, 'max_execution_time=1'))->toBe('50')
        ->and(limiteDopoAlza(50, 'max_execution_time=50'))->toBe('50')
        ->and(limiteDopoAlza(50, 'max_execution_time=300'))->toBe('300');
});

test('alza(0), dell\'aggiornamento dal pannello, toglie il limite a chi ne ha uno', function () {
    expect(limiteDopoAlza(0, 'max_execution_time=60'))->toBe('0');
});

test('dove l\'hosting vieta set_time_limit nessun errore: il limite resta quello del processo', function () {
    expect(limiteDopoAlza(50, 'max_execution_time=30', 'disable_functions=set_time_limit'))->toBe('30')
        ->and(limiteDopoAlza(0, 'max_execution_time=60', 'disable_functions=set_time_limit'))->toBe('60')
        ->and(limiteDopoAlza(50, 'max_execution_time=0', 'disable_functions=set_time_limit'))->toBe('0')
        // Un altro elenco di funzioni vietate non cambia niente.
        ->and(limiteDopoAlza(50, 'max_execution_time=30', 'disable_functions=exec,shell_exec'))->toBe('50');
});

test('i punti che alzavano il limite a mano usano la funzione comune: niente set_time_limit fuori da LimiteDiTempo', function () {
    $dove = [
        'app/Services/Backup/BackupManager.php',
        'app/Services/Restore/RestoreManager.php',
        'app/Services/System/SystemFinalizer.php',
        'app/Services/PDF/PdfService.php',
    ];
    foreach (glob(database_path('migrations/*.php')) as $migrazione) {
        $dove[] = 'database/migrations/'.basename($migrazione);
    }
    foreach ($dove as $file) {
        $righe = preg_grep('/\bset_time_limit\s*\(/', preg_split('/\R/', file_get_contents(base_path($file))));
        // Un commento che nomina la funzione senza chiamarla non conta: si cerca la chiamata a inizio istruzione.
        $chiamate = array_filter($righe, fn ($r) => ! preg_match('#^\s*(//|\*|/\*)#', $r));
        expect($chiamate)->toBe([], $file);
    }
    expect(method_exists(LimiteDiTempo::class, 'alza'))->toBeTrue();
});

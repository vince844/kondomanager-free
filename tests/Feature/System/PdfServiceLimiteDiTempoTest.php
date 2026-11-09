<?php

/**
 * Il limite di tempo di una stampa. `PdfService` lo porta a 120 s dove è finito e più basso: una stampa grande (un libro giornale di un anno attivo)
 * supera i 30-60 s di un hosting condiviso. Non lo tocca dove non c'è limite (da riga di comando vale 0, e diventerebbe 120) né dove è già più alto.
 *
 * Dove l'hosting vieta `set_time_limit` con `disable_functions`, in PHP 8 la funzione non esiste: chiamarla è un errore che `@` non tace, e ogni
 * stampa si fermerebbe alla prima. Lì il limite resta quello del processo e la stampa parte.
 *
 * Il limite e le funzioni vietate sono del processo: ogni caso gira in un processo a parte (solo l'autoload, nessuna applicazione, nessun database),
 * così la prova non li tocca in quello dei test.
 */

use App\Services\PDF\PdfService;
use Illuminate\Support\Facades\View;
use Symfony\Component\Process\Process;

/** Il limite di tempo del processo dopo il rialzo, con le impostazioni di PHP date; vuoto se il processo si è fermato con un errore. */
function pdfLimiteDopoIlRialzo(string ...$ini): string
{
    $comando = [PHP_BINARY];
    foreach ($ini as $i) {
        array_push($comando, '-d', $i);
    }
    array_push($comando, '-r', 'require getcwd()."/vendor/autoload.php"; \\App\\Services\\PDF\\PdfService::alzaIlLimiteDiTempo(); echo ini_get("max_execution_time");');
    $processo = new Process($comando, base_path());
    $processo->run();

    return $processo->getOutput();
}

test('dove PHP concede di alzarlo, un limite fra 1 e 119 s diventa 120; un limite più alto, o nessun limite, restano com\'erano', function () {
    expect(pdfLimiteDopoIlRialzo('max_execution_time=30'))->toBe('120')
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=1'))->toBe('120')
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=119'))->toBe('120')
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=120'))->toBe('120')
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=300'))->toBe('300')
        // Da riga di comando il limite è 0, nessun limite: non diventa 120.
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=0'))->toBe('0');
});

test('dove l\'hosting vieta set_time_limit la stampa non si ferma: il limite resta quello del processo', function () {
    expect(pdfLimiteDopoIlRialzo('max_execution_time=30', 'disable_functions=set_time_limit'))->toBe('30')
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=45', 'disable_functions=set_time_limit'))->toBe('45')
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=0', 'disable_functions=set_time_limit'))->toBe('0')
        // Un altro elenco di funzioni vietate non cambia niente: set_time_limit c'è, e il limite si alza.
        ->and(pdfLimiteDopoIlRialzo('max_execution_time=30', 'disable_functions=exec,shell_exec'))->toBe('120');
});

test('generate() alza il limite di tempo prima di scrivere il PDF', function () {
    View::shouldReceive('make')->once()->andReturn(new class
    {
        public function render()
        {
            return '<html><body><p>Prova</p></body></html>';
        }
    });
    $prima = ini_get('max_execution_time');
    ini_set('max_execution_time', '30');
    try {
        app(PdfService::class)->generate('pdf.base', ['senza_firma' => true, 'gruppo_carta' => \App\Services\PDF\GruppoCarta::REGISTRI_E_ELENCHI]);

        expect(ini_get('max_execution_time'))->toBe('120');
    } finally {
        // Il processo dei test torna com'era (da riga di comando, nessun limite).
        set_time_limit((int) $prima);
        ini_set('max_execution_time', $prima);
    }
});

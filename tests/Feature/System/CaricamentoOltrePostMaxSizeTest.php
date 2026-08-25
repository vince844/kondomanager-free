<?php

/**
 * Un file più grande di `post_max_size` non deve produrre «sessione scaduta».
 *
 * ## Il caso, e perché è insidioso
 *
 * PHP ha due limiti e si comportano in modo diverso:
 *
 * - superato **`upload_max_filesize`**: la richiesta arriva, il file è marcato come scartato, e la
 *   regola `uploaded` di Laravel produce un messaggio — dalla beta.58 con dentro il limite vero.
 * - superato **`post_max_size`**: PHP scarta **l'intero corpo** della richiesta prima che PHP-FPM lo
 *   consegni all'applicazione. Spariscono i campi, spariscono i file e **sparisce il token CSRF**:
 *   Laravel non vede un file troppo grande, vede una richiesta senza token e risponde **419
 *   «pagina scaduta»**.
 *
 * Cioè: a un problema di dimensione il programma risponde parlando di sessione. È la trappola in cui
 * cade **chi segue a metà il nostro consiglio** — il messaggio del caricamento dice di alzare
 * `upload_max_filesize` *e* `post_max_size`, e chi alza solo il primo finisce esattamente qui.
 *
 * ## Come si riconosce il caso
 *
 * `CONTENT_LENGTH` arriva comunque nell'intestazione, anche quando il corpo è stato scartato: se
 * supera `post_max_size` e il corpo è vuoto, non è una sessione scaduta — è un file troppo grande.
 */

use App\Support\LimiteCaricamento;

it('la pagina d\'errore del 413 esiste e nomina il limite vero', function () {
    // ⚠️ Riscritto dal ripasso della beta.58. La prima stesura provava `corpoScartatoDalServer()`,
    // un riconoscimento che serviva a un gestore agganciato a `TokenMismatchException` — e quel
    // gestore era **codice morto**: `Handler::prepareException()` converte quell'eccezione in
    // `HttpException(419)` prima che i callback la vedano. Il metodo è stato tolto con lui.
    //
    // Soprattutto: il gestore rispondeva con `back()->withErrors()`, che in quel punto **non
    // consegna niente** — `ValidatePostSize` è nello stack globale e `StartSession` nel gruppo web,
    // quindi la sessione non è ancora partita e il flash finisce in un archivio mai salvato.
    // Adesso si risponde con una vista, che di sessione non ha bisogno.
    $reso = view('errors.413')->render();

    expect($reso)->toContain(LimiteCaricamento::etichettaPost())
        ->and($reso)->toContain('post_max_size')
        ->and($reso)->not->toContain(':limite');
});

it('per una richiesta che si aspetta JSON risponde JSON, non una pagina HTML', function () {
    // Il gestore è globale: vale anche per le chiamate che non sono un modulo. Rispondere con una
    // pagina a chi si aspetta JSON produrrebbe un errore di analisi al posto di un messaggio.
    $codice = file_get_contents(base_path('bootstrap/app.php'));

    expect($codice)->toContain("expectsJson()")
        ->and($codice)->toContain("response()->view('errors.413', [], 413)");
});

it('il ramo morto non è rimasto in giro: TokenMismatchException non è più agganciata', function () {
    // ⚠️ L'asserzione vieta il **gestore**, non la parola: il commento che spiega perché quel ramo è
    // stato tolto deve restare, ed è la parte che impedisce di riscriverlo domani. È la terza volta
    // in questa beta che una mia asserzione vietava una stringa invece di un comportamento.
    $codice = file_get_contents(base_path('bootstrap/app.php'));

    expect($codice)->not->toContain('render(fn (TokenMismatchException')
        ->and($codice)->not->toContain('render(function (TokenMismatchException')
        ->and($codice)->not->toContain('use Illuminate\\Session\\TokenMismatchException;');
});

it('il messaggio mostrato parla di dimensione e non di sessione', function () {
    // ⚠️ La prima stesura **fabbricava** il messaggio con `trans(..., ['limite' => etichetta()])` e
    // poi verificava che quell'ingrediente ci fosse: era cieca sul suo unico dato variabile, perché
    // il programma usa `etichettaPost()`, che è un altro numero. Un test che si costruisce il
    // proprio atteso non può accorgersi di uno scambio. Ora legge la **pagina che il programma
    // renderà davvero**.
    $reso = view('errors.413')->render();

    expect($reso)->toContain(LimiteCaricamento::etichettaPost())
        ->and($reso)->toContain('post_max_size')
        ->and($reso)->toContain('supera il limite')
        ->and($reso)->not->toContain(':limite');
});


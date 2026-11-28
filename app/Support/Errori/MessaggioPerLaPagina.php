<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Errori;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Il messaggio che un `catch` generico di un controller mostra in pagina.
 *
 * Un `catch (\Throwable $e)` raccoglie due cose diverse: le regole del programma, che i servizi sollevano con
 * una frase scritta per l'amministratore («La voce è già stata chiesta tutta»), e i guasti (una query che
 * fallisce, un file che non si scrive, un errore di tipo). Le prime si mostrano come sono: dicono che cosa fare.
 * I secondi no: il loro testo è per chi sviluppa (`SQLSTATE[23000] … for key 'numero_protocollo_unique'`),
 * può portare nomi di tabelle, percorsi e dati di altre righe, e all'amministratore non dice niente. Per loro la
 * pagina riceve una frase fissa con un riferimento breve, e il dettaglio va nel registro con lo stesso
 * riferimento: chi segnala scrive sei caratteri e si trova la riga.
 *
 * Una regola del programma è un'eccezione della cartella `App\`, una delle eccezioni di base di PHP sollevate
 * così come sono (`\Exception`, `\RuntimeException`, `\DomainException`, `\InvalidArgumentException`,
 * `\LogicException`, `\UnexpectedValueException`), che è come i servizi del gestionale scrivono le loro regole, o una
 * delle eccezioni del framework fatte per chi usa la pagina (il modulo non valido, il rifiuto, un `abort()` con la sua frase).
 * Ogni altra classe (le eccezioni del database, dei file, del framework, gli `\Error` di PHP) è un guasto, e lo è
 * anche un'eccezione di base il cui testo porta `SQLSTATE` (un servizio che ha riavvolto un errore del database).
 *
 * Con `APP_DEBUG` acceso il testo vero si aggiunge al riferimento, come in `ErroriDiagnosticabili`: chi sviluppa
 * non deve cercarlo nel registro. A differenza di quella classe non rilancia nei test: le prove devono poter
 * vedere il messaggio che la pagina riceve.
 */
final class MessaggioPerLaPagina
{
    /** Le eccezioni di base che i servizi sollevano con una frase per l'amministratore. */
    private const REGOLE = [
        \Exception::class,
        \RuntimeException::class,
        \DomainException::class,
        \InvalidArgumentException::class,
        \LogicException::class,
        \UnexpectedValueException::class,
    ];

    /**
     * La frase da mettere in pagina per l'eccezione raccolta.
     *
     * @param  array<string, mixed>  $contesto
     */
    public static function di(Throwable $e, string $operazione, array $contesto = []): string
    {
        if (self::eUnaRegola($e)) {
            return $e->getMessage();
        }

        $riferimento = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        Log::error($operazione, array_merge($contesto, [
            'riferimento' => $riferimento,
            'classe'      => $e::class,
            'eccezione'   => $e->getMessage(),
            'file'        => $e->getFile().':'.$e->getLine(),
        ]));

        $frase = __('errors.guasto_con_riferimento', ['riferimento' => $riferimento]);

        return config('app.debug') ? $frase.' — '.$e->getMessage() : $frase;
    }

    public static function eUnaRegola(Throwable $e): bool
    {
        // Un servizio che riavvolge un guasto in un'eccezione di base ne porta il testo: resta un guasto.
        if (str_contains($e->getMessage(), 'SQLSTATE')) {
            return false;
        }

        if (str_starts_with($e::class, 'App\\')) {
            return true;
        }

        // Le eccezioni del framework che portano una frase per chi usa la pagina: il modulo non valido, il rifiuto, la risposta HTTP voluta.
        if ($e instanceof \Illuminate\Validation\ValidationException
            || $e instanceof \Illuminate\Auth\Access\AuthorizationException
            || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return true;
        }

        return in_array($e::class, self::REGOLE, true);
    }
}

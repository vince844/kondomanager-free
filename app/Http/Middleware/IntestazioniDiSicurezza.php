<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le intestazioni di sicurezza su ogni risposta (Coda 264).
 *
 * - `X-Frame-Options: SAMEORIGIN` e, della CSP, solo `frame-ancestors 'self'`: il gestionale si apre in
 *   un riquadro solo dentro sé stesso, mai in quello di un altro sito. Le due insieme perché i browser
 *   vecchi leggono la prima e i nuovi la seconda. La CSP completa è un lavoro a sé (pagine d'errore con
 *   script da CDN, font esterno, script in linea): qui c'è solo la direttiva dei riquadri.
 * - `X-Content-Type-Options: nosniff`: il browser usa il tipo dichiarato e non lo indovina dal contenuto.
 * - `Referrer-Policy: strict-origin-when-cross-origin`: verso un altro sito esce solo l'origine, non
 *   l'indirizzo della pagina; dentro il gestionale il Referer resta intero (`url()->previous()` lo usa).
 * - `Strict-Transport-Security` solo se la richiesta è sicura **dopo** i proxy fidati: `isSecure()`
 *   guarda `X-Forwarded-Proto` solo se viene da un proxy di `TRUSTED_PROXIES`. Senza `includeSubDomains`
 *   né `preload`, e con una durata moderata: un'installazione che torna su http, o resta con il
 *   certificato scaduto, è bloccata nei browser che l'hanno già visitata al massimo per quel tempo. La
 *   durata riparte a ogni visita, quindi copre chi usa il gestionale almeno una volta al mese.
 *
 * Globale e in testa alla pila (`bootstrap/app.php`): vale anche per `/up`, che sta fuori dal gruppo
 * `web`, per la 503 del ripristino, che `CheckRestoreMode` dà in testa al gruppo `web`, per la
 * manutenzione e per le pagine d'errore. Un'intestazione che la risposta ha già non si tocca, e nginx
 * non le ripete: la stessa intestazione mandata due volte vale come non valida.
 */
class IntestazioniDiSicurezza
{
    /** Trenta giorni. */
    public const HSTS_DURATA_SECONDI = 2592000;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $intestazioni = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ];

        if ($request->isSecure()) {
            $intestazioni['Strict-Transport-Security'] = 'max-age='.self::HSTS_DURATA_SECONDI;
        }

        foreach ($intestazioni as $nome => $valore) {
            if (! $response->headers->has($nome)) {
                $response->headers->set($nome, $valore);
            }
        }

        return $response;
    }
}

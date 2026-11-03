<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * L'indirizzo da mettere nelle chiavi dei limiti per indirizzo (Code 262, 259 e 202).
 *
 * Un indirizzo IPv4 conta per sé stesso. Un indirizzo IPv6 conta per la sua rete /64: chi ha una linea o
 * un server con IPv6 riceve una /64 intera (2^64 indirizzi) e ne sceglie l'ultima parte come vuole, quindi
 * i limiti del login, il tetto per indirizzo e i tetti di rotta valgono per la rete. Chi sta sulla stessa
 * /64 condivide i limiti, come chi esce dallo stesso IPv4: gli utenti di un ufficio, di una rete Wi-Fi
 * pubblica, di un hosting che divide una /64 fra più clienti.
 *
 * Un IPv4 scritto in forma IPv6 (`::ffff:a.b.c.d`, come lo riporta un server a doppio stack) resta
 * l'indirizzo IPv4 intero. Un valore che non è un indirizzo IPv6 (un IPv4, `null` in console) torna com'è.
 *
 * Una sola definizione per tutte le chiavi: un limite nuovo per indirizzo passa da qui.
 */
final class IndirizzoDeiLimiti
{
    public static function di(?string $indirizzo): string
    {
        $indirizzo = (string) $indirizzo;

        if (filter_var($indirizzo, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $indirizzo;
        }

        // Zero byte tolti a un IPv4 (anche mappato), otto a un IPv6: restano i primi 64 bit.
        return IpUtils::anonymize($indirizzo, 0, 8);
    }
}

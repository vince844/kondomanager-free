<?php

namespace App\Services\Gestionale\Ricevute;

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Il limite sulle ricevute mandate a un indirizzo scritto a mano (1.12.0-beta.2, decisione 204): è l'unico modo di far partire dal server dello
 * studio molti messaggi, ognuno con il PDF di una ricevuta, verso indirizzi qualunque, e se ne abusa finisce in lista nera. Al massimo dieci al
 * minuto per utente, con un conto solo per tutte le strade: la finestra dell'incasso (un indirizzo scritto a mano), «Invia di nuovo» e
 * «Riprova le non partite» di una riga che era andata a un indirizzo scritto a mano, e la finestra di un incasso annullato che rimanda la
 * ricevuta all'indirizzo scritto a mano a cui era partita (un posto per indirizzo). Un invio all'indirizzo di una persona non conta.
 */
class LimiteAltroIndirizzo
{
    public const MASSIMO_AL_MINUTO = 10;

    /** Quello che legge chi lavora quando il limite scatta. */
    public const FRASE = 'Hai già mandato dieci ricevute a indirizzi scritti a mano nell\'ultimo minuto: aspetta un momento e riprova.';

    private static function chiave(?User $utente): string
    {
        return 'ricevuta-altro-indirizzo|' . ($utente?->getAuthIdentifier() ?? 'senza-utente');
    }

    /** Conta un invio a un indirizzo scritto a mano; falso se in questo minuto ne sono già partiti dieci (e allora non conta). */
    public static function consuma(?User $utente): bool
    {
        return RateLimiter::attempt(self::chiave($utente), self::MASSIMO_AL_MINUTO, fn () => true, 60) === true;
    }

    /** Fra quanti secondi si può mandare di nuovo. */
    public static function secondiAllaProssima(?User $utente): int
    {
        return RateLimiter::availableIn(self::chiave($utente));
    }

    /** La riga è stata mandata a un indirizzo scritto a mano (non a quello di una persona)? */
    public static function aMano(RegistroInvio $riga): bool
    {
        return (string) ($riga->dati['tipo_indirizzo'] ?? 'altro') === 'altro';
    }
}

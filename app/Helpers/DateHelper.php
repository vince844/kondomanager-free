<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;

class DateHelper
{
    /**
     * La data di "oggi" nel fuso in cui vive l'amministratore.
     *
     * I timestamp dell'applicazione restano in UTC (config/app.php `timezone`), ma le
     * validazioni sulle date digitate a mano — "non può essere futura" — devono usare
     * il calendario dell'utente, non quello del server.
     *
     * Senza questa distinzione, un amministratore italiano che registra un pagamento
     * alle 00:30 sceglie dal date-picker la data di oggi (ora locale) e se la vede
     * respingere: in UTC sono ancora le 22:30 del giorno prima. La finestra è di 1-2
     * ore per notte, ma l'errore è incomprensibile per chi lo riceve.
     *
     * Restituisce una stringa Y-m-d, pronta per `before_or_equal:`.
     */
    public static function oggiUtente(): string
    {
        return Carbon::now(config('app.user_timezone', 'Europe/Rome'))->toDateString();
    }
}

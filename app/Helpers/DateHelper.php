<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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

    /**
     * L'istante di adesso nel fuso dell'amministratore: il giorno e l'ora che una stampa scrive («Stampato il …», «Documento emesso il …»).
     * Fra le 00:00 e le 02:00 di Roma `now()` (UTC) è ancora il giorno prima, e alle 00:30 del 01/01 una stampa di fine anno diceva il 31/12.
     */
    public static function adessoUtente(): Carbon
    {
        return Carbon::now(config('app.user_timezone', 'Europe/Rome'));
    }

    /**
     * Lo stesso «oggi» dell'utente, come data immutabile: per `inCorsoIl()` e i confronti fra date. Fra le 00:00 e
     * le 02:00 di Roma `CarbonImmutable::today()` (UTC) è ancora ieri: una titolarità che decorre da oggi
     * risulterebbe non ancora in corso, e una finita ieri ancora in corso.
     */
    public static function oggiUtenteImmutable(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse(self::oggiUtente());
    }

    /**
     * Un istante scritto come l'agenda scrive le ore: il giorno e l'ora di Roma così come si leggono, nel fuso dell'applicazione (UTC), senza la conversione
     * che d'inverno li sposterebbe di un'ora e d'estate di due. È la forma di `eventi.start_time` per gli eventi scritti a mano e per i compiti delle due
     * Inbox, che leggono il giorno dalla data di `start_time` ({@see \App\Services\Gestionale\Inbox\FasceDeiCompiti}): alle 00:30 di Roma del 16/11 un
     * compito scritto con `now()` (le 23:30 UTC del 15/11) nasceva «scaduto da ieri»; scritto così è delle 00:30 del 16/11.
     */
    public static function comeSiLegge(\DateTimeInterface $istante): Carbon
    {
        return Carbon::parse(
            Carbon::instance($istante)->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d H:i:s'),
            (string) config('app.timezone')
        );
    }

    /** Adesso, scritto come l'agenda scrive le ore ({@see comeSiLegge()}): il giorno e l'ora di un compito che nasce adesso. */
    public static function adessoComeSiLegge(): Carbon
    {
        return self::comeSiLegge(Carbon::now());
    }
}

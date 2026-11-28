<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Listeners;

use App\Models\DispositivoConosciuto;
use App\Models\User;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * L'avviso al titolare quando qualcuno entra nel suo account da un dispositivo nuovo (Coda 385).
 *
 * Un dispositivo è un gettone casuale in un cookie lungo del browser (cifrato e firmato dal gruppo `web`);
 * la tabella `dispositivi_conosciuti` ne tiene l'impronta. All'accesso:
 *
 * - il gettone è conosciuto per questo utente → si aggiornano l'ultima volta e l'indirizzo, nessun avviso;
 * - il gettone manca o non è di questo utente → il dispositivo si registra, e se l'utente ha già almeno un dispositivo
 *   parte l'avviso `ACCESSO_DA_DISPOSITIVO_NUOVO` (data, browser e sistema, indirizzo approssimato). Il primo
 *   accesso di sempre non avvisa: non c'è niente di conosciuto con cui confrontarlo.
 *
 * Sull'evento `Login`, come `AggiornaUltimoAccesso`: lo emettono il modulo, la sfida a due fattori e il cookie
 * «ricordami». Con la verifica in due passaggi l'evento scatta solo dopo la sfida superata
 * (`AuthenticatedSessionController::store` confronta la password senza fare l'accesso), quindi la sola
 * password giusta non registra un dispositivo e non avvisa. Il sospeso no: il suo accesso è respinto.
 *
 * Avvisare è un servizio, non una condizione per entrare: senza la tabella (un aggiornamento non ancora
 * finito) o con un errore qualsiasi l'accesso riesce lo stesso, e l'errore va nel registro.
 */
class AvvisaDispositivoNuovo
{
    public const COOKIE = 'km_dispositivo';

    /** Quanto dura il cookie, in minuti: quattrocento giorni, il massimo che i browser tengono. */
    private const DURATA_COOKIE = 400 * 24 * 60;

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->suspended()) {
            return;
        }

        try {
            if (! Schema::hasTable('dispositivi_conosciuti')) {
                return;
            }

            $this->registra($user);
        } catch (\Throwable $e) {
            Log::error("Dispositivo dell'accesso non registrato per l'utente {$user->id}: ".class_basename($e));
        }
    }

    private function registra(User $user): void
    {
        $request = request();
        $gettone = (string) $request->cookie(self::COOKIE, '');
        $descrizione = self::descrizione((string) $request->userAgent());
        $indirizzo = $request->ip();

        if ($gettone !== '') {
            $conosciuto = DispositivoConosciuto::where('user_id', $user->id)
                ->where('impronta', hash('sha256', $gettone))
                ->first();

            if ($conosciuto) {
                $conosciuto->forceFill([
                    'ultima_volta' => now(),
                    'ultimo_indirizzo' => $indirizzo,
                    'descrizione' => $descrizione,
                ])->save();

                return;
            }
        }

        $haGiaDispositivi = DispositivoConosciuto::where('user_id', $user->id)->exists();

        // Il browser che porta già un gettone (di un altro utente dello stesso computer) lo tiene: il
        // dispositivo diventa conosciuto anche per questo utente, e l'altro non lo perde.
        if ($gettone === '') {
            $gettone = Str::random(48);
        }

        DispositivoConosciuto::create([
            'user_id' => $user->id,
            'impronta' => hash('sha256', $gettone),
            'descrizione' => $descrizione,
            'ultimo_indirizzo' => $indirizzo,
            'prima_volta' => now(),
            'ultima_volta' => now(),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $gettone, self::DURATA_COOKIE, null, null, null, true, false, 'lax'));

        if ($haGiaDispositivi) {
            app(AvvisiDiSicurezza::class)->accessoDaDispositivoNuovo($user, $descrizione, self::indirizzoApprossimato($indirizzo));
        }
    }

    /** «Chrome su Android», «Safari su iPhone»: il browser e il sistema, letti dallo user agent. */
    public static function descrizione(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'CriOS/') || str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $sistema = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $sistema !== null => __('notifications.security_alert.dispositivo', ['browser' => $browser, 'sistema' => $sistema]),
            $browser !== null => $browser,
            $sistema !== null => $sistema,
            default => __('notifications.security_alert.dispositivo_sconosciuto'),
        };
    }

    /** L'indirizzo senza la sua parte finale: 93.45.120.7 → 93.45.x.x. Basta a riconoscere la rete, non la persona. */
    public static function indirizzoApprossimato(?string $indirizzo): ?string
    {
        if ($indirizzo === null || $indirizzo === '') {
            return null;
        }

        if (filter_var($indirizzo, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parti = explode('.', $indirizzo);

            return $parti[0].'.'.$parti[1].'.x.x';
        }

        if (filter_var($indirizzo, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parti = explode(':', (string) inet_ntop((string) inet_pton($indirizzo)));

            return implode(':', array_slice($parti, 0, 3)).'::';
        }

        return null;
    }
}

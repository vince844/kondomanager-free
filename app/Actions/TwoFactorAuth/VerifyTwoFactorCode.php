<?php

namespace App\Actions\TwoFactorAuth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

/**
 * Il codice a sei cifre dell'app di autenticazione, alla sfida del login e alla conferma nelle
 * impostazioni (Coda 259).
 *
 * Due garanzie:
 *
 * 1. **La finestra è di ±30 secondi**: il codice di adesso, quello di prima e quello dopo (una finestra
 *    di 1 per la libreria, che conta periodi di 30 secondi; è anche il suo valore di serie): tre codici
 *    validi alla volta, non di più. Chi ha l'orologio del telefono sballato di più ripiega sui codici di
 *    recupero.
 * 2. **Un codice già usato non vale una seconda volta**, e nemmeno uno più vecchio: l'ultimo periodo
 *    usato si ricorda per utente (`verifyKeyNewer`). Nella cache e non nel database, per non toccare lo
 *    schema: un codice vale al massimo un minuto e mezzo, quindi perdere il ricordo con uno svuotamento
 *    della cache riapre al più quella finestra.
 */
class VerifyTwoFactorCode
{
    /** Periodi di 30 secondi accettati prima e dopo quello di adesso. */
    public const FINESTRA = 1;

    /** Quanto si ricorda l'ultimo codice usato: più della finestra intera. */
    private const RICORDO_MINUTI = 5;

    public function __invoke(User $user, string $code): bool
    {
        // Solo le cifre: chi copia il codice con uno spazio in mezzo non deve sbagliare.
        $code = preg_replace('/[^0-9]/', '', $code);

        if ($user->two_factor_secret === null || $code === '') {
            return false;
        }

        $google2fa = new Google2FA();
        $google2fa->setWindow(self::FINESTRA);
        $google2fa->setOneTimePasswordLength(6);

        $chiave = self::chiaveUltimoCodice($user);

        try {
            // Con un ultimo periodo noto (0 se non c'è) la libreria restituisce il periodo del codice
            // trovato, o `false`; e accetta solo periodi successivi a quello.
            $periodo = $google2fa->verifyKeyNewer(decrypt($user->two_factor_secret), $code, (int) Cache::get($chiave, 0), self::FINESTRA);
        } catch (\Exception $e) {
            return false;
        }

        if ($periodo === false) {
            return false;
        }

        Cache::put($chiave, (int) $periodo, now()->addMinutes(self::RICORDO_MINUTI));

        return true;
    }

    public static function chiaveUltimoCodice(User $user): string
    {
        return '2fa-ultimo-codice|'.$user->getKey();
    }
}

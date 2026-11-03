<?php

namespace App\Services\Notifiche;

use App\Models\User;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Gli avvisi di sicurezza al titolare di un account (Coda 267, decisione 126.1).
 *
 * Partono dalle azioni che cambiano la sicurezza di un account: la password cambiata dal profilo o
 * reimpostata dal link «password dimenticata», l'email cambiata (dal profilo o dalla scheda utenti),
 * l'autenticazione a due fattori attivata o disattivata, i codici di recupero rigenerati. Non dalla
 * prima password scelta dal link dell'invito o del primo accesso: prima non c'era niente da proteggere.
 *
 * Tre garanzie, tutte qui per non doverle ripetere in ogni controller:
 *
 * 1. **Un invio che fallisce non ferma l'azione.** La password è già cambiata quando l'avviso parte:
 *    rispondere con un errore direbbe il falso. L'errore va nel registro.
 * 2. **Le preferenze di notifica non li spengono.** Le preferenze filtrano i destinatari degli avvisi
 *    del portale (`FilterByNotificationPreference`); qui il destinatario è sempre il titolare, scelto
 *    senza passare di lì.
 * 3. **L'email cambiata si avvisa al vecchio indirizzo**, l'unico che il titolare legge ancora se il
 *    cambio non l'ha fatto lui, con il nuovo indirizzo mascherato: se il vecchio era sbagliato (un
 *    refuso corretto da un collaboratore), chi lo legge non impara l'indirizzo vero.
 */
class AvvisiDiSicurezza
{
    public const PASSWORD_CAMBIATA = 'password_cambiata';

    public const PASSWORD_REIMPOSTATA = 'password_reimpostata';

    public const EMAIL_CAMBIATA = 'email_cambiata';

    public const DUE_FATTORI_ATTIVATA = 'due_fattori_attivata';

    public const DUE_FATTORI_DISATTIVATA = 'due_fattori_disattivata';

    public const CODICI_RIGENERATI = 'codici_rigenerati';

    /**
     * Avvisa il titolare al suo indirizzo di adesso.
     *
     * @param  User|null  $autore  Chi ha fatto la modifica; nell'avviso solo se non è il titolare.
     */
    public function avvisa(User $titolare, string $evento, ?User $autore = null): void
    {
        $avviso = new AvvisoDiSicurezzaNotification($evento, (string) $titolare->name, $this->nomeDi($autore, $titolare));

        $this->spedisci(fn () => $titolare->notify($avviso), $titolare, $evento);
    }

    /**
     * Avvisa il vecchio indirizzo che l'email è cambiata. `$titolare` ha già quella nuova.
     */
    public function emailCambiata(User $titolare, string $vecchia, ?User $autore = null): void
    {
        $avviso = new AvvisoDiSicurezzaNotification(
            self::EMAIL_CAMBIATA,
            (string) $titolare->name,
            $this->nomeDi($autore, $titolare),
            self::mascheraEmail((string) $titolare->email),
        );

        $this->spedisci(fn () => Notification::route('mail', $vecchia)->notify($avviso), $titolare, self::EMAIL_CAMBIATA);
    }

    /**
     * «maria.rossi@example.com» diventa «m***@example.com»: il titolare riconosce il suo indirizzo, chi
     * non lo conosce non lo impara.
     */
    public static function mascheraEmail(string $email): string
    {
        $chiocciola = strrpos($email, '@');

        if ($chiocciola === false || $chiocciola === 0) {
            return '***';
        }

        return Str::substr($email, 0, 1).'***'.substr($email, $chiocciola);
    }

    private function nomeDi(?User $autore, User $titolare): ?string
    {
        return $autore && ! $autore->is($titolare) ? (string) $autore->name : null;
    }

    private function spedisci(\Closure $invio, User $titolare, string $evento): void
    {
        try {
            $invio();
        } catch (\Throwable $e) {
            Log::error("Avviso di sicurezza «{$evento}» non spedito all'utente {$titolare->id}: {$e->getMessage()}");
        }
    }
}
